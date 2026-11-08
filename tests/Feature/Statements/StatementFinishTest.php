<?php

use App\Ai\Agents\StatementLinesAgent;
use App\Enums\StatementLineStatus;
use App\Enums\StatementParseStatus;
use App\Enums\StatementReadOutcome;
use App\Jobs\ParseStatementWithAi;
use App\Models\Account;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorySuggestionService;
use App\Services\Statements\GenericCsvParser;
use App\Services\Statements\Normalizer;
use App\Services\Statements\PdfLineParser;
use App\Services\Statements\PdfTextExtractor;
use App\Services\Statements\StatementImporter;
use App\Services\Statements\StatementMatcher;
use App\Services\Statements\StatementSearch;
use App\Services\Statements\StatementSearchIndex;
use App\Services\Statements\StatementService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;

function statementsFinishPdfUpload(User $user, ?string $text, string $name = 'finish.pdf'): void
{
    statementsFakePdfText($text);

    actingAs($user)->post(route('statements.store'), [
        'files' => [UploadedFile::fake()->createWithContent($name, '%PDF-1.4 '.$name.uniqid())],
    ])->assertSessionHasNoErrors();
}

function statementsFinishUsText(): string
{
    return implode("\n", [
        'Date        Description                              Amount       Balance',
        '03/04/2024  AMAZON MARKETPLACE                      -1,234.56    5,000.00',
        '03/15/2024  PAYROLL ACME INC                         2,500.00    7,500.00',
    ]);
}

function statementsFinishItalianText(): string
{
    return implode("\n", [
        'Data       Valuta     Descrizione                           Importo      Saldo',
        '03/04/2024 03/04/2024 PAGAMENTO POS FARMACIA                -23,40      100,00',
        '15/04/2024 15/04/2024 STIPENDIO ACME                      1.850,00    1.950,00',
    ]);
}

function statementsFinishAmbiguousText(): string
{
    return implode("\n", [
        '03/04/2024 03/04/2024 PAGAMENTO POS FARMACIA                -23,40',
        '05/04/2024 05/04/2024 ACCREDITO RIMBORSO                    10,00',
    ]);
}

function statementsFinishColumnsText(): string
{
    return implode("\n", [
        'Data       Valuta     Descrizione                           Dare         Avere        Saldo',
        '01/03/2024 01/03/2024 PAGAMENTO POS SUPERMERCATO ROSSI       45,30                      954,70',
        '05/03/2024 05/03/2024 ACCREDITO STIPENDIO ACME SRL                      1.850,00      2.804,70',
        '08/03/2024 08/03/2024 COMMISSIONI                         -1,00',
        '09/03/2024 09/03/2024 MISTERO                                                           9,99',
    ]);
}

function statementsFinishAmountColumnText(): string
{
    return implode("\n", [
        'Data       Descrizione                      Importo',
        '05/03/2024 PAGAMENTO POS COOP                 45,30',
        '06/03/2024 PAGAMENTO POS ENEL                 80,00',
        '07/03/2024 COMMISSIONE                       -10,00',
        '08/03/2024 RIMBORSO                           15,00 CR',
    ]);
}

/**
 * @return array{0: User, 1: Account, 2: Statement}
 */
function statementsFinishUnsignedStatement(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);

    statementsFinishPdfUpload($user, statementsFinishAmountColumnText());

    return [$user, $account, $user->statements()->firstOrFail()];
}

/**
 * @return array{0: User, 1: Statement}
 */
function statementsFinishPendingStatement(): array
{
    Queue::fake();
    statementsEnableAi();

    $user = User::factory()->create();
    statementsFinishPdfUpload($user, statementsProseText());

    return [$user, $user->statements()->firstOrFail()];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function statementsFinishLine(Statement $statement, float $amount, array $attributes = []): StatementLine
{
    return StatementLine::factory()->create([
        'statement_id' => $statement->id,
        'user_id' => $statement->user_id,
        'posted_on' => '2024-03-10',
        'amount' => $amount,
        'currency' => 'EUR',
        'description' => 'Coffee beans',
        ...$attributes,
    ]);
}

beforeEach(fn () => Storage::fake('local'));

test('the text kept per statement is capped and flagged', function () {
    $user = User::factory()->create();
    $text = str_repeat('ab ', intdiv(StatementService::MAX_TEXT_CHARACTERS, 3) + 1);

    expect(strlen($text))->toBeGreaterThan(StatementService::MAX_TEXT_CHARACTERS);

    statementsFinishPdfUpload($user, $text);

    $statement = $user->statements()->firstOrFail();

    expect(strlen((string) $statement->extracted_text))->toBe(StatementService::MAX_TEXT_CHARACTERS)
        ->and($statement->text_truncated)->toBeTrue();

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page->where('statement.text_truncated', true));
});

test('a text within the cap is stored whole', function () {
    $user = User::factory()->create();

    statementsFinishPdfUpload($user, str_repeat('ab ', 100));

    $statement = $user->statements()->firstOrFail();

    expect(strlen((string) $statement->extracted_text))->toBe(300)
        ->and($statement->text_truncated)->toBeFalse();
});

test('US dates are read month-first and Italian dates day-first, from the whole statement', function () {
    $us = app(PdfLineParser::class)->parse(statementsFinishUsText());
    $italian = app(PdfLineParser::class)->parse(statementsFinishItalianText());

    expect($us->dateOrderAssumed)->toBeFalse()
        ->and(array_map(fn ($line) => [$line->date, $line->amount], $us->lines))->toBe([
            ['2024-03-04', '-1234.56'],
            ['2024-03-15', '2500.00'],
        ])
        ->and($italian->dateOrderAssumed)->toBeFalse()
        ->and(array_map(fn ($line) => [$line->date, $line->amount], $italian->lines))->toBe([
            ['2024-04-03', '-23.40'],
            ['2024-04-15', '1850.00'],
        ]);
});

test('dates that cannot tell their order default to day-first and are flagged on the statement', function () {
    $user = User::factory()->create();

    statementsFinishPdfUpload($user, statementsFinishAmbiguousText());

    $statement = $user->statements()->firstOrFail();

    expect($statement->date_order_assumed)->toBeTrue()
        ->and($statement->lines()->orderBy('posted_on')->get()->map(fn (StatementLine $line) => $line->posted_on->toDateString())->all())->toBe(['2024-04-03', '2024-04-05']);

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page->where('statement.date_order_assumed', true));
});

test('a statement whose dates prove their order is not flagged', function () {
    $user = User::factory()->create();

    statementsFinishPdfUpload($user, statementsFinishUsText());

    expect($user->statements()->firstOrFail()->date_order_assumed)->toBeFalse();
});

test('an unsigned amount takes its sign from the debit or credit column it sits in', function () {
    $lines = app(PdfLineParser::class)->parse(statementsFinishColumnsText())->lines;

    expect(array_map(fn ($line) => [$line->description, $line->amount, $line->signUncertain], $lines))->toBe([
        ['PAGAMENTO POS SUPERMERCATO ROSSI', '-45.30', false],
        ['ACCREDITO STIPENDIO ACME SRL', '1850.00', false],
        ['COMMISSIONI', '-1.00', false],
        ['MISTERO', '9.99', true],
    ]);
});

test('debit and credit columns are found in English and Romanian headers, aligned to either edge', function () {
    $english = app(PdfLineParser::class)->parse(implode("\n", [
        'Date        Description                       Debit      Credit      Balance',
        '03/15/2024  COFFEE                              4.50                 995.50',
        '03/16/2024  REFUND                                         10.00   1,005.50',
    ]));
    $romanian = app(PdfLineParser::class)->parse(implode("\n", [
        'Data       Descriere                              Ieșiri       Intrări      Sold',
        '01.03.2024 PLATA POS MEGA IMAGE                  45,30                    954,70',
        '05.03.2024 SALARIU                                          1.850,00    2.804,70',
    ]));

    expect(array_map(fn ($line) => $line->amount, $english->lines))->toBe(['-4.50', '10.00'])
        ->and(array_map(fn ($line) => $line->amount, $romanian->lines))->toBe(['-45.30', '1850.00']);
});

test('without debit and credit columns an amount with no sign of its own is flagged, one with a sign is not', function () {
    $lines = app(PdfLineParser::class)->parse(statementsFinishAmountColumnText())->lines;

    expect(array_map(fn ($line) => [$line->amount, $line->signUncertain], $lines))->toBe([
        ['45.30', true],
        ['80.00', true],
        ['-10.00', false],
        ['15.00', false],
    ]);
});

test('the show page lists the lines whose sign is uncertain', function () {
    $user = User::factory()->create();

    statementsFinishPdfUpload($user, statementsFinishColumnsText());

    $statement = $user->statements()->firstOrFail();

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page
            ->where('statement.sign_uncertain_count', 1)
            ->where('lines.data.3.description', 'MISTERO')
            ->where('lines.data.3.sign_uncertain', true)
            ->where('lines.data.0.sign_uncertain', false));
});

test('the owner can flip the sign of a line, which clears the doubt and keeps the search index in step', function () {
    $user = User::factory()->create();
    $statement = statementsArchive($user, 'Coffee beans invoice', [['2024-03-10', 'Coffee beans', 9.99]]);
    $line = $statement->lines()->firstOrFail();
    $line->update(['sign_uncertain' => true]);

    actingAs($user)->post(route('statements.flip-line-sign', [$statement, $line]))->assertRedirect();

    expect($line->refresh()->amount)->toBe('-9.99')
        ->and($line->sign_uncertain)->toBeFalse();

    actingAs($user)->post(route('statements.flip-line-sign', [$statement, $line]));

    expect($line->refresh()->amount)->toBe('9.99');

    $results = actingAs($user)->get(route('statements.index', ['q' => 'beans']))->viewData('page')['props']['search']['results'];

    expect($results)->toHaveCount(1)
        ->and($results[0]['id'])->toBe($statement->id);
});

test('only lines that are not imported or ignored, and not zero, can change sign', function (StatementLineStatus $status, float $amount, bool $flips) {
    $user = User::factory()->create();
    $statement = Statement::factory()->create(['user_id' => $user->id]);
    $line = statementsFinishLine($statement, $amount, ['match_status' => $status]);

    actingAs($user)->post(route('statements.flip-line-sign', [$statement, $line]))->assertRedirect();

    expect((float) $line->refresh()->amount)->toBe($flips ? -$amount : $amount);
})->with([
    'unmatched' => [StatementLineStatus::Unmatched, 12.5, true],
    'matched' => [StatementLineStatus::Matched, 12.5, true],
    'imported' => [StatementLineStatus::Imported, 12.5, false],
    'ignored' => [StatementLineStatus::Ignored, 12.5, false],
    'zero' => [StatementLineStatus::Unmatched, 0.0, false],
]);

test('another user cannot flip a line, and a line of another statement is not found', function () {
    $user = User::factory()->create();
    $statement = Statement::factory()->create(['user_id' => $user->id]);
    $other = Statement::factory()->create(['user_id' => $user->id]);
    $line = statementsFinishLine($statement, 5);

    actingAs(User::factory()->create())->post(route('statements.flip-line-sign', [$statement, $line]))->assertForbidden();
    actingAs($user)->post(route('statements.flip-line-sign', [$other, $line]))->assertNotFound();

    expect((float) $line->refresh()->amount)->toBe(5.0);
});

test('guests cannot flip a line', function () {
    $statement = Statement::factory()->create();
    $line = statementsFinishLine($statement, 5);

    $this->post(route('statements.flip-line-sign', [$statement, $line]))->assertRedirect(route('login'));
});

test('flipping a matched line works its match out again', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $statement = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
    $line = statementsFinishLine($statement, 20, ['match_status' => StatementLineStatus::Matched]);

    actingAs($user)->post(route('statements.flip-line-sign', [$statement, $line]))->assertRedirect();

    expect($line->refresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and((float) $line->amount)->toBe(-20.0);
});

test('when the parsers find nothing and AI is on, the statement is kept and the AI read is queued', function () {
    [$user, $statement] = statementsFinishPendingStatement();

    expect($statement->parse_status)->toBe(StatementParseStatus::PendingAi)
        ->and($statement->parser)->toBe('none')
        ->and($statement->lines()->count())->toBe(0)
        ->and($statement->extracted_text)->toContain('FARMACIA CENTRALE');

    Queue::assertPushed(ParseStatementWithAi::class, fn (ParseStatementWithAi $job) => $job->statementId === $statement->id);
    Queue::assertPushed(ParseStatementWithAi::class, 1);

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page->where('statement.parse_status', 'pending_ai'));
    actingAs($user)->get(route('statements.index'))
        ->assertInertia(fn ($page) => $page->where('statements.data.0.parse_status', 'pending_ai'));
});

// One upload per case: the statements controller (and the PDF extractor it holds) is reused by
// every request of a test, so a second upload would still read the first upload's fake text.
test('nothing is queued when AI is off or the deterministic parsers read the lines', function (bool $aiOn, string $text) {
    Queue::fake();

    if ($aiOn) {
        statementsEnableAi();
    }

    $user = User::factory()->create();

    statementsFinishPdfUpload($user, $text === 'prose' ? statementsProseText() : statementsFinishUsText());

    Queue::assertNothingPushed();

    expect($user->statements()->firstOrFail()->parse_status)->toBe(StatementParseStatus::Done);
})->with([
    'AI off, nothing readable' => [false, 'prose'],
    'AI off, readable' => [false, 'us'],
    'AI on, readable' => [true, 'us'],
]);

test('the queued job reads the text with the model and replaces the lines', function () {
    [, $statement] = statementsFinishPendingStatement();

    StatementLinesAgent::fake([[
        'lines' => [
            ['date' => '2024-03-04', 'description' => 'FARMACIA CENTRALE', 'amount' => -23.40],
            ['date' => '2024-03-05', 'description' => 'Invented amount', 'amount' => 999.99],
        ],
    ]]);

    (new ParseStatementWithAi($statement->id))->handle(app(StatementService::class));

    $statement->refresh();

    expect($statement->parse_status)->toBe(StatementParseStatus::Done)
        ->and($statement->parser)->toBe('ai')
        ->and($statement->lines()->pluck('description')->all())->toBe(['FARMACIA CENTRALE'])
        ->and($statement->period_start->toDateString())->toBe('2024-03-04');

    StatementLinesAgent::assertPromptedTimes(1);
});

test('a model that reads nothing leaves the statement marked as not read by the AI', function () {
    [, $statement] = statementsFinishPendingStatement();

    StatementLinesAgent::fake([['lines' => []]]);

    (new ParseStatementWithAi($statement->id))->handle(app(StatementService::class));

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::AiFailed)
        ->and($statement->lines()->count())->toBe(0)
        ->and($statement->extracted_text)->not->toBeNull();
});

test('a failed job shows as failed, but never overwrites a statement that was read', function () {
    [, $pending] = statementsFinishPendingStatement();
    $done = Statement::factory()->create(['parse_status' => StatementParseStatus::Done]);

    (new ParseStatementWithAi($pending->id))->failed(new RuntimeException('boom'));
    (new ParseStatementWithAi($done->id))->failed(new RuntimeException('boom'));

    expect($pending->refresh()->parse_status)->toBe(StatementParseStatus::AiFailed)
        ->and($done->refresh()->parse_status)->toBe(StatementParseStatus::Done);
});

test('the job does nothing for a deleted statement or one that is not waiting', function () {
    $done = Statement::factory()->create(['parse_status' => StatementParseStatus::Done]);
    StatementLinesAgent::fake();

    (new ParseStatementWithAi($done->id))->handle(app(StatementService::class));
    (new ParseStatementWithAi(0))->handle(app(StatementService::class));

    StatementLinesAgent::assertNeverPrompted();
});

test('the job is one try, ten minutes, and unique per statement', function () {
    $job = new ParseStatementWithAi(7);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(600)
        ->and($job->uniqueId())->toBe('7')
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

test('a statement waiting for the AI can be read again without a second job, and its lines imported', function () {
    [$user, $statement] = statementsFinishPendingStatement();

    actingAs($user)->post(route('statements.reparse', $statement))->assertSessionHasNoErrors();

    // The job is unique per statement: the one already waiting will read the new text.
    Queue::assertPushed(ParseStatementWithAi::class, 1);

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::PendingAi);

    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $line = statementsFinishLine($statement, -9.5);

    actingAs($user)->post(route('statements.import-lines', $statement), [
        'account_id' => $account->id,
        'lines' => [['id' => $line->id]],
    ])->assertSessionHasNoErrors();

    expect($line->refresh()->match_status)->toBe(StatementLineStatus::Imported);
});

test('reading again after a failed AI read tries the model once more', function () {
    [$user, $statement] = statementsFinishPendingStatement();
    $statement->update(['parse_status' => StatementParseStatus::AiFailed]);

    // A failed job releases its unique lock; the fake queue never ran it, so release it here
    // (the array store keeps locks apart from its values, so Cache::flush() would not do it).
    (new UniqueLock(Cache::driver()))->release(new ParseStatementWithAi($statement->id));

    actingAs($user)->post(route('statements.reparse', $statement));

    Queue::assertPushed(ParseStatementWithAi::class, 2);

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::PendingAi);
});

test('unsigned lines are not imported until the signs are confirmed, then they are income', function () {
    [$user, $account, $statement] = statementsFinishUnsignedStatement();

    expect($statement->lines()->where('sign_uncertain', true)->count())->toBe(2);

    $payload = ['account_id' => $account->id, 'import_all' => true, 'lines' => []];

    actingAs($user)->post(route('statements.import-lines', $statement), $payload);

    expect(Transaction::query()->where('type', 'income')->count())->toBe(1);

    actingAs($user)->post(route('statements.confirm-all-signs', $statement))->assertRedirect();

    expect($statement->lines()->where('sign_uncertain', true)->count())->toBe(0)
        ->and($statement->lines()->where('sign_reviewed', true)->count())->toBe(2);

    actingAs($user)->post(route('statements.import-lines', $statement), $payload);

    expect(Transaction::query()->where('type', 'income')->count())->toBe(3)
        ->and(Transaction::query()->where('type', 'expense')->count())->toBe(1);
});

test('flipping all uncertain signs makes them expenses and leaves the signed lines alone', function () {
    [$user, $account, $statement] = statementsFinishUnsignedStatement();

    actingAs($user)->post(route('statements.flip-all-signs', $statement))->assertRedirect();

    expect($statement->lines()->orderBy('posted_on')->get()->map(fn (StatementLine $line) => (float) $line->amount)->all())->toBe([-45.3, -80.0, -10.0, 15.0]);

    actingAs($user)->post(route('statements.import-lines', $statement), ['account_id' => $account->id, 'import_all' => true, 'lines' => []]);

    expect(Transaction::query()->where('type', 'expense')->count())->toBe(3)
        ->and(Transaction::query()->where('type', 'income')->count())->toBe(1);
});

test('the sign actions of a whole statement belong to its owner and skip decided lines', function () {
    [$user, , $statement] = statementsFinishUnsignedStatement();

    actingAs(User::factory()->create())->post(route('statements.confirm-all-signs', $statement))->assertForbidden();
    actingAs(User::factory()->create())->post(route('statements.flip-all-signs', $statement))->assertForbidden();

    $ignored = $statement->lines()->where('sign_uncertain', true)->firstOrFail();
    $ignored->update(['match_status' => StatementLineStatus::Ignored]);

    actingAs($user)->post(route('statements.flip-all-signs', $statement));

    expect((float) $ignored->refresh()->amount)->toBe(45.3)
        ->and($statement->lines()->where('sign_uncertain', true)->count())->toBe(1);
});

test('reading again is refused once a sign was checked, and nothing is lost', function () {
    [$user, , $statement] = statementsFinishUnsignedStatement();

    actingAs($user)->post(route('statements.flip-all-signs', $statement));
    actingAs($user)->get(route('statements.show', $statement))->assertInertia(fn ($page) => $page->where('statement.can_reparse', false));
    actingAs($user)->post(route('statements.reparse', $statement));

    expect($statement->lines()->where('sign_reviewed', true)->count())->toBe(2)
        ->and((float) $statement->lines()->orderBy('posted_on')->firstOrFail()->amount)->toBe(-45.3);
});

test('a decided line that appears while the file is read again stops the replacement', function () {
    [, , $statement] = statementsFinishUnsignedStatement();
    $statement->lines()->firstOrFail()->update(['match_status' => StatementLineStatus::Imported]);

    expect(app(StatementService::class)->process($statement))->toBe(StatementReadOutcome::Refused)
        ->and($statement->lines()->count())->toBe(4)
        ->and(app(StatementService::class)->reprocess($statement))->toBe(StatementReadOutcome::Refused);
});

test('money in does not match an expense of the same size, nor money out an income', function () {
    [, $account, $statement] = statementsMatchSetup();
    $refund = statementsLine($statement, '2024-03-10', 10.00, 'Refund');
    statementsTransaction($account, '2024-03-10', 10.00);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 0, 'unmatched' => 1])
        ->and($refund->fresh()->match_status)->toBe(StatementLineStatus::Unmatched)
        ->and(app(StatementMatcher::class)->candidates($statement))->toBe([]);

    $payment = statementsLine($statement, '2024-03-20', -25.00);
    statementsTransaction($account, '2024-03-20', 25.00, ['type' => 'income']);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 0, 'unmatched' => 2])
        ->and($payment->fresh()->matched_transaction_id)->toBeNull();
});

test('a transfer matches money out on its source and money in on its destination only', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id]);
    $to = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->transfer($to, 100)->create([
        'user_id' => $user->id, 'account_id' => $from->id, 'amount' => 100, 'date' => '2024-03-10', 'description' => null,
    ]);

    $wrongOut = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $from->id]);
    $wrongIn = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $to->id]);
    statementsLine($wrongOut, '2024-03-10', 100.00);
    statementsLine($wrongIn, '2024-03-10', -100.00);

    $matcher = app(StatementMatcher::class);

    expect($matcher->match($wrongOut))->toBe(['matched' => 0, 'unmatched' => 1])
        ->and($matcher->match($wrongIn))->toBe(['matched' => 0, 'unmatched' => 1]);
});

test('matching skips a line that was imported or ignored since it was loaded', function () {
    [, $account, $statement] = statementsMatchSetup();
    $line = statementsLine($statement, '2024-03-10', -12.50);
    statementsTransaction($account, '2024-03-10', 12.50);

    $listener = 'eloquent.retrieved: '.Transaction::class;
    Event::listen($listener, fn () => StatementLine::query()->whereKey($line->id)->update(['match_status' => StatementLineStatus::Ignored->value]));

    try {
        app(StatementMatcher::class)->match($statement);
    } finally {
        Event::forget($listener);
    }

    expect($line->fresh()->match_status)->toBe(StatementLineStatus::Ignored)
        ->and($line->fresh()->matched_transaction_id)->toBeNull();
});

test('candidates for the lines of a page are the same as for the whole statement', function () {
    [, $account, $statement] = statementsMatchSetup();
    $first = statementsLine($statement, '2024-03-10', -9.99, 'A');
    $second = statementsLine($statement, '2024-03-12', -4.00, 'B');
    statementsTransaction($account, '2024-03-10', 9.99);
    statementsTransaction($account, '2024-03-10', 9.99);
    statementsTransaction($account, '2024-03-12', 4.00);
    statementsTransaction($account, '2024-03-12', 4.00);

    $matcher = app(StatementMatcher::class);
    $all = $matcher->candidates($statement);
    $page = $matcher->candidates($statement, [$second->id]);

    $ids = fn (array $candidates) => array_map(fn (array $transactions) => collect($transactions)->pluck('id')->sort()->values()->all(), $candidates);

    expect(array_keys($all))->toBe([$first->id, $second->id])
        ->and($ids($page))->toBe([$second->id => $ids($all)[$second->id]]);
});

test('when the model fails on a later chunk, the lines read so far are kept but marked as partial', function () {
    statementsEnableAi();
    Queue::fake();

    $user = User::factory()->create();
    $filler = implode("\n", array_map(fn (int $i) => "Nota informativa numero {$i} senza alcun importo da riportare qui", range(1, 200)));

    statementsFinishPdfUpload($user, statementsProseText()."\n".$filler);

    $statement = $user->statements()->firstOrFail();
    $calls = 0;

    StatementLinesAgent::fake(function () use (&$calls) {
        if (++$calls > 1) {
            throw new RuntimeException('model down');
        }

        return ['lines' => [['date' => '2024-03-04', 'description' => 'FARMACIA CENTRALE', 'amount' => -23.40]]];
    });

    (new ParseStatementWithAi($statement->id))->handle(app(StatementService::class));

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::AiPartial)
        ->and($statement->lines()->count())->toBe(1);

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page->where('statement.parse_status', 'ai_partial'));
});

test('an AI amount whose sign contradicts the text is flagged as uncertain', function () {
    statementsEnableAi();
    Queue::fake();

    $user = User::factory()->create();

    statementsFinishPdfUpload($user, "Estratto conto\nPagamento carta presso FARMACIA CENTRALE del 4 marzo 2024 importo -23,40 euro\nAccredito del 9 marzo 2024 di 150,00 euro");

    $statement = $user->statements()->firstOrFail();

    StatementLinesAgent::fake([[
        'lines' => [
            ['date' => '2024-03-04', 'description' => 'FARMACIA CENTRALE', 'amount' => 23.40],
            ['date' => '2024-03-09', 'description' => 'Accredito', 'amount' => 150],
        ],
    ]]);

    (new ParseStatementWithAi($statement->id))->handle(app(StatementService::class));

    expect($statement->lines()->orderBy('posted_on')->get()->map(fn (StatementLine $line) => $line->sign_uncertain)->all())->toBe([true, false]);
});

dataset('statementsFinishMinuses', [
    'minus sign' => "\u{2212}",
    'en dash' => "\u{2013}",
    'em dash' => "\u{2014}",
    'small hyphen-minus' => "\u{FE63}",
    'fullwidth hyphen-minus' => "\u{FF0D}",
]);

test('every kind of minus sign makes an amount negative, in numbers, CSV and PDF lines', function (string $minus) {
    $csv = app(GenericCsvParser::class)->parse("Date,Description,Amount\n2024-03-02,Coffee,{$minus}3.50\n");
    $pdf = app(PdfLineParser::class)->parse("01/03/2024  PAGAMENTO POS COOP        {$minus}45,30\n");

    expect(Normalizer::amount("{$minus}12,50"))->toBe('-12.50')
        ->and($csv->lines[0]->amount)->toBe('-3.50')
        ->and($pdf->lines[0]->amount)->toBe('-45.30')
        ->and($pdf->lines[0]->signUncertain)->toBeFalse();
})->with('statementsFinishMinuses');

test('an AI amount is checked against a minus sign of any kind in the text', function (string $minus) {
    statementsEnableAi();
    Queue::fake();

    $user = User::factory()->create();

    statementsFinishPdfUpload($user, "Estratto conto\nPagamento carta presso FARMACIA CENTRALE del 4 marzo 2024 importo {$minus}23,40 euro");

    $statement = $user->statements()->firstOrFail();

    StatementLinesAgent::fake([['lines' => [['date' => '2024-03-04', 'description' => 'FARMACIA CENTRALE', 'amount' => 23.40]]]]);

    (new ParseStatementWithAi($statement->id))->handle(app(StatementService::class));

    expect($statement->lines()->firstOrFail()->sign_uncertain)->toBeTrue();
})->with('statementsFinishMinuses');

test('a CSV whose dates cannot tell their order is flagged like a PDF', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), [
        'files' => [UploadedFile::fake()->createWithContent('ambiguous.csv', "Date,Description,Amount\n03/04/2024,Coffee,-1.00\n05/04/2024,Tea,-2.00\n")],
    ])->assertSessionHasNoErrors();

    $statement = $user->statements()->firstOrFail();

    expect($statement->date_order_assumed)->toBeTrue();

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page->where('statement.date_order_assumed', true));
});

test('a statement overlapping an imported one matches the same transactions and duplicates nothing', function () {
    [$user, $account, $january] = statementImportSetup();
    $line = statementImportLine($january, -20.00, ['posted_on' => '2024-01-10']);

    actingAs($user)->post(route('statements.import-lines', $january), statementImportPayload([$line], ['account_id' => $account->id]));

    $quarter = Statement::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
    $again = statementImportLine($quarter, -20.00, ['posted_on' => '2024-01-10']);
    statementImportLine($quarter, -30.00, ['posted_on' => '2024-02-05']);

    app(StatementMatcher::class)->match($quarter);

    expect($again->refresh()->match_status)->toBe(StatementLineStatus::Matched)
        ->and($again->matched_transaction_id)->toBe($line->refresh()->matched_transaction_id);

    actingAs($user)->post(route('statements.import-lines', $quarter), ['account_id' => $account->id, 'import_all' => true, 'lines' => []]);

    expect(Transaction::query()->count())->toBe(2);
});

dataset('statementsFinishBackends', ['fts' => [false], 'like' => [true]]);

test('two identical lines against two identical transactions stay ambiguous and are never linked to one transaction', function () {
    [, $account, $statement] = statementsMatchSetup();
    $first = statementsLine($statement, '2024-03-10', -5.00);
    $second = statementsLine($statement, '2024-03-10', -5.00);
    statementsTransaction($account, '2024-03-10', 5.00);
    statementsTransaction($account, '2024-03-10', 5.00);

    expect(app(StatementMatcher::class)->match($statement))->toBe(['matched' => 0, 'unmatched' => 2])
        ->and($first->fresh()->matched_transaction_id)->toBeNull()
        ->and($second->fresh()->matched_transaction_id)->toBeNull();
});

test('a line that several transactions fit is refused unless it is imported on its own with an explicit force', function () {
    [$user, $account, $statement] = statementImportSetup();
    statementImportTransaction($account, '2024-03-10', 9.99, ['description' => 'Lunch']);
    statementImportTransaction($account, '2024-03-10', 9.99, ['description' => 'Book']);
    $line = statementImportLine($statement, -9.99, ['posted_on' => '2024-03-10', 'description' => 'CARD']);

    actingAs($user)->post(route('statements.import-lines', $statement), ['account_id' => $account->id, 'import_all' => true, 'lines' => []]);
    actingAs($user)->post(route('statements.import-lines', $statement), statementImportPayload([$line]));

    expect(Transaction::query()->count())->toBe(2)
        ->and($line->refresh()->match_status)->toBe(StatementLineStatus::Unmatched);

    actingAs($user)->post(route('statements.import-lines', $statement), ['lines' => [['id' => $line->id, 'force' => true]]]);

    expect(Transaction::query()->count())->toBe(3)
        ->and($line->refresh()->match_status)->toBe(StatementLineStatus::Imported);
});

test('one import takes at most the cap and says how many remain', function () {
    [$user, $account, $statement] = statementImportSetup();
    $now = now();

    StatementLine::query()->insert(array_map(fn (int $i) => [
        'statement_id' => $statement->id, 'user_id' => $user->id, 'posted_on' => '2024-03-10', 'amount' => -1.00 - $i,
        'currency' => 'USD', 'description' => "Line {$i}", 'parsed_by' => 'csv', 'match_status' => 'unmatched',
        'created_at' => $now, 'updated_at' => $now,
    ], range(1, StatementImporter::MAX_LINES_PER_IMPORT + 5)));

    $result = app(StatementImporter::class)->import($user, $statement, $account, [], true);

    expect($result['remaining'])->toBe(5)
        ->and($result['imported'])->toBe(0)
        ->and(count($result['refused']))->toBe(StatementImporter::MAX_LINES_PER_IMPORT);
});

test('a category is looked up once per description in one request', function () {
    [$user, , $statement] = statementImportSetup();

    $this->partialMock(CategorySuggestionService::class, fn (MockInterface $mock) => $mock->shouldReceive('fromHistory')->once()->andReturn(null));

    $lines = [
        statementImportLine($statement, -1.00, ['description' => 'Lidl  Market']),
        statementImportLine($statement, -2.00, ['description' => 'lidl market']),
        statementImportLine($statement, -3.00, ['description' => 'LIDL MARKET ']),
    ];

    expect(app(StatementImporter::class)->suggestCategories($user, $lines))->toBe([]);
});

test('lines are kept when a re-read finds nothing, and when the AI fails, but replaced when it reads more', function () {
    statementsEnableAi();
    Queue::fake();

    $user = User::factory()->create();
    $statement = statementsStoredFor($user);
    $service = app(StatementService::class);

    expect($service->process($statement))->toBe(StatementReadOutcome::Replaced)
        ->and($statement->lines()->count())->toBe(1);

    Storage::disk('local')->put($statement->path, 'Compra del 5 marzo 2024 per 12,00 euro');

    expect($service->reprocess($statement))->toBe(StatementReadOutcome::Kept)
        ->and($statement->refresh()->parse_status)->toBe(StatementParseStatus::PendingAi)
        ->and($statement->lines()->count())->toBe(1);

    StatementLinesAgent::fake([['lines' => []]]);
    (new ParseStatementWithAi($statement->id))->handle($service);

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::AiFailed)
        ->and($statement->lines()->count())->toBe(1);

    (new UniqueLock(Cache::driver()))->release(new ParseStatementWithAi($statement->id));
    $service->reprocess($statement);
    StatementLinesAgent::fake([['lines' => [['date' => '2024-03-05', 'description' => 'Compra', 'amount' => -12.00]]]]);
    (new ParseStatementWithAi($statement->id))->handle($service);

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::Done)
        ->and($statement->lines()->pluck('description')->all())->toBe(['Compra']);
});

test('lines are kept when AI is off and the re-read finds nothing', function () {
    $user = User::factory()->create();
    $statement = statementsStoredFor($user);
    $service = app(StatementService::class);

    $service->process($statement);
    Storage::disk('local')->put($statement->path, 'nothing to read here');

    actingAs($user)->post(route('statements.reparse', $statement))->assertSessionHasNoErrors();

    expect($statement->lines()->count())->toBe(1)
        ->and($statement->refresh()->parser)->toBe('csv');
});

test('a file that fails while being read is still kept, marked as unreadable and can be read again', function () {
    app()->instance(PdfTextExtractor::class, new class extends PdfTextExtractor
    {
        public function extract(string $path): ?string
        {
            throw new RuntimeException('pdftotext crashed');
        }
    });

    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), [
        'files' => [UploadedFile::fake()->createWithContent('crash.pdf', '%PDF-1.4 crash')],
    ])->assertSessionHasNoErrors();

    $statement = $user->statements()->firstOrFail();

    expect($statement->parse_status)->toBe(StatementParseStatus::Failed);

    actingAs($user)->get(route('statements.show', $statement))
        ->assertInertia(fn ($page) => $page->where('statement.parse_status', 'failed'));

    statementsFakePdfText(statementsFinishItalianText());
    actingAs($user)->post(route('statements.reparse', $statement));

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::Done)
        ->and($statement->lines()->count())->toBe(2);
});

test('at most five files are read in one upload', function () {
    $user = User::factory()->create();
    $files = array_map(fn (int $i) => UploadedFile::fake()->createWithContent("s{$i}.csv", "Date,Description,Amount\n2024-03-0{$i},Coffee,-{$i}.00\n"), range(1, 6));

    actingAs($user)->post(route('statements.store'), ['files' => $files])->assertSessionHasErrors('files');

    expect($user->statements()->count())->toBe(0);
});

test('a search excerpt is cut in the database around the first hit, with or without FTS5', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $text = str_repeat('lorem ipsum dolor ', 2000).' sanzione amministrativa '.str_repeat('amet consectetur ', 2000);
    $statement = statementsArchive($user, $text);

    $result = statementsSearchResults($user, ['q' => 'sanzione'])['results'][0];
    $length = array_sum(array_map(fn (array $segment) => mb_strlen($segment['text']), $result['snippet']));

    expect($result['id'])->toBe($statement->id)
        ->and($length)->toBeLessThan(400)
        ->and(collect($result['snippet'])->contains(fn (array $segment) => $segment['hit'] && strtolower($segment['text']) === 'sanzione'))->toBeTrue();
})->with('statementsFinishBackends');

test('line totals are counted in SQL, so no cap hides matches', function (bool $withoutFts) {
    if ($withoutFts) {
        statementsWithoutFts();
    }

    $user = User::factory()->create();
    $big = statementsArchive($user, 'Big file');
    $small = statementsArchive($user, 'Small file', [['2024-03-01', 'Coffee bar', 3]]);
    $now = now();

    StatementLine::query()->insert(array_map(fn (int $i) => [
        'statement_id' => $big->id, 'user_id' => $user->id, 'posted_on' => '2024-04-10', 'amount' => -1.5,
        'description' => 'Coffee beans', 'parsed_by' => 'csv', 'match_status' => 'unmatched', 'created_at' => $now, 'updated_at' => $now,
    ], range(1, 1100)));
    app(StatementSearchIndex::class)->index($big);

    $results = collect(statementsSearchResults($user, ['q' => 'coffee'])['results'])->keyBy('id');

    expect($results[$big->id]['lines_total'])->toBe(1100)
        ->and($results[$big->id]['lines'])->toHaveCount(StatementSearch::MAX_LINES_PER_STATEMENT)
        ->and($results[$small->id]['lines_total'])->toBe(1);
})->with('statementsFinishBackends');
