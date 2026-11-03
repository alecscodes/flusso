<?php

use App\Ai\Agents\ReceiptReader;
use App\Enums\AiFeature;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Receipts\ReceiptAmounts;
use App\Services\Receipts\ReceiptOcr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * @param  array<string, mixed>  $overrides
 */
function receiptsEnableAi(array $overrides = []): void
{
    app(AiSettings::class)->save(array_merge([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [$feature->value => true])->all(),
    ], $overrides));
}

function receiptsImage(string $name = 'receipt.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
    );
}

function receiptsOcrReturns(?string $text): void
{
    test()->mock(ReceiptOcr::class, fn ($mock) => $mock->shouldReceive('read')->andReturn($text));
}

/**
 * @param  array<string, mixed>  $data
 */
function receiptsScan(User $user, array $data = []): TestResponse
{
    return actingAs($user)->post(route('receipts.scan'), [
        'image' => receiptsImage(),
        'mode' => 'total',
        ...$data,
    ], ['Accept' => 'application/json']);
}

beforeEach(function () {
    Storage::fake('local');
    receiptsEnableAi();
    receiptsOcrReturns("LIDL\nUOVA 3,10\nTOTALE 13,70");
});

test('a total scan proposes one expense and saves nothing', function () {
    $user = User::factory()->create();
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Groceries']);
    ReceiptReader::fake([['merchant' => 'Lidl', 'date' => now()->subDay()->toDateString(), 'total' => '13,70', 'category_id' => $groceries->id]]);

    receiptsScan($user)
        ->assertOk()
        ->assertJsonPath('mode', 'total')
        ->assertJsonPath('merchant', 'Lidl')
        ->assertJsonPath('date', now()->subDay()->toDateString())
        ->assertJsonPath('total', 13.7)
        ->assertJsonPath('transactions.0.amount', 13.7)
        ->assertJsonPath('transactions.0.category_id', $groceries->id)
        ->assertJsonPath('transactions.0.description', 'Lidl')
        ->assertJsonStructure(['token']);

    expect($user->transactions()->count())->toBe(0);
});

test('amounts can be rounded up to the next whole unit', function () {
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => 'Lidl', 'date' => '', 'total' => 13.70, 'category_id' => 0]]);

    receiptsScan($user, ['amounts' => 'round_up'])
        ->assertOk()
        ->assertJsonPath('total', 13.7)
        ->assertJsonPath('transactions.0.amount', 14.0)
        ->assertJsonPath('transactions.0.category_id', null)
        ->assertJsonPath('date', null);
});

test('the model only sees the receipt and the category names of the owner', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Groceries']);
    Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'name' => 'Salary']);
    Category::factory()->create(['user_id' => $other->id, 'type' => 'expense', 'name' => 'Secret hobby']);
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Vault account', 'balance' => 4321]);
    ReceiptReader::fake([['merchant' => '', 'date' => '', 'total' => 5, 'category_id' => 0]]);

    receiptsScan($user)->assertOk();

    ReceiptReader::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Groceries')
        && $prompt->contains('TOTALE 13,70')
        && ! $prompt->contains('Salary')
        && ! $prompt->contains('Secret hobby')
        && ! $prompt->contains('Vault account')
        && ! $prompt->contains('4321'));
});

test('a category the model makes up, or that is not an expense category of the owner, is dropped', function (string $case) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $ids = [
        'foreign' => Category::factory()->create(['user_id' => $other->id, 'type' => 'expense'])->id,
        'income' => Category::factory()->create(['user_id' => $user->id, 'type' => 'income'])->id,
        'unknown' => 999999,
    ];
    ReceiptReader::fake([['merchant' => 'Shop', 'date' => '', 'total' => 5, 'category_id' => $ids[$case]]]);

    receiptsScan($user)->assertOk()->assertJsonPath('transactions.0.category_id', null);
})->with(['foreign', 'income', 'unknown']);

test('a model with vision gets the image and no OCR runs', function () {
    receiptsEnableAi(['vision' => true]);
    test()->mock(ReceiptOcr::class, fn ($mock) => $mock->shouldReceive('read')->never());
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => 'Shop', 'date' => '', 'total' => 5, 'category_id' => 0]]);

    receiptsScan($user)->assertOk()->assertJsonPath('transactions.0.amount', 5.0);
});

test('a split scan groups the lines by category and reports the reconciliation difference', function () {
    $user = User::factory()->create();
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Groceries']);
    $auto = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Auto']);
    ReceiptReader::fake([[
        'merchant' => 'Hypermarket',
        'date' => '',
        'total' => 31.00,
        'lines' => [
            ['description' => 'Eggs', 'amount' => 3.10, 'category_id' => $groceries->id],
            ['description' => 'Wipers', 'amount' => '25,00', 'category_id' => $auto->id],
            ['description' => 'Milk', 'amount' => '2.00', 'category_id' => $groceries->id],
            ['description' => 'Discount', 'amount' => -1.50, 'category_id' => 0],
            ['description' => 'Broken', 'amount' => 'n/a', 'category_id' => 0],
        ],
    ]]);

    receiptsScan($user, ['mode' => 'split'])
        ->assertOk()
        ->assertJsonCount(3, 'lines')
        ->assertJsonPath('lines_sum', 30.1)
        ->assertJsonPath('difference', 0.9)
        ->assertJsonCount(2, 'transactions')
        ->assertJsonPath('transactions.0.category_id', $groceries->id)
        ->assertJsonPath('transactions.0.amount', 5.1)
        ->assertJsonPath('transactions.1.category_id', $auto->id)
        ->assertJsonPath('transactions.1.amount', 25.0);
});

test('a split scan rounds each group up when asked to', function () {
    $user = User::factory()->create();
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    ReceiptReader::fake([['merchant' => '', 'date' => '', 'total' => 5.10, 'lines' => [
        ['description' => 'Eggs', 'amount' => 3.10, 'category_id' => $groceries->id],
        ['description' => 'Milk', 'amount' => 2.00, 'category_id' => $groceries->id],
    ]]]);

    receiptsScan($user, ['mode' => 'split', 'amounts' => 'round_up'])
        ->assertOk()
        ->assertJsonPath('difference', null)
        ->assertJsonPath('transactions.0.amount', 6.0)
        ->assertJsonPath('lines.0.amount', 3.1);
});

test('a gap of five cents or less between the lines and the total is not a difference', function () {
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => '', 'date' => '', 'total' => 5.14, 'lines' => [
        ['description' => 'Eggs', 'amount' => 5.10, 'category_id' => 0],
    ]]]);

    receiptsScan($user, ['mode' => 'split'])->assertOk()->assertJsonPath('difference', null);
});

test('the number of lines is capped', function () {
    $user = User::factory()->create();
    $lines = array_fill(0, 100, ['description' => 'Item', 'amount' => 1, 'category_id' => 0]);
    ReceiptReader::fake([['merchant' => '', 'date' => '', 'total' => 0, 'lines' => $lines]]);

    receiptsScan($user, ['mode' => 'split'])->assertOk()->assertJsonCount(60, 'lines');
});

test('a total mode scan without a readable total is an error', function () {
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => 'Shop', 'date' => '', 'total' => 0, 'category_id' => 0]]);

    receiptsScan($user)->assertUnprocessable()->assertJsonValidationErrors('image');

    expect(Storage::disk('local')->allFiles('receipt-uploads'))->toBeEmpty();
});

test('a split scan without items is an error', function () {
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => 'Shop', 'date' => '', 'total' => 5, 'lines' => []]]);

    receiptsScan($user, ['mode' => 'split'])->assertUnprocessable()->assertJsonValidationErrors('image');
});

test('an invalid date from the model is ignored', function (string $date) {
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => '', 'date' => $date, 'total' => 5, 'category_id' => 0]]);

    receiptsScan($user)->assertOk()->assertJsonPath('date', null);
})->with(['2026-02-31', 'yesterday', '1999-01-01', '2999-01-01', '13/02/2026']);

test('unreadable OCR text is a clear error and the upload is not kept', function () {
    receiptsOcrReturns(null);
    $user = User::factory()->create();
    ReceiptReader::fake();

    receiptsScan($user)->assertUnprocessable()->assertJsonValidationErrors('image');

    ReceiptReader::assertNeverPrompted();
    expect(Storage::disk('local')->allFiles('receipt-uploads'))->toBeEmpty();
});

test('a failing model is a clear error and the form keeps working', function () {
    Http::fake(['*' => Http::response('boom', 500)]);
    $user = User::factory()->create();

    receiptsScan($user)->assertUnprocessable()->assertJsonValidationErrors('image');

    expect(Storage::disk('local')->allFiles('receipt-uploads'))->toBeEmpty();
});

test('scanning is forbidden while AI or the receipts feature is off', function (array $settings) {
    receiptsEnableAi($settings);
    $user = User::factory()->create();
    ReceiptReader::fake();

    receiptsScan($user)->assertForbidden();

    ReceiptReader::assertNeverPrompted();
})->with([
    'AI off' => [['enabled' => false]],
    'feature off' => [['features' => ['categorize' => true, 'receipts' => false, 'quick_entry' => true, 'statements' => true, 'insights' => true]]],
]);

test('guests cannot scan', function () {
    post(route('receipts.scan'), ['image' => receiptsImage(), 'mode' => 'total'], ['Accept' => 'application/json'])->assertUnauthorized();
});

test('only JPEG, PNG and WebP content is accepted, whatever the file name says', function (string $name, string $content) {
    $user = User::factory()->create();
    ReceiptReader::fake();

    receiptsScan($user, ['image' => UploadedFile::fake()->createWithContent($name, $content)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    ReceiptReader::assertNeverPrompted();
})->with([
    'script named png' => ['receipt.png', '<?php echo 1;'],
    'text named jpg' => ['receipt.jpg', 'just text'],
    'pdf' => ['receipt.pdf', "%PDF-1.4\n%%EOF"],
]);

test('the scan mode is required and must be known', function (?string $mode) {
    $user = User::factory()->create();

    receiptsScan($user, ['mode' => $mode])->assertUnprocessable()->assertJsonValidationErrors('mode');
})->with([null, 'everything']);

test('the stored upload gets a generated name in the folder of the user', function () {
    $user = User::factory()->create();
    ReceiptReader::fake([['merchant' => '', 'date' => '', 'total' => 5, 'category_id' => 0]]);

    $token = receiptsScan($user, ['image' => receiptsImage('../../evil name.png')])->assertOk()->json('token');

    expect($token)->toMatch('/^[0-9a-f-]{36}\.png$/');
    Storage::disk('local')->assertExists("receipt-uploads/{$user->id}/{$token}");
});

test('amounts from the model are read defensively', function (mixed $input, ?float $expected) {
    expect(ReceiptAmounts::parse($input))->toBe($expected);
})->with([
    ['13.70', 13.7],
    ['13,70', 13.7],
    ['1.234,56', 1234.56],
    ['1,234.56', 1234.56],
    ['EUR 13,70', 13.7],
    [13.7, 13.7],
    [14, 14.0],
    ['-5,00', null],
    [-5, null],
    [0, null],
    ['0', null],
    ['abc', null],
    ['', null],
    [null, null],
    [[], null],
    [NAN, null],
    [INF, null],
    [1.0E+15, null],
]);
