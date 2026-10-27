<?php

use App\Models\Account;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

test('a transaction on the last day of the period is counted in the dashboard and the date_end filter', function () {
    $this->travelTo('2026-02-10 12:00:00');

    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);

    foreach (['2026-01-31' => 1, '2026-02-01' => 10, '2026-02-28' => 100, '2026-03-01' => 1000] as $date => $amount) {
        actingAs($user)->post(route('transactions.store'), [
            'type' => 'expense', 'account_id' => $account->id, 'amount' => $amount, 'date' => $date,
        ])->assertRedirect();
    }

    // Stored as a plain date, so string comparison against 'Y-m-d' bounds includes the whole day.
    expect(DB::table('transactions')->pluck('date')->all())->each->toHaveLength(10);

    actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('period.start', '2026-02-01')
            ->where('period.end', '2026-02-28')
            ->where('summary.expenses', 110)
            ->where('summary.transaction_count', 2)
        );

    actingAs($user)->get(route('transactions.index', ['date_start' => '2026-02-01', 'date_end' => '2026-02-28']))
        ->assertInertia(fn ($page) => $page->where('transactions.total', 2));

    actingAs($user)->get(route('transactions.index', ['date_end' => '2026-02-28']))
        ->assertInertia(fn ($page) => $page->where('transactions.total', 3));
});

test('dates are persisted as plain Y-m-d whatever shape they are assigned in', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    foreach (['2026-05-11', '2026-05-11 00:00:00', now()->setDate(2026, 5, 11)->setTime(15, 30)] as $value) {
        $transaction = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'date' => $value]);

        expect(DB::table('transactions')->where('id', $transaction->id)->value('date'))->toBe('2026-05-11')
            ->and($transaction->fresh()->date->toDateString())->toBe('2026-05-11')
            ->and($transaction->fresh()->toArray()['date'])->toBe('2026-05-11');
    }
});

test('payments due on the last day of the period are included in the period summary', function () {
    $this->travelTo('2026-02-10 12:00:00');

    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

    Payment::factory()->create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'type' => 'expense', 'amount' => 40, 'due_date' => '2026-02-28',
    ]);

    actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('paymentSummary.total_due', 40));
});

test('the financial period follows the reset day and clamps it to short months', function (string $today, ?int $resetDate, string $start, string $end) {
    $this->travelTo($today);

    $user = User::factory()->make(['reset_date' => $resetDate]);
    $period = $user->getFinancialPeriod();

    expect($period['start']->toDateString())->toBe($start)
        ->and($period['end']->toDateString())->toBe($end)
        ->and($period['end']->format('H:i:s'))->toBe('23:59:59');
})->with([
    'calendar month' => ['2026-10-27', null, '2026-10-01', '2026-10-31'],
    'before the reset day' => ['2026-10-27', 28, '2026-09-28', '2026-10-27'],
    'on the reset day' => ['2026-10-28', 28, '2026-10-28', '2026-11-27'],
    '31st in a non-leap February' => ['2026-02-28', 31, '2026-02-28', '2026-03-30'],
    '31st, early March' => ['2026-03-10', 31, '2026-02-28', '2026-03-30'],
    '31st, mid April' => ['2026-04-15', 31, '2026-03-31', '2026-04-29'],
    '30th in a leap February' => ['2028-02-29', 30, '2028-02-29', '2028-03-29'],
]);
