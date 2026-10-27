<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

function balanceOf(Account $account): string
{
    return $account->fresh()->balance;
}

/**
 * The invariant, computed independently of Account::ledgerBalance().
 */
function expectLedgerToHold(User $user): void
{
    $rows = Transaction::query()->where('user_id', $user->id)->get();

    foreach (Account::withTrashed()->where('user_id', $user->id)->get() as $account) {
        $cents = (int) round($account->initial_balance * 100);

        foreach ($rows as $row) {
            if ($row->account_id === $account->id) {
                $cents += match ($row->type) {
                    TransactionType::Income => (int) round($row->amount * 100),
                    default => -(int) round($row->amount * 100),
                };
            }

            if ($row->to_account_id === $account->id) {
                $cents += (int) round($row->to_amount * 100);
            }
        }

        expect($account->balance)->toBe(number_format($cents / 100, 2, '.', ''), "account {$account->name}");
    }
}

test('creating, editing and deleting a same-currency transfer updates both accounts', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $to = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 500]);

    actingAs($user)->post(route('transactions.store'), [
        'type' => 'transfer',
        'account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => 300,
        'date' => now()->toDateString(),
    ])->assertRedirect(route('transactions.index'));

    $transfer = Transaction::sole();

    expect(Transaction::count())->toBe(1)
        ->and($transfer->to_amount)->toBe('300.00')
        ->and($transfer->category_id)->toBeNull()
        ->and(balanceOf($from))->toBe('700.00')
        ->and(balanceOf($to))->toBe('800.00');

    actingAs($user)->put(route('transactions.update', $transfer), [
        'type' => 'transfer',
        'account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => 250,
        'to_amount' => 249.5,
        'date' => now()->toDateString(),
    ])->assertRedirect(route('transactions.index'));

    // Same currency: the given to_amount is ignored, what left arrives.
    expect(balanceOf($from))->toBe('750.00')
        ->and(balanceOf($to))->toBe('750.00');

    actingAs($user)->delete(route('transactions.destroy', $transfer))->assertRedirect();

    expect(balanceOf($from))->toBe('1000.00')
        ->and(balanceOf($to))->toBe('500.00');
});

test('moving a transfer to another destination rebalances all three accounts', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $old = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 0]);
    $new = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 0]);

    $transfer = app(TransactionService::class)->createTransaction($user, [
        'type' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $old->id, 'amount' => 100,
    ]);

    app(TransactionService::class)->updateTransaction($transfer, [
        'type' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $new->id, 'amount' => 100,
    ]);

    expect(balanceOf($from))->toBe('900.00')
        ->and(balanceOf($old))->toBe('0.00')
        ->and(balanceOf($new))->toBe('100.00');
});

test('a cross-currency transfer credits the amount received', function () {
    $user = User::factory()->create();
    $eur = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 0]);

    actingAs($user)->post(route('transactions.store'), [
        'type' => 'transfer',
        'account_id' => $eur->id,
        'to_account_id' => $ron->id,
        'amount' => 20,
        'to_amount' => 99.9,
        'date' => now()->toDateString(),
    ])->assertRedirect();

    expect(balanceOf($eur))->toBe('80.00')
        ->and(balanceOf($ron))->toBe('99.90');
});

test('a cross-currency transfer without the amount received uses today\'s rate', function () {
    Http::fake(['*' => Http::response(['eur' => ['ron' => 5.0]])]);

    $user = User::factory()->create();
    $eur = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 0]);

    actingAs($user)->post(route('transactions.store'), [
        'type' => 'transfer',
        'account_id' => $eur->id,
        'to_account_id' => $ron->id,
        'amount' => 20,
        'date' => now()->toDateString(),
    ])->assertRedirect();

    expect(balanceOf($ron))->toBe('100.00');
});

test('a cross-currency transfer fails clearly when no rate is available', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $user = User::factory()->create();
    $eur = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 0]);

    actingAs($user)->post(route('transactions.store'), [
        'type' => 'transfer',
        'account_id' => $eur->id,
        'to_account_id' => $ron->id,
        'amount' => 20,
        'date' => now()->toDateString(),
    ])->assertSessionHasErrors('to_amount');

    expect(Transaction::count())->toBe(0)
        ->and(balanceOf($eur))->toBe('100.00');
});

test('changing the account of a transaction moves the balance', function () {
    $user = User::factory()->create();
    $a = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $b = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 100]);

    $expense = app(TransactionService::class)->createTransaction($user, [
        'type' => 'expense', 'account_id' => $a->id, 'amount' => 30,
    ]);

    actingAs($user)->put(route('transactions.update', $expense), [
        'type' => 'expense', 'account_id' => $b->id, 'amount' => 30, 'date' => now()->toDateString(),
    ])->assertRedirect();

    expect(balanceOf($a))->toBe('100.00')
        ->and(balanceOf($b))->toBe('70.00')
        ->and($expense->fresh()->currency)->toBe('RON');
});

test('changing the type of a transaction rebalances it and nullable fields can be cleared', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 100]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $expense = app(TransactionService::class)->createTransaction($user, [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 30,
        'category_id' => $category->id, 'description' => 'Lunch',
    ]);

    actingAs($user)->put(route('transactions.update', $expense), [
        'type' => 'income', 'account_id' => $account->id, 'amount' => 30, 'date' => now()->toDateString(),
        'category_id' => null, 'description' => null,
    ])->assertRedirect();

    expect(balanceOf($account))->toBe('130.00')
        ->and($expense->fresh()->category_id)->toBeNull()
        ->and($expense->fresh()->description)->toBeNull();
});

test('transaction validation rejects bad categories, accounts and destinations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $second = Account::factory()->create(['user_id' => $user->id]);
    $foreignAccount = Account::factory()->create(['user_id' => $other->id]);
    $incomeCategory = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);
    $foreignCategory = Category::factory()->create(['user_id' => $other->id, 'type' => 'expense']);
    $archived = Account::factory()->create(['user_id' => $user->id]);
    $archived->delete();

    $valid = ['type' => 'expense', 'account_id' => $account->id, 'amount' => 10, 'date' => now()->toDateString()];

    $cases = [
        'expense with an income category' => [['category_id' => $incomeCategory->id], 'category_id'],
        'foreign category' => [['category_id' => $foreignCategory->id], 'category_id'],
        'foreign account' => [['account_id' => $foreignAccount->id], 'account_id'],
        'archived account' => [['account_id' => $archived->id], 'account_id'],
        'transfer with a category' => [['type' => 'transfer', 'to_account_id' => $second->id, 'category_id' => $incomeCategory->id], 'category_id'],
        'transfer without a destination' => [['type' => 'transfer'], 'to_account_id'],
        'transfer to the same account' => [['type' => 'transfer', 'to_account_id' => $account->id], 'to_account_id'],
        'transfer to a foreign account' => [['type' => 'transfer', 'to_account_id' => $foreignAccount->id], 'to_account_id'],
        'destination on an expense' => [['to_account_id' => $second->id], 'to_account_id'],
    ];

    foreach ($cases as [$override, $field]) {
        actingAs($user)->post(route('transactions.store'), [...$valid, ...$override])
            ->assertSessionHasErrors($field);
    }

    expect(Transaction::count())->toBe(0);
});

test('a user cannot edit or delete another user\'s transaction', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $owner->id, 'balance' => 100]);
    $transaction = app(TransactionService::class)->createTransaction($owner, [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 10,
    ]);

    actingAs($intruder)->put(route('transactions.update', $transaction), [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 1, 'date' => now()->toDateString(),
    ])->assertForbidden();
    actingAs($intruder)->delete(route('transactions.destroy', $transaction))->assertForbidden();

    expect(balanceOf($account))->toBe('90.00');
});

test('archiving an account keeps its history and every other balance', function () {
    $user = User::factory()->create();
    $archived = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 500]);
    $kept = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);

    app(TransactionService::class)->createTransaction($user, [
        'type' => 'transfer', 'account_id' => $archived->id, 'to_account_id' => $kept->id, 'amount' => 50,
    ]);
    app(TransactionService::class)->createTransaction($user, [
        'type' => 'income', 'account_id' => $archived->id, 'amount' => 20,
    ]);

    $rule = RecurringPayment::factory()->create(['user_id' => $user->id, 'account_id' => $archived->id, 'is_active' => true]);
    $occurrence = Payment::factory()->create(['user_id' => $user->id, 'account_id' => $archived->id, 'recurring_payment_id' => $rule->id]);

    app(AccountService::class)->deleteAccount($archived);

    expect($rule->fresh()->is_active)->toBeFalse()
        ->and(Payment::find($occurrence->id))->toBeNull();

    expect(Account::find($archived->id))->toBeNull()
        ->and(Transaction::count())->toBe(2)
        ->and(balanceOf($kept))->toBe('150.00')
        ->and(Account::withTrashed()->find($archived->id)->balance)->toBe('470.00');

    actingAs($user)->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.total', 2)
            ->where('transactions.data.1.account.name', $archived->name));

    expectLedgerToHold($user);
});

test('force deleting and restoring a transaction recalculates the balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 100]);
    $expense = app(TransactionService::class)->createTransaction($user, [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 40,
    ]);

    $expense->delete();
    expect(balanceOf($account))->toBe('100.00');

    $expense->restore();
    expect(balanceOf($account))->toBe('60.00');

    $expense->forceDelete();
    expect(balanceOf($account))->toBe('100.00');
});

test('verify-balances is silent when balances match and fails on drift', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 100]);
    app(TransactionService::class)->createTransaction($user, [
        'type' => 'income', 'account_id' => $account->id, 'amount' => 25,
    ]);

    $this->artisan('accounts:verify-balances')->assertSuccessful();

    DB::table('accounts')->where('id', $account->id)->update(['balance' => 999]);

    $this->artisan('accounts:verify-balances')->assertFailed();
    expect(balanceOf($account))->toBe('999.00'); // read-only: it never repairs
});

test('the ledger invariant holds through a random sequence of operations', function (int $seed) {
    mt_srand($seed);
    Http::fake(['*' => Http::response(['eur' => ['ron' => 5.0], 'ron' => ['eur' => 0.2]])]);

    $user = User::factory()->create();
    $service = app(TransactionService::class);
    $accounts = collect(['EUR', 'EUR', 'RON', 'RON'])->map(fn (string $currency) => Account::factory()->create([
        'user_id' => $user->id, 'currency' => $currency, 'balance' => mt_rand(0, 100000) / 100,
    ]));
    $categories = [
        'income' => Category::factory()->create(['user_id' => $user->id, 'type' => 'income']),
        'expense' => Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']),
    ];

    $payload = function () use ($accounts, $categories): array {
        $type = ['income', 'expense', 'transfer'][mt_rand(0, 2)];
        $account = $accounts->random();
        $data = [
            'type' => $type,
            'account_id' => $account->id,
            'amount' => mt_rand(1, 50000) / 100,
            'date' => now()->subDays(mt_rand(0, 40))->toDateString(),
            'category_id' => $type === 'transfer' || mt_rand(0, 1) ? null : $categories[$type]->id,
        ];

        if ($type === 'transfer') {
            $data['to_account_id'] = $accounts->where('id', '!=', $account->id)->random()->id;
            $data['to_amount'] = mt_rand(0, 1) ? mt_rand(1, 50000) / 100 : null;
        }

        return $data;
    };

    $live = collect();

    for ($i = 0; $i < 60; $i++) {
        $action = $live->isEmpty() ? 'create' : ['create', 'create', 'update', 'delete'][mt_rand(0, 3)];

        if ($action === 'create') {
            $live->push($service->createTransaction($user, $payload()));
        } elseif ($action === 'update') {
            $service->updateTransaction($live->random(), $payload());
        } else {
            $victim = $live->random();
            $service->deleteTransaction($victim);
            $live = $live->reject(fn (Transaction $transaction) => $transaction->is($victim));
        }

        expectLedgerToHold($user);
    }
})->with([1, 2, 3, 4, 5]);

test('search matches descriptions and exact amounts, including the amount received by a transfer', function () {
    $user = User::factory()->create();
    $eur = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 0]);
    $service = app(TransactionService::class);

    $expense = $service->createTransaction($user, ['type' => 'expense', 'account_id' => $eur->id, 'amount' => 13.70, 'description' => 'Lunch']);
    $transfer = $service->createTransaction($user, ['type' => 'transfer', 'account_id' => $eur->id, 'to_account_id' => $ron->id, 'amount' => 20, 'to_amount' => 99.9]);

    $found = fn (string $term) => actingAs($user)->get(route('transactions.index', ['search' => $term]))
        ->viewData('page')['props']['transactions']['data'];

    expect(collect($found('13.70'))->pluck('id')->all())->toBe([$expense->id])
        ->and(collect($found('13.7'))->pluck('id')->all())->toBe([$expense->id])
        ->and($found('13'))->toBe([])
        ->and(collect($found('99.90'))->pluck('id')->all())->toBe([$transfer->id])
        ->and(collect($found('Lunch'))->pluck('id')->all())->toBe([$expense->id]);
});

test('an account page lists its own transactions and the transfers it received', function () {
    $user = User::factory()->create();
    $source = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $destination = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 0]);
    $other = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 0]);
    $service = app(TransactionService::class);

    $transfer = $service->createTransaction($user, ['type' => 'transfer', 'account_id' => $source->id, 'to_account_id' => $destination->id, 'amount' => 25]);
    $service->createTransaction($user, ['type' => 'income', 'account_id' => $destination->id, 'amount' => 5]);
    $service->createTransaction($user, ['type' => 'income', 'account_id' => $other->id, 'amount' => 5]);

    actingAs($user)->get(route('accounts.show', $destination))
        ->assertInertia(fn ($page) => $page
            ->has('account.transactions', 2)
            ->where('account.transactions.1.id', $transfer->id)
            ->where('account.transactions.1.to_account.name', $destination->name));
});
