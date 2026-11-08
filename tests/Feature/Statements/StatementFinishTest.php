<?php

use App\Ai\Agents\StatementLinesAgent;
use App\Enums\StatementLineStatus;
use App\Enums\StatementParseStatus;
use App\Jobs\ParseStatementWithAi;
use App\Models\Account;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\Statements\PdfLineParser;
use App\Services\Statements\StatementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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

test('a statement without debit and credit columns keeps its amounts as printed', function () {
    $lines = app(PdfLineParser::class)->parse(statementsFinishItalianText())->lines;

    expect(array_map(fn ($line) => [$line->amount, $line->signUncertain], $lines))->toBe([['-23.40', false], ['1850.00', false]]);
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

test('nothing is queued when AI is off or the deterministic parsers read the lines', function () {
    Queue::fake();
    $user = User::factory()->create();

    statementsFinishPdfUpload($user, statementsProseText(), 'prose.pdf');
    statementsFinishPdfUpload($user, statementsFinishItalianText(), 'italian.pdf');

    statementsEnableAi();
    statementsFinishPdfUpload($user, statementsFinishUsText(), 'again.pdf');

    Queue::assertNothingPushed();

    expect($user->statements()->pluck('parse_status')->unique()->values()->all())->toBe([StatementParseStatus::Done]);
});

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

test('a statement waiting for the AI can be read again and its lines imported', function () {
    [$user, $statement] = statementsFinishPendingStatement();

    actingAs($user)->post(route('statements.reparse', $statement))->assertSessionHasNoErrors();

    Queue::assertPushed(ParseStatementWithAi::class, 2);

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

    actingAs($user)->post(route('statements.reparse', $statement));

    Queue::assertPushed(ParseStatementWithAi::class, 2);

    expect($statement->refresh()->parse_status)->toBe(StatementParseStatus::PendingAi);
});
