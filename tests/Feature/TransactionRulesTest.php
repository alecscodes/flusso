<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionFile;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

function expensePayload(Account $account, array $overrides = []): array
{
    return ['type' => 'expense', 'account_id' => $account->id, 'amount' => 10, 'date' => '2026-03-10', ...$overrides];
}

test('dates must be Y-m-d and amounts must fit the column', function (array $overrides, string $field) {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

    actingAs($user)->post(route('transactions.store'), expensePayload($account, $overrides))->assertSessionHasErrors($field);

    expect(Transaction::count())->toBe(0);
})->with([
    'day first' => [['date' => '10/03/2026'], 'date'],
    'with a time' => [['date' => '2026-03-10 10:00:00'], 'date'],
    'too big' => [['amount' => 10000000000], 'amount'],
]);

test('a received amount is limited like an amount', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $to = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON']);

    actingAs($user)->post(route('transactions.store'), [
        'type' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 10, 'to_amount' => 10000000000, 'date' => '2026-03-10',
    ])->assertSessionHasErrors('to_amount');
});

test('saving a change that does not touch the ledger leaves the balance alone', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $service = app(TransactionService::class);
    $transaction = $service->createTransaction($user, expensePayload($account, ['amount' => 100]));

    // A deliberately wrong cache shows whether a save recalculated it.
    Account::whereKey($account->id)->update(['balance' => 5]);

    $service->updateTransaction($transaction, expensePayload($account, ['amount' => 100, 'description' => 'Groceries']));
    expect($account->fresh()->balance)->toBe('5.00');

    $service->updateTransaction($transaction, expensePayload($account, ['amount' => 50]));
    expect($account->fresh()->balance)->toBe('950.00');
});

test('an edited transaction may keep its archived category but nobody can pick one', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $old = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $other = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $transaction = app(TransactionService::class)->createTransaction($user, expensePayload($account, ['category_id' => $old->id]));
    $old->delete();
    $other->delete();

    actingAs($user)->put(route('transactions.update', $transaction), expensePayload($account, ['category_id' => $old->id, 'description' => 'Edited']))
        ->assertSessionHasNoErrors();
    actingAs($user)->put(route('transactions.update', $transaction), expensePayload($account, ['category_id' => $other->id]))
        ->assertSessionHasErrors('category_id');
    actingAs($user)->post(route('transactions.store'), expensePayload($account, ['category_id' => $old->id]))
        ->assertSessionHasErrors('category_id');

    expect($transaction->fresh())->description->toBe('Edited')->category_id->toBe($old->id);
});

test('saving or deleting a transaction returns to the page it was done from', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $page = '/transactions?search=coffee&page=2';

    actingAs($user)->from($page)->post(route('transactions.store'), expensePayload($account))->assertRedirect($page);

    $transaction = Transaction::sole();

    actingAs($user)->from($page)->put(route('transactions.update', $transaction), expensePayload($account, ['amount' => 11]))->assertRedirect($page);
    actingAs($user)->from($page)->delete(route('transactions.destroy', $transaction))->assertRedirect($page);
});

test('the transaction filters reject malformed or reversed dates', function (array $query) {
    actingAs(User::factory()->create())->get(route('transactions.index', $query))->assertSessionHasErrors();
})->with([
    'not a date' => [['date_start' => 'yesterday']],
    'with a time' => [['date_end' => '2026-03-10 10:00:00']],
    'end before start' => [['date_start' => '2026-03-10', 'date_end' => '2026-03-09']],
]);

test('the category spending beside the list follows the list filters', function () {
    $user = User::factory()->create();
    $mine = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $other = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Food']);
    $fun = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Fun']);
    $service = app(TransactionService::class);
    $service->createTransaction($user, expensePayload($mine, ['category_id' => $food->id, 'amount' => 20]));
    $service->createTransaction($user, expensePayload($other, ['category_id' => $fun->id, 'amount' => 30]));

    $spending = fn (array $query) => collect(actingAs($user)->get(route('transactions.index', ['date_start' => '2026-03-01', 'date_end' => '2026-03-31', ...$query]))
        ->viewData('page')['props']['categorySpending'])->pluck('category.name')->all();

    expect($spending([]))->toBe(['Fun', 'Food'])
        ->and($spending(['account_id' => $mine->id]))->toBe(['Food'])
        ->and($spending(['category_id' => $fun->id]))->toBe(['Fun'])
        ->and($spending(['search' => 'nothing like this']))->toBe([]);
});

test('transactions deleted more than 30 days ago are pruned for good, files included', function () {
    Storage::fake('local');
    $this->travelTo('2026-03-01 12:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $service = app(TransactionService::class);
    $old = $service->createTransaction($user, expensePayload($account));
    $recent = $service->createTransaction($user, expensePayload($account));
    $live = $service->createTransaction($user, expensePayload($account));

    Storage::disk('local')->put('transactions/receipt.pdf', 'x');
    TransactionFile::create(['transaction_id' => $old->id, 'filename' => 'receipt.pdf', 'original_filename' => 'receipt.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'path' => 'transactions/receipt.pdf']);

    $service->deleteTransaction($old);
    $this->travelTo('2026-03-25 12:00:00');
    $service->deleteTransaction($recent);
    $this->travelTo('2026-04-02 12:00:00'); // old: 32 days in the bin, recent: 8

    $this->artisan('model:prune', ['--model' => Transaction::class])->assertSuccessful();

    expect(Transaction::withTrashed()->pluck('id')->all())->toBe([$recent->id, $live->id])
        ->and(Storage::disk('local')->exists('transactions/receipt.pdf'))->toBeFalse()
        ->and(TransactionFile::count())->toBe(0);
});
