<?php

use App\Enums\AiFeature;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionFile;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Receipts\ReceiptFileStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

function receiptsStashFor(User $user): string
{
    return app(ReceiptFileStore::class)->stash($user, UploadedFile::fake()->createWithContent(
        'my receipt.png',
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function receiptsPayload(User $user, Account $account, array $overrides = []): array
{
    return [
        'account_id' => $account->id,
        'date' => '2026-10-31',
        'token' => receiptsStashFor($user),
        'transactions' => [
            ['category_id' => null, 'amount' => '13.70', 'description' => 'Lidl'],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    Storage::fake('local');
});

test('a confirmed receipt creates the expense through the ledger and keeps the image', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 1000]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $payload = receiptsPayload($user, $account, ['transactions' => [
        ['category_id' => $groceries->id, 'amount' => 14, 'description' => 'Lidl'],
    ]]);

    actingAs($user)->post(route('receipts.store'), $payload)->assertRedirect(route('transactions.index'));

    $transaction = $user->transactions()->with('files')->sole();

    expect($transaction->type->value)->toBe('expense')
        ->and($transaction->amount)->toBe('14.00')
        ->and($transaction->currency)->toBe('RON')
        ->and($transaction->category_id)->toBe($groceries->id)
        ->and($transaction->description)->toBe('Lidl')
        ->and($transaction->date->toDateString())->toBe('2026-10-31')
        ->and($account->refresh()->balance)->toBe('986.00')
        ->and($transaction->files)->toHaveCount(1);

    $file = $transaction->files->first();
    expect($file->original_filename)->toBe('receipt.png')
        ->and($file->mime_type)->toBe('image/png')
        ->and($file->path)->toStartWith('transaction-files/');
    Storage::disk('local')->assertExists($file->path);
    expect(Storage::disk('local')->allFiles('receipt-uploads'))->toBeEmpty();
});

test('a split receipt creates one expense per group, each with its own copy of the image', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $auto = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    actingAs($user)->post(route('receipts.store'), receiptsPayload($user, $account, ['transactions' => [
        ['category_id' => $groceries->id, 'amount' => '5.10', 'description' => 'Hypermarket'],
        ['category_id' => $auto->id, 'amount' => '25.90', 'description' => 'Hypermarket'],
    ]]))->assertRedirect();

    expect($user->transactions()->count())->toBe(2)
        ->and($account->refresh()->balance)->toBe('969.00');

    $files = TransactionFile::all();
    expect($files)->toHaveCount(2)
        ->and($files->pluck('path')->unique())->toHaveCount(2);

    // Deleting one of them for good leaves the receipt of the other.
    $first = $user->transactions()->orderBy('id')->first();
    $second = $user->transactions()->orderBy('id')->skip(1)->first();
    $keptPath = $second->files()->first()->path;

    $first->forceDelete();

    Storage::disk('local')->assertExists($keptPath);
    expect($second->files()->count())->toBe(1);
});

test('a file that two rows share survives until the last one is deleted for good', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $one = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
    $two = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
    Storage::disk('local')->put('transaction-files/shared.png', 'x');

    foreach ([$one, $two] as $transaction) {
        $transaction->files()->create([
            'filename' => 'shared.png',
            'original_filename' => 'receipt.png',
            'mime_type' => 'image/png',
            'size' => 1,
            'path' => 'transaction-files/shared.png',
        ]);
    }

    $one->forceDelete();
    Storage::disk('local')->assertExists('transaction-files/shared.png');

    $two->forceDelete();
    Storage::disk('local')->assertMissing('transaction-files/shared.png');
});

test('the amounts are kept exactly as confirmed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)->post(route('receipts.store'), receiptsPayload($user, $account, ['transactions' => [
        ['category_id' => null, 'amount' => '13.70', 'description' => null],
    ]]))->assertRedirect();

    expect($user->transactions()->sole()->amount)->toBe('13.70');
});

test('nothing is saved when one entry is invalid', function (array $entry, string $errorKey) {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
    $payload = receiptsPayload($user, $account, ['transactions' => [
        ['category_id' => null, 'amount' => '5.00', 'description' => 'ok'],
        $entry,
    ]]);

    actingAs($user)->post(route('receipts.store'), $payload)->assertSessionHasErrors($errorKey);

    expect(Transaction::count())->toBe(0)
        ->and($account->refresh()->balance)->toBe('1000.00');
})->with([
    'zero amount' => [['category_id' => null, 'amount' => '0', 'description' => null], 'transactions.1.amount'],
    'negative amount' => [['category_id' => null, 'amount' => '-3', 'description' => null], 'transactions.1.amount'],
    'three decimals' => [['category_id' => null, 'amount' => '1.234', 'description' => null], 'transactions.1.amount'],
    'not a number' => [['category_id' => null, 'amount' => 'abc', 'description' => null], 'transactions.1.amount'],
    'unknown category' => [['category_id' => 999999, 'amount' => '1', 'description' => null], 'transactions.1.category_id'],
]);

test('categories of another user or of the wrong kind are rejected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $foreign = Category::factory()->create(['user_id' => User::factory()->create()->id, 'type' => 'expense']);
    $income = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    foreach ([$foreign, $income] as $category) {
        actingAs($user)
            ->post(route('receipts.store'), receiptsPayload($user, $account, ['transactions' => [
                ['category_id' => $category->id, 'amount' => '5', 'description' => null],
            ]]))
            ->assertSessionHasErrors('transactions.0.category_id');
    }

    expect(Transaction::count())->toBe(0);
});

test('an account of another user is rejected', function () {
    $user = User::factory()->create();
    $foreign = Account::factory()->create(['user_id' => User::factory()->create()->id, 'balance' => 500]);

    actingAs($user)
        ->post(route('receipts.store'), receiptsPayload($user, $foreign))
        ->assertSessionHasErrors('account_id');

    expect(Transaction::count())->toBe(0)
        ->and($foreign->refresh()->balance)->toBe('500.00');
});

test('an archived account is rejected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $account->delete();

    actingAs($user)
        ->post(route('receipts.store'), receiptsPayload($user, $account))
        ->assertSessionHasErrors('account_id');
});

test('the receipt of another user cannot be attached', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $attacker->id]);
    $victimToken = receiptsStashFor($owner);

    actingAs($attacker)
        ->post(route('receipts.store'), receiptsPayload($attacker, $account, ['token' => $victimToken]))
        ->assertSessionHasErrors('token');

    expect(Transaction::count())->toBe(0);
    Storage::disk('local')->assertExists("receipt-uploads/{$owner->id}/{$victimToken}");
});

test('a token that is not a generated file name is rejected', function (string $token) {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->post(route('receipts.store'), receiptsPayload($user, $account, ['token' => $token]))
        ->assertSessionHasErrors('token');
})->with(['../../.env', 'receipt.png', '12345678-1234-1234-1234-123456789012.php', '']);

test('a receipt that was already used cannot be used again', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $payload = receiptsPayload($user, $account);

    actingAs($user)->post(route('receipts.store'), $payload)->assertSessionHasNoErrors();
    actingAs($user)->post(route('receipts.store'), $payload)->assertSessionHasErrors('token');

    expect(Transaction::count())->toBe(1);
});

test('at least one and at most sixty entries are taken', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $entry = ['category_id' => null, 'amount' => '1', 'description' => null];

    actingAs($user)
        ->post(route('receipts.store'), receiptsPayload($user, $account, ['transactions' => []]))
        ->assertSessionHasErrors('transactions');
    actingAs($user)
        ->post(route('receipts.store'), receiptsPayload($user, $account, ['transactions' => array_fill(0, 61, $entry)]))
        ->assertSessionHasErrors('transactions');
});

test('guests cannot confirm a receipt', function () {
    post(route('receipts.store'), [])->assertRedirect(route('login'));
});

test('stale uploads of the user are removed when a new receipt is scanned', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Storage::disk('local')->put("receipt-uploads/{$user->id}/old.png", 'x');
    Storage::disk('local')->put("receipt-uploads/{$other->id}/old.png", 'x');
    touch(Storage::disk('local')->path("receipt-uploads/{$user->id}/old.png"), now()->subDays(2)->getTimestamp());
    touch(Storage::disk('local')->path("receipt-uploads/{$other->id}/old.png"), now()->subDays(2)->getTimestamp());

    $token = receiptsStashFor($user);

    Storage::disk('local')->assertMissing("receipt-uploads/{$user->id}/old.png");
    Storage::disk('local')->assertExists("receipt-uploads/{$user->id}/{$token}");
    Storage::disk('local')->assertExists("receipt-uploads/{$other->id}/old.png");
});

test('the transactions page tells the dialog whether receipt scanning is available', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page->where('receiptScanEnabled', false));

    app(AiSettings::class)->save([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [$feature->value => true])->all(),
    ]);

    actingAs($user)->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page->where('receiptScanEnabled', true));
});
