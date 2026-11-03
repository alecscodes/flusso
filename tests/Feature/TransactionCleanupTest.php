<?php

use App\Ai\Agents\CategorySuggestionAgent;
use App\Enums\AiFeature;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiSettings;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * @param  array<string, mixed>  $overrides
 */
function cleanupTransaction(User $user, Account $account, ?Category $category, string $description, array $overrides = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'type' => 'expense',
        'amount' => 12.5,
        'description' => $description,
        ...$overrides,
    ]);
}

test('the cleanup page lists the transactions without a category with a suggestion from history', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Groceries']);

    cleanupTransaction($user, $account, $groceries, 'Lidl', ['date' => now()->subDays(20)->toDateString()]);
    $known = cleanupTransaction($user, $account, null, 'lidl', ['date' => now()->subDay()->toDateString()]);
    $unknown = cleanupTransaction($user, $account, null, 'Dentist', ['date' => now()->subDays(2)->toDateString()]);
    cleanupTransaction($user, $account, $groceries, 'Already filed');

    $other = User::factory()->create();
    cleanupTransaction($other, Account::factory()->create(['user_id' => $other->id]), null, 'Not mine');

    actingAs($user)
        ->get(route('cleanup.index'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Transactions/Cleanup')
            ->has('transactions.data', 2)
            ->where('transactions.data.0.id', $known->id)
            ->where('transactions.data.0.suggestion.category_id', $groceries->id)
            ->where('transactions.data.0.suggestion.source', 'history')
            ->where('transactions.data.1.id', $unknown->id)
            ->where('transactions.data.1.suggestion', null)
            ->where('aiEnabled', false)
        );
});

test('transfers are not listed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $to = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->transfer($to)->create(['user_id' => $user->id, 'account_id' => $account->id]);

    actingAs($user)
        ->get(route('cleanup.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('transactions.data', 0));
});

test('applying a suggestion sets only the category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 500]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $transaction = cleanupTransaction($user, $account, null, 'Lidl', ['date' => '2026-03-04']);
    $balance = $account->fresh()->balance;

    actingAs($user)
        ->patch(route('cleanup.apply', $transaction), ['category_id' => $category->id])
        ->assertRedirect();

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->amount)->toBe('12.50')
        ->and($transaction->description)->toBe('Lidl')
        ->and($transaction->date->toDateString())->toBe('2026-03-04')
        ->and($transaction->account_id)->toBe($account->id)
        ->and($account->fresh()->balance)->toBe($balance);
});

test('a suggestion cannot be applied with a category of another user or of the wrong type', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = cleanupTransaction($user, $account, null, 'Lidl');

    $foreign = Category::factory()->create(['user_id' => $other->id, 'type' => 'expense']);
    $income = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    actingAs($user)->patch(route('cleanup.apply', $transaction), ['category_id' => $foreign->id])->assertSessionHasErrors('category_id');
    actingAs($user)->patch(route('cleanup.apply', $transaction), ['category_id' => $income->id])->assertSessionHasErrors('category_id');
    actingAs($user)->patch(route('cleanup.apply', $transaction), [])->assertSessionHasErrors('category_id');

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('a transfer cannot be given a category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $to = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $transfer = Transaction::factory()->transfer($to)->create(['user_id' => $user->id, 'account_id' => $account->id]);

    actingAs($user)->patch(route('cleanup.apply', $transfer), ['category_id' => $category->id])->assertSessionHasErrors('category_id');

    expect($transfer->fresh()->category_id)->toBeNull();
});

test('users cannot apply a category to another users transaction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $transaction = cleanupTransaction($other, Account::factory()->create(['user_id' => $other->id]), null, 'Lidl');
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    actingAs($user)
        ->patch(route('cleanup.apply', $transaction), ['category_id' => $category->id])
        ->assertForbidden();

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('applying all only uses confident history matches and never the model or other users data', function () {
    app(AiSettings::class)->save([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [$feature->value => true])->all(),
    ]);
    CategorySuggestionAgent::fake();

    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Groceries']);
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'name' => 'Salary']);

    cleanupTransaction($user, $account, $groceries, 'Lidl');
    cleanupTransaction($user, $account, $salary, 'Bonus', ['type' => 'income']);

    $lidl = cleanupTransaction($user, $account, null, 'LIDL');
    $bonus = cleanupTransaction($user, $account, null, 'bonus', ['type' => 'income']);
    $unknown = cleanupTransaction($user, $account, null, 'Dentist');
    $noDescription = cleanupTransaction($user, $account, null, '', ['description' => null]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $theirs = cleanupTransaction($other, $otherAccount, null, 'Lidl');

    actingAs($user)->post(route('cleanup.applyAll'))->assertRedirect();

    expect($lidl->fresh()->category_id)->toBe($groceries->id)
        ->and($bonus->fresh()->category_id)->toBe($salary->id)
        ->and($unknown->fresh()->category_id)->toBeNull()
        ->and($noDescription->fresh()->category_id)->toBeNull()
        ->and($theirs->fresh()->category_id)->toBeNull();

    CategorySuggestionAgent::assertNeverPrompted();
});

test('guests cannot use the cleanup', function () {
    get(route('cleanup.index'))->assertRedirect(route('login'));
});
