<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;

test('marking a payment as paid books the planned amount today through the ledger', function () {
    $this->travelTo('2026-05-10 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-05-01', 'installments' => 1, 'amount' => 100]);
    $payment = $rule->payments()->first();

    actingAs($user)->patch(route('payments.mark-paid', $payment))->assertRedirect();

    $transaction = Transaction::first();

    expect($payment->fresh()->transaction_id)->toBe($transaction->id)
        ->and($payment->fresh()->isPaid())->toBeTrue()
        ->and($transaction)->type->value->toBe('expense')->amount->toBe('100.00')->recurring_payment_id->toBe($rule->id)
        ->and($transaction->date->toDateString())->toBe('2026-05-10')
        ->and($rule->account->fresh()->balance)->toBe('900.00');
});

test('a payment can be paid with another amount, date and account', function () {
    $this->travelTo('2026-05-10 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-05-01', 'installments' => 1, 'amount' => 13.70]);
    $cash = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 50]);
    $payment = $rule->payments()->first();

    actingAs($user)->patch(route('payments.mark-paid', $payment), ['amount' => 14, 'date' => '2026-05-03', 'account_id' => $cash->id])->assertRedirect();

    expect($cash->fresh()->balance)->toBe('36.00')
        ->and($rule->account->fresh()->balance)->toBe('1000.00')
        ->and($payment->fresh()->amount)->toBe('13.70')
        ->and(Transaction::first())->amount->toBe('14.00')->account_id->toBe($cash->id)
        ->and(Transaction::first()->date->toDateString())->toBe('2026-05-03');
});

test('an income payment books an income', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 0]);
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);
    $rule = plannedPayment($user, ['category_id' => $salary->id, 'installments' => 1, 'amount' => 2000], $account);

    actingAs($user)->patch(route('payments.mark-paid', $rule->payments()->first()))->assertRedirect();

    expect($account->fresh()->balance)->toBe('2000.00');
});

test('deleting the transaction re-opens the payment', function (string $how) {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 1, 'amount' => 100]);
    $payment = $rule->payments()->first();
    actingAs($user)->patch(route('payments.mark-paid', $payment))->assertRedirect();

    $transaction = Transaction::first();
    match ($how) {
        'list' => actingAs($user)->delete(route('transactions.destroy', $transaction))->assertRedirect(),
        'unpay' => actingAs($user)->patch(route('payments.mark-unpaid', $payment))->assertRedirect(),
        'force' => $transaction->forceDelete(),
    };

    expect($payment->fresh()->transaction_id)->toBeNull()
        ->and($payment->fresh()->isPending())->toBeTrue()
        ->and($rule->account->fresh()->balance)->toBe('1000.00');
})->with(['list', 'unpay', 'force']);

test('a payment can only be paid once and a skipped one cannot be paid', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 2]);
    [$first, $second] = $rule->payments()->orderBy('occurrence')->get()->all();

    actingAs($user)->patch(route('payments.mark-paid', $first))->assertRedirect();
    actingAs($user)->patch(route('payments.mark-paid', $first))->assertSessionHasErrors('payment');
    actingAs($user)->patch(route('payments.skip', $second))->assertRedirect();
    actingAs($user)->patch(route('payments.mark-paid', $second))->assertSessionHasErrors('payment');

    expect(Transaction::count())->toBe(1)
        ->and($rule->account->fresh()->balance)->toBe('990.00');
});

test('an occurrence can be edited and is then marked as overridden', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 2]);
    $payment = $rule->payments()->first();

    actingAs($user)->patch(route('payments.update', $payment), ['amount' => 55.5, 'due_date' => '2027-01-02', 'account_id' => $rule->account_id, 'description' => null])->assertRedirect();

    expect($payment->fresh())->amount->toBe('55.50')->is_overridden->toBeTrue()->description->toBeNull()
        ->and($payment->fresh()->due_date->toDateString())->toBe('2027-01-02');

    actingAs($user)->patch(route('payments.update', $payment), ['amount' => 0, 'due_date' => '2027-01-02', 'account_id' => $rule->account_id])
        ->assertSessionHasErrors('amount');
});

test('a payment that runs once can be deleted with its rule but a repeating one has to be skipped', function () {
    $user = User::factory()->create();
    $once = plannedPayment($user, ['installments' => 1]);
    $repeating = plannedPayment($user, ['installments' => 3]);

    actingAs($user)->delete(route('payments.destroy', $repeating->payments()->first()))->assertSessionHasErrors('payment');
    expect($repeating->payments()->count())->toBe(3);

    actingAs($user)->delete(route('payments.destroy', $once->payments()->first()))->assertRedirect(route('payments.index'));
    expect(RecurringPayment::find($once->id))->toBeNull()
        ->and(Payment::where('recurring_payment_id', $once->id)->count())->toBe(0);
});

test('the payments page splits overdue, this period and later and nothing is generated by viewing it', function () {
    $this->travelTo('2026-04-15 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-03-10', 'installments' => 4]);
    $bare = RecurringPayment::factory()->create(['user_id' => $user->id, 'account_id' => $rule->account_id, 'category_id' => $rule->category_id]);
    $before = Payment::count();

    actingAs($user)->get(route('payments.index'))->assertInertia(fn ($page) => $page
        ->has('overduePayments', 2)   // 03-10, 04-10
        ->has('periodPayments', 0)    // period is the calendar month: 05-10 falls later
        ->has('laterPayments', 2)
        ->where('totals.overdue_count', 2)
        ->has('monthlyTotals', 2));
    actingAs($user)->get(route('dashboard'))->assertSuccessful();

    expect(Payment::count())->toBe($before)
        ->and($bare->payments()->count())->toBe(0);
});

test('paid and skipped payments of the period stay visible so they can be undone', function () {
    $this->travelTo('2026-04-15 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-04-20', 'installments' => 3]);
    [$first, $second] = $rule->payments()->orderBy('occurrence')->get()->all();
    actingAs($user)->patch(route('payments.mark-paid', $first))->assertRedirect();
    actingAs($user)->patch(route('payments.skip', $second))->assertRedirect();

    actingAs($user)->get(route('payments.index'))->assertInertia(fn ($page) => $page
        ->has('overduePayments', 0)->has('periodPayments', 1)->has('laterPayments', 2)
        ->where('periodPayments.0.transaction_id', $first->fresh()->transaction_id)
        ->where('laterPayments.0.skipped_at', fn ($skipped) => $skipped !== null));
});

test('another user cannot touch my payments or rules', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 2]);
    $payment = $rule->payments()->first();
    $account = Account::factory()->create(['user_id' => $stranger->id]);

    actingAs($stranger);
    $this->patch(route('payments.update', $payment), ['amount' => 5, 'due_date' => '2027-01-01', 'account_id' => $account->id])->assertForbidden();
    $this->delete(route('payments.destroy', $payment))->assertForbidden();
    $this->patch(route('payments.skip', $payment))->assertForbidden();
    $this->patch(route('payments.unskip', $payment))->assertForbidden();
    $this->patch(route('payments.mark-paid', $payment))->assertForbidden();
    $this->patch(route('payments.mark-unpaid', $payment))->assertForbidden();
    $this->get(route('recurring-payments.show', $rule))->assertForbidden();
    $this->put(route('recurring-payments.update', $rule), [])->assertForbidden();
    $this->delete(route('recurring-payments.destroy', $rule))->assertForbidden();

    // ...and cannot book into someone else's account.
    actingAs($user)->patch(route('payments.mark-paid', $payment), ['account_id' => $account->id])->assertSessionHasErrors('account_id');
    actingAs($user)->patch(route('payments.update', $payment), ['amount' => 5, 'due_date' => '2027-01-01', 'account_id' => $account->id])->assertSessionHasErrors('account_id');

    expect($payment->fresh()->isPending())->toBeTrue();
});

test('the standalone payment creation route is gone', function () {
    expect(Route::has('payments.store'))->toBeFalse();
});
