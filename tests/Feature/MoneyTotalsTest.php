<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/**
 * Today is 2026-04-15, so the period is April. 1 EUR = 5 RON.
 *
 * Balances: EUR 1890, RON 300 (= 60 EUR), savings 270. Available 1950.
 * Payments: overdue 100, in the period 50 + 100 RON (= 20), later 80 (June).
 * Left out of the totals: a skipped one, a paid one, an income.
 */
function moneyScenario(): User
{
    test()->travelTo('2026-04-15 12:00:00');
    Http::fake(['*' => Http::response(['eur' => ['ron' => 5.0], 'ron' => ['eur' => 0.2]])]);

    $user = User::factory()->create();
    $eur = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 500]);
    $savings = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 300, 'is_savings' => true]);
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Food']);
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'name' => 'Salary']);
    $bills = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Bills']);

    $transactions = app(TransactionService::class);
    $book = fn (string $type, Account $account, float $amount, Category $category, array $extra = []) => $transactions->createTransaction($user, [
        'type' => $type, 'account_id' => $account->id, 'category_id' => $category->id, 'amount' => $amount, 'date' => '2026-04-12', ...$extra,
    ]);
    $book('income', $eur, 1000, $salary);
    $book('expense', $eur, 100, $food);
    $book('expense', $ron, 250, $food);
    $transactions->createTransaction($user, ['type' => 'transfer', 'account_id' => $eur->id, 'to_account_id' => $ron->id, 'amount' => 10, 'to_amount' => 50, 'date' => '2026-04-13']);

    $payments = app(PaymentService::class);
    $plan = fn (array $overrides, ?Account $account = null) => plannedPayment($user, ['installments' => 1, ...$overrides], $account ?? $eur)->payments()->first();

    $plan(['start_date' => '2026-04-10', 'amount' => 100, 'category_id' => $food->id]);
    $payments->skip($plan(['start_date' => '2026-04-05', 'amount' => 40]));
    $plan(['start_date' => '2026-04-20', 'amount' => 50]);
    $plan(['start_date' => '2026-04-25', 'amount' => 100], $ron);
    $plan(['start_date' => '2026-04-22', 'amount' => 200, 'category_id' => $salary->id]);
    $plan(['start_date' => '2026-06-10', 'amount' => 80]);
    $payments->markAsPaid($plan(['start_date' => '2026-04-12', 'amount' => 30, 'category_id' => $bills->id]), ['account_id' => $savings->id, 'date' => '2026-04-12']);

    return $user;
}

test('the payments page totals count what is still owed, in the primary currency', function () {
    $user = moneyScenario();

    $props = actingAs($user)->get(route('payments.index'))->viewData('page')['props'];

    expect($props['totals'])->toMatchArray([
        'available_balance' => 1950.0,
        'overdue_amount' => 100.0,
        'overdue_count' => 1,
        'period_amount' => 70.0,
        'upcoming_amount' => 150.0,
        'upcoming_count' => 4,
        'balance_after_planned' => 1780.0,
        'primary_currency' => 'EUR',
    ])
        ->and($props['monthlyTotals'])->toBe(['2026-06' => 80.0])
        ->and($props['overduePayments'])->toHaveCount(1)
        ->and($props['periodPayments'])->toHaveCount(5) // 3 pending + the paid and the skipped one, so they can be undone
        ->and($props['laterPayments'])->toHaveCount(1);
});

test('the dashboard adds up the period in the primary currency', function () {
    $user = moneyScenario();

    $props = actingAs($user)->get(route('dashboard'))->viewData('page')['props'];

    expect($props['summary'])->toMatchArray(['income' => 1000.0, 'expenses' => 180.0, 'net' => 820.0, 'transaction_count' => 5])
        ->and($props['availableBalance'])->toBe(1950.0)
        ->and($props['totalBalance'])->toBe(2220.0)
        ->and($props['savingsBalance'])->toBe(270.0)
        ->and($props['paymentSummary'])->toBe(['total_due' => 170.0, 'primary_currency' => 'EUR'])
        ->and($props['overduePayments'])->toHaveCount(1)
        ->and($props['upcomingPayments'])->toHaveCount(3)
        ->and(collect($props['categorySpending'])->mapWithKeys(fn ($row) => [$row['category']['name'] => [$row['total'], $row['count']]])->all())
        ->toBe(['Food' => [150.0, 2], 'Bills' => [30.0, 1]]);
});

test('the dashboard reads the period with aggregate queries, not row by row', function () {
    $user = moneyScenario();

    DB::enableQueryLog();
    actingAs($user)->get(route('dashboard'));
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'from "transactions"'));

    expect($selects)->not->toBeEmpty()
        ->and($selects->every(fn (string $sql) => str_contains($sql, 'sum(amount)')))->toBeTrue();
});
