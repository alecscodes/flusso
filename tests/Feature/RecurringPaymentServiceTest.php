<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Services\AccountService;
use App\Services\PaymentService;
use App\Services\RecurringPaymentService;

use function Pest\Laravel\actingAs;

function dueDates(RecurringPayment $rule): array
{
    return $rule->payments()->orderBy('occurrence')->get()->map(fn (Payment $payment) => $payment->due_date->toDateString())->all();
}

test('an installment plan is generated up front and editing it changes the unpaid payments from today on', function () {
    $this->travelTo('2026-04-15 12:00:00');
    $user = User::factory()->create();

    $rule = plannedPayment($user, ['start_date' => '2026-03-10', 'installments' => 4, 'amount' => 10]);

    expect(dueDates($rule))->toBe(['2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10']);

    app(RecurringPaymentService::class)->updateRecurringPayment($rule, [
        'name' => 'Rent', 'account_id' => $rule->account_id, 'category_id' => $rule->category_id, 'amount' => 12,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => '2026-03-10', 'installments' => 3,
    ]);

    // The two past-due ones keep their values; the future one follows the rule; the 4th is gone.
    expect($rule->payments()->orderBy('occurrence')->pluck('amount')->all())->toBe(['10.00', '10.00', '12.00'])
        ->and(dueDates($rule))->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

test('shortening the end date deletes the extra unpaid payments and clearing it restores them', function () {
    $this->travelTo('2026-01-15 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-01-20']);
    $payload = fn (array $end) => [
        'name' => 'Rent', 'account_id' => $rule->account_id, 'category_id' => $rule->category_id, 'amount' => 10,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => '2026-01-20', ...$end,
    ];

    expect($rule->payments()->count())->toBe(12);

    app(RecurringPaymentService::class)->updateRecurringPayment($rule, $payload(['end_date' => '2026-03-31']));
    expect(dueDates($rule))->toBe(['2026-01-20', '2026-02-20', '2026-03-20']);

    app(RecurringPaymentService::class)->updateRecurringPayment($rule, $payload([]));
    expect($rule->fresh()->end_date)->toBeNull()
        ->and($rule->payments()->count())->toBe(12);
});

test('a rule without an end is scheduled a year ahead and the daily command extends it', function () {
    $this->travelTo('2026-01-15 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-01-15']);

    expect($rule->payments()->count())->toBe(13)
        ->and(dueDates($rule)[12])->toBe('2027-01-15');

    $this->travelTo('2026-03-01 08:00:00');
    $this->artisan('payments:sync')->assertSuccessful();

    expect($rule->payments()->count())->toBe(14)
        ->and(dueDates($rule)[13])->toBe('2027-02-15');

    $this->artisan('payments:sync')->assertSuccessful();
    expect($rule->payments()->count())->toBe(14);
});

test('month end dates never drift', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();

    $rule = plannedPayment($user, ['start_date' => '2026-01-31', 'installments' => 5]);

    expect(dueDates($rule))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']);

    $yearly = plannedPayment($user, ['start_date' => '2024-02-29', 'interval_type' => 'years', 'installments' => 3]);
    expect(dueDates($yearly))->toBe(['2024-02-29', '2025-02-28', '2026-02-28']);
});

test('days and weeks intervals count from the start date', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();

    expect(dueDates(plannedPayment($user, ['start_date' => '2026-01-01', 'interval_type' => 'weeks', 'interval_value' => 2, 'installments' => 3])))
        ->toBe(['2026-01-01', '2026-01-15', '2026-01-29'])
        ->and(dueDates(plannedPayment($user, ['start_date' => '2026-01-01', 'interval_type' => 'days', 'interval_value' => 10, 'installments' => 3])))
        ->toBe(['2026-01-01', '2026-01-11', '2026-01-21']);
});

test('an occurrence edited by hand keeps its values when the rule changes', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-02-01', 'installments' => 3, 'amount' => 13.70]);
    $edited = $rule->payments()->where('occurrence', 1)->first();

    actingAs($user)->patch(route('payments.update', $edited), [
        'amount' => 14, 'due_date' => '2026-03-05', 'account_id' => $rule->account_id, 'description' => 'Rounded up',
    ])->assertRedirect();

    $newCategory = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);
    app(RecurringPaymentService::class)->updateRecurringPayment($rule, [
        'name' => 'Salary', 'account_id' => $rule->account_id, 'category_id' => $newCategory->id, 'amount' => 20,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => '2026-02-01', 'installments' => 3,
    ]);

    $payments = $rule->payments()->orderBy('occurrence')->get();

    expect($payments->pluck('amount')->all())->toBe(['20.00', '14.00', '20.00'])
        ->and($payments[1])->is_overridden->toBeTrue()->due_date->toDateString()->toBe('2026-03-05')->description->toBe('Rounded up')
        ->and($payments->pluck('type.value')->unique()->all())->toBe(['income']) // type follows the category
        ->and($payments[0]->description)->toBe('Salary');
});

test('a skipped occurrence is not created again and unskipping restores it', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-02-01', 'installments' => 3]);
    $second = $rule->payments()->where('occurrence', 1)->first();

    actingAs($user)->patch(route('payments.skip', $second))->assertRedirect();
    app(RecurringPaymentService::class)->sync($rule);

    expect($rule->payments()->count())->toBe(3)
        ->and($second->fresh()->skipped_at)->not->toBeNull()
        ->and(Payment::pending()->where('recurring_payment_id', $rule->id)->count())->toBe(2);

    actingAs($user)->patch(route('payments.unskip', $second))->assertRedirect();

    expect($second->fresh()->skipped_at)->toBeNull();
});

test('paying the last installment finishes the rule and it leaves the active list', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-01-01', 'installments' => 2]);
    $other = plannedPayment($user, ['name' => 'Forever', 'start_date' => '2026-01-01']);

    $statuses = fn () => collect(actingAs($user)->get(route('recurring-payments.index'))->viewData('page')['props']['recurringPayments'])
        ->pluck('status', 'name')->all();

    expect($statuses())->toEqual(['Rent' => 'active', 'Forever' => 'active']);

    foreach ($rule->payments()->get() as $payment) {
        actingAs($user)->patch(route('payments.mark-paid', $payment))->assertRedirect();
    }

    expect($statuses())->toEqual(['Rent' => 'finished', 'Forever' => 'active']);
});

test('a skipped last installment also finishes the rule', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-01-01', 'installments' => 1]);

    app(PaymentService::class)->skip($rule->payments()->first());

    expect($rule->fresh(['payments', 'account'])->status)->toBe('finished');
});

test('pausing removes only the future unpaid payments and resuming brings them back', function () {
    $this->travelTo('2026-04-15 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-03-10', 'installments' => 5]);
    $paid = $rule->payments()->where('occurrence', 0)->first();
    actingAs($user)->patch(route('payments.mark-paid', $paid))->assertRedirect();

    $payload = fn (bool $active) => [
        'name' => 'Rent', 'account_id' => $rule->account_id, 'category_id' => $rule->category_id, 'amount' => 10,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => '2026-03-10', 'installments' => 5, 'is_active' => $active,
    ];

    app(RecurringPaymentService::class)->updateRecurringPayment($rule, $payload(false));

    // paid (03-10) and past-due unpaid (04-10) stay; 05-10, 06-10, 07-10 are gone.
    expect(dueDates($rule))->toBe(['2026-03-10', '2026-04-10'])
        ->and($rule->fresh(['payments', 'account'])->status)->toBe('paused');

    app(RecurringPaymentService::class)->updateRecurringPayment($rule, $payload(true));

    expect(dueDates($rule))->toBe(['2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10']);
});

test('a rule on an archived account generates nothing and its leftovers never show as upcoming or overdue', function () {
    $this->travelTo('2026-04-15 12:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $rule = plannedPayment($user, ['start_date' => '2026-03-10'], $account);
    $pastDue = $rule->payments()->where('occurrence', 0)->first();

    // Archive without the service so a past-due row and an active rule are left behind.
    $account->delete();
    app(RecurringPaymentService::class)->sync($rule);

    expect($rule->fresh(['payments', 'account'])->status)->toBe('paused')
        ->and($rule->payments()->where('due_date', '>=', '2026-04-15')->count())->toBe(0)
        ->and(Payment::visible()->count())->toBe(0);

    actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->has('overduePayments', 0)->has('upcomingPayments', 0));
    actingAs($user)->get(route('payments.index'))->assertInertia(fn ($page) => $page
        ->has('overduePayments', 0)->has('periodPayments', 0)->has('laterPayments', 0));

    // Archiving through the service clears what is still owed.
    $other = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $second = plannedPayment($user, ['start_date' => '2026-03-10'], $other);
    app(AccountService::class)->deleteAccount($other);

    expect($second->fresh()->is_active)->toBeFalse()
        ->and($second->payments()->count())->toBe(0)
        ->and($pastDue->exists)->toBeTrue();
});

test('a deleted rule keeps its paid history and loses what is still owed', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-01-01', 'installments' => 3]);
    actingAs($user)->patch(route('payments.mark-paid', $rule->payments()->where('occurrence', 0)->first()))->assertRedirect();

    actingAs($user)->delete(route('recurring-payments.destroy', $rule))->assertRedirect(route('recurring-payments.index'));

    expect(RecurringPayment::find($rule->id))->toBeNull()
        ->and(Payment::where('recurring_payment_id', $rule->id)->count())->toBe(1)
        ->and(Payment::visible()->count())->toBe(0)
        ->and(Payment::first()->recurringPayment->name)->toBe('Rent');

    $this->artisan('payments:sync')->assertSuccessful();
    expect(Payment::where('recurring_payment_id', $rule->id)->count())->toBe(1);
});

test('currency follows the account', function () {
    $user = User::factory()->create();
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON']);

    $rule = plannedPayment($user, ['installments' => 1], $ron);

    expect($rule->currency)->toBe('RON')
        ->and($rule->payments()->first()->currency)->toBe('RON');
});

test('statistics count paid, pending and the next due date', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['start_date' => '2026-01-01', 'installments' => 3, 'amount' => 20]);
    actingAs($user)->patch(route('payments.mark-paid', $rule->payments()->where('occurrence', 0)->first()), ['amount' => 25])->assertRedirect();

    $stats = app(RecurringPaymentService::class)->getStatistics($rule);

    expect($stats)->toMatchArray(['total_payments' => 3, 'paid_payments' => 1, 'unpaid_payments' => 2, 'next_due_date' => '2026-02-01'])
        ->and(round($stats['total_paid_amount'], 2))->toBe(25.0);
});

test('a rule with an end date is only finished when no date is left before it ends', function () {
    $this->travelTo('2026-01-01 12:00:00');
    $user = User::factory()->create();
    $yearly = plannedPayment($user, ['start_date' => '2026-01-01', 'interval_type' => 'years', 'end_date' => '2029-06-01']);
    $short = plannedPayment($user, ['start_date' => '2026-01-01', 'interval_type' => 'years', 'end_date' => '2027-06-01']);

    // Only the first two years are scheduled so far (a year ahead), but 2028 and 2029 are still to come.
    expect($yearly->payments()->count())->toBe(2);

    foreach ([$yearly, $short] as $rule) {
        foreach ($rule->payments()->get() as $payment) {
            actingAs($user)->patch(route('payments.mark-paid', $payment))->assertRedirect();
        }
    }

    expect($yearly->fresh(['payments', 'account'])->status)->toBe('active')
        ->and($short->fresh(['payments', 'account'])->status)->toBe('finished');
});
