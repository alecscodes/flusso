<?php

use App\Enums\StatementLineStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\Statements\PdfTextExtractor;
use App\Services\Statements\StatementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

function statementsCsvFile(string $name = 'march.csv', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? implode("\n", [
        'Date,Description,Amount',
        '2024-03-02,Coffee Shop,-3.50',
        '2024-03-03,Salary ACME,1500.00',
    ]));
}

function statementsPdfFile(string $name = 'april.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 fake statement');
}

/**
 * Replaces pdftotext, which tests never run, with fixed text.
 */
function statementsFakePdfText(?string $text): void
{
    app()->instance(PdfTextExtractor::class, new class($text) extends PdfTextExtractor
    {
        public function __construct(private ?string $text) {}

        public function extract(string $path): ?string
        {
            return $this->text;
        }
    });
}

/**
 * A statement of the user with a real file in the fake private disk.
 *
 * @param  array<string, mixed>  $attributes
 */
function statementsStoredFor(User $user, array $attributes = []): Statement
{
    $path = 'statements/'.$user->id.'/'.fake()->uuid().'.csv';
    Storage::disk('local')->put($path, "Date,Description,Amount\n2024-03-02,Coffee,-3.50\n");

    return Statement::factory()->create([
        'user_id' => $user->id,
        'path' => $path,
        'original_filename' => 'march.csv',
        ...$attributes,
    ]);
}

beforeEach(fn () => Storage::fake('local'));

test('guests are sent to the login page', function () {
    $statement = Statement::factory()->create();

    $this->get(route('statements.index'))->assertRedirect(route('login'));
    $this->post(route('statements.store'))->assertRedirect(route('login'));
    $this->get(route('statements.show', $statement))->assertRedirect(route('login'));
    $this->get(route('statements.download', $statement))->assertRedirect(route('login'));
});

test('uploading a CSV keeps the file under a generated name, reads its lines and opens the statement', function () {
    $user = User::factory()->create();

    $response = actingAs($user)->post(route('statements.store'), ['files' => [statementsCsvFile()]]);

    $statement = $user->statements()->firstOrFail();

    $response->assertRedirect(route('statements.show', $statement));

    expect($statement->original_filename)->toBe('march.csv')
        ->and($statement->mime_type)->toBe('text/csv')
        ->and($statement->sha256)->toHaveLength(64)
        ->and($statement->path)->toStartWith('statements/'.$user->id.'/')
        ->and($statement->path)->not->toContain('march')
        ->and($statement->parser)->toBe('csv')
        ->and($statement->parsed_at)->not->toBeNull()
        ->and($statement->period_start->toDateString())->toBe('2024-03-02')
        ->and($statement->period_end->toDateString())->toBe('2024-03-03')
        ->and($statement->extracted_text)->toContain('Coffee Shop')
        ->and($statement->lines()->count())->toBe(2);

    Storage::disk('local')->assertExists($statement->path);
});

test('typed details win over detected ones', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)->post(route('statements.store'), [
        'files' => [statementsCsvFile()],
        'institution' => 'Old Bank',
        'account_label' => 'Closed savings',
        'account_id' => $account->id,
        'period_start' => '2024-03-01',
        'period_end' => '2024-03-31',
    ])->assertSessionHasNoErrors();

    $statement = $user->statements()->firstOrFail();

    expect($statement->institution)->toBe('Old Bank')
        ->and($statement->account_label)->toBe('Closed savings')
        ->and($statement->account_id)->toBe($account->id)
        ->and($statement->period_start->toDateString())->toBe('2024-03-01')
        ->and($statement->period_end->toDateString())->toBe('2024-03-31');
});

test('a PDF is read through the extractor and is still kept when no line can be parsed', function () {
    statementsFakePdfText("Gentile cliente,\nla informiamo che le condizioni sono cambiate. Codice fiscale RSSMRA80A01H501U");
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [statementsPdfFile()]])->assertSessionHasNoErrors();

    $statement = $user->statements()->firstOrFail();

    expect($statement->mime_type)->toBe('application/pdf')
        ->and($statement->path)->toEndWith('.pdf')
        ->and($statement->parser)->toBe('none')
        ->and($statement->lines()->count())->toBe(0)
        ->and($statement->extracted_text)->toContain('RSSMRA80A01H501U');

    Storage::disk('local')->assertExists($statement->path);
});

test('a PDF whose text cannot be extracted is still stored', function () {
    statementsFakePdfText(null);
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [statementsPdfFile()]])->assertSessionHasNoErrors();

    $statement = $user->statements()->firstOrFail();

    expect($statement->extracted_text)->toBeNull()
        ->and($statement->parsed_at)->not->toBeNull();
});

test('uploading the same file again says it is already in the vault and stores nothing', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [statementsCsvFile('march.csv')]]);

    actingAs($user)
        ->post(route('statements.store'), ['files' => [statementsCsvFile('copy-of-march.csv')]])
        ->assertSessionHasErrors(['files' => 'This file is already in your vault.']);

    expect($user->statements()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('statements'))->toHaveCount(1);
});

test('several files at once skip the duplicates and keep the new ones', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [statementsCsvFile('march.csv')]]);

    actingAs($user)
        ->post(route('statements.store'), ['files' => [
            statementsCsvFile('march-again.csv'),
            statementsCsvFile('feb.csv', "Date,Description,Amount\n2024-02-02,Bakery,-2.00\n"),
        ]])
        ->assertRedirect(route('statements.index'))
        ->assertSessionHasNoErrors();

    expect($user->statements()->count())->toBe(2);
});

test('the same file can be kept by two different users', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    actingAs($first)->post(route('statements.store'), ['files' => [statementsCsvFile()]]);
    actingAs($second)->post(route('statements.store'), ['files' => [statementsCsvFile()]])->assertSessionHasNoErrors();

    expect(Statement::query()->count())->toBe(2);
});

test('files that are not PDF or CSV statements are rejected', function (UploadedFile $file) {
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [$file]])->assertSessionHasErrors('files.0');

    expect(Statement::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'a text file' => fn () => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
    'an image' => fn () => UploadedFile::fake()->create('scan.png', 10, 'image/png'),
    'a spreadsheet' => fn () => UploadedFile::fake()->create('book.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    'a PDF with the wrong mime type' => fn () => UploadedFile::fake()->create('statement.pdf', 10, 'text/html'),
    'a PDF that is not a PDF' => fn () => UploadedFile::fake()->createWithContent('statement.pdf', '<html>not a pdf</html>'),
    'a binary CSV' => fn () => UploadedFile::fake()->createWithContent('data.csv', "a,b\0c"),
]);

test('a file over the size cap is rejected', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('huge.pdf', StatementService::MAX_SIZE_KB + 1, 'application/pdf');

    actingAs($user)->post(route('statements.store'), ['files' => [$file]])->assertSessionHasErrors('files.0');

    expect(Statement::query()->count())->toBe(0);
});

test('at least one file is required and the account must be the user\'s own', function () {
    $user = User::factory()->create();
    $foreign = Account::factory()->create();

    actingAs($user)->post(route('statements.store'), [])->assertSessionHasErrors('files');
    actingAs($user)->post(route('statements.store'), ['files' => [statementsCsvFile()], 'account_id' => $foreign->id])->assertSessionHasErrors('account_id');
});

test('the index lists only the user\'s own statements', function () {
    $user = User::factory()->create();
    $own = statementsStoredFor($user);
    statementsStoredFor(User::factory()->create());

    actingAs($user)->get(route('statements.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Statements/Index')
            ->has('statements.data', 1)
            ->where('statements.data.0.id', $own->id)
            ->where('search', null));
});

test('the show page lists the lines with their match status and counts', function () {
    $user = User::factory()->create();
    $statement = statementsStoredFor($user);
    StatementLine::factory()->create(['statement_id' => $statement->id, 'description' => 'Coffee', 'match_status' => StatementLineStatus::Matched]);
    StatementLine::factory()->count(2)->create(['statement_id' => $statement->id, 'match_status' => StatementLineStatus::Unmatched]);

    actingAs($user)->get(route('statements.show', $statement))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Statements/Show')
            ->where('statement.id', $statement->id)
            ->where('statement.counts.matched', 1)
            ->where('statement.counts.unmatched', 2)
            ->has('lines.data', 3));

    actingAs($user)->get(route('statements.show', [$statement, 'status' => 'unmatched']))
        ->assertInertia(fn ($page) => $page->has('lines.data', 2)->where('status', 'unmatched'));
});

test('the original file downloads only for its owner', function () {
    $owner = User::factory()->create();
    $statement = statementsStoredFor($owner);

    actingAs($owner)->get(route('statements.download', $statement))
        ->assertSuccessful()
        ->assertDownload('march.csv');

    actingAs(User::factory()->create())->get(route('statements.download', $statement))->assertForbidden();
});

test('a missing file is a 404, not an error', function () {
    $owner = User::factory()->create();
    $statement = statementsStoredFor($owner);
    Storage::disk('local')->delete($statement->path);

    actingAs($owner)->get(route('statements.download', $statement))->assertNotFound();
});

test('other users cannot see, change, read again or delete a statement', function () {
    $owner = User::factory()->create();
    $statement = statementsStoredFor($owner);
    $intruder = User::factory()->create();

    actingAs($intruder)->get(route('statements.show', $statement))->assertForbidden();
    actingAs($intruder)->patch(route('statements.update', $statement), ['institution' => 'Hacked'])->assertForbidden();
    actingAs($intruder)->post(route('statements.reparse', $statement))->assertForbidden();
    actingAs($intruder)->delete(route('statements.destroy', $statement))->assertForbidden();

    expect($statement->fresh()->institution)->toBe('Test Bank');
    Storage::disk('local')->assertExists($statement->path);
});

test('the owner can edit the details', function () {
    $user = User::factory()->create();
    $statement = statementsStoredFor($user);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)->patch(route('statements.update', $statement), [
        'institution' => 'Revolut',
        'account_label' => 'Main',
        'account_id' => $account->id,
        'period_start' => '2024-03-01',
        'period_end' => '2024-03-31',
    ])->assertSessionHasNoErrors();

    $statement->refresh();

    expect($statement->institution)->toBe('Revolut')
        ->and($statement->account_id)->toBe($account->id)
        ->and($statement->period_end->toDateString())->toBe('2024-03-31');

    actingAs($user)->patch(route('statements.update', $statement), ['period_start' => '2024-04-01', 'period_end' => '2024-03-01'])
        ->assertSessionHasErrors('period_end');
});

test('deleting a statement removes the file, its lines and its search rows', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [statementsCsvFile()]]);
    $statement = $user->statements()->firstOrFail();

    actingAs($user)->delete(route('statements.destroy', $statement))->assertRedirect(route('statements.index'));

    expect(Statement::query()->count())->toBe(0)
        ->and(StatementLine::query()->count())->toBe(0);

    Storage::disk('local')->assertMissing($statement->path);
});

test('reading a statement again replaces its lines, unless some were already decided', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('statements.store'), ['files' => [statementsCsvFile()]]);
    $statement = $user->statements()->firstOrFail();
    $statement->lines()->first()->update(['description' => 'edited by hand']);

    actingAs($user)->post(route('statements.reparse', $statement))->assertSessionHasNoErrors();

    expect($statement->lines()->where('description', 'edited by hand')->count())->toBe(0)
        ->and($statement->lines()->count())->toBe(2);

    $statement->lines()->first()->update(['match_status' => StatementLineStatus::Ignored]);

    expect(app(StatementService::class)->reprocess($statement))->toBeFalse()
        ->and($statement->lines()->count())->toBe(2);
});
