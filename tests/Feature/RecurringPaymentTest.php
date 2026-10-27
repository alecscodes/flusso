<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

function ruleInput(User $user, array $overrides = []): array
{
    return [
        'name' => 'Netflix',
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR'])->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => 'expense'])->id,
        'amount' => 15.99,
        'interval_type' => 'months',
        'interval_value' => 1,
        'start_date' => now()->addDays(3)->toDateString(),
        ...$overrides,
    ];
}

test('a planned payment that runs once is a rule with a single installment', function () {
    $user = User::factory()->create();

    actingAs($user)->from(route('payments.index'))
        ->post(route('planned-payments.store'), ruleInput($user, ['name' => 'Taxe', 'installments' => 1]))
        ->assertRedirect(route('payments.index'));

    $rule = RecurringPayment::where('name', 'Taxe')->first();

    expect($rule)->installments->toBe(1)->end_date->toBeNull()->currency->toBe('EUR')
        ->and($rule->payments()->count())->toBe(1)
        ->and($rule->payments()->first())->occurrence->toBe(0);
});

test('a repeating planned payment is generated a year ahead', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('planned-payments.store'), ruleInput($user))->assertRedirect();

    expect(RecurringPayment::first()->payments()->count())->toBeGreaterThanOrEqual(12);
});

test('it ends never, on a date or after a number of payments but not two at once', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('planned-payments.store'), ruleInput($user, ['end_date' => now()->addMonths(6)->toDateString(), 'installments' => 3]))
        ->assertSessionHasErrors('end_date');
    actingAs($user)->post(route('planned-payments.store'), ruleInput($user, ['end_date' => now()->subDay()->toDateString()]))
        ->assertSessionHasErrors('end_date');

    expect(RecurringPayment::count())->toBe(0);
});

test('editing a rule can clear its end', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 3]);

    actingAs($user)->put(route('planned-payments.update', $rule), [
        'name' => 'Rent', 'account_id' => $rule->account_id, 'category_id' => $rule->category_id, 'amount' => 10,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => $rule->start_date->toDateString(),
        'installments' => null, 'end_date' => null,
    ])->assertRedirect();

    expect($rule->fresh()->installments)->toBeNull()
        ->and($rule->payments()->count())->toBeGreaterThanOrEqual(12);
});

test('the currency cannot be set by the client and accounts and categories must be mine', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $foreignAccount = Account::factory()->create(['user_id' => $stranger->id]);
    $foreignCategory = Category::factory()->create(['user_id' => $stranger->id]);

    actingAs($user)->post(route('planned-payments.store'), ruleInput($user, ['account_id' => $foreignAccount->id, 'category_id' => $foreignCategory->id]))
        ->assertSessionHasErrors(['account_id', 'category_id']);

    actingAs($user)->post(route('planned-payments.store'), ruleInput($user, ['currency' => 'USD']))->assertRedirect();
    expect(RecurringPayment::first()->currency)->toBe('EUR');
});

test('a rule can use an income category', function () {
    $user = User::factory()->create();
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    actingAs($user)->post(route('planned-payments.store'), ruleInput($user, ['category_id' => $salary->id, 'installments' => 1]))->assertRedirect();

    expect(RecurringPayment::first()->payments()->first()->type->value)->toBe('income');
});

test('deleting a transaction booked by a rule leaves the rule alone', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 1]);
    actingAs($user)->patch(route('payments.mark-paid', $rule->payments()->first()))->assertRedirect();

    actingAs($user)->delete(route('transactions.destroy', Transaction::first()))->assertRedirect();

    expect(RecurringPayment::find($rule->id))->not->toBeNull()
        ->and($rule->fresh(['payments', 'account'])->status->value)->toBe('active');
});

test('a planned payment needs Y-m-d dates, a sane amount and a last payment before 2101', function (array $overrides, string $field) {
    $user = User::factory()->create();

    actingAs($user)->post(route('planned-payments.store'), ruleInput($user, $overrides))->assertSessionHasErrors($field);

    expect(RecurringPayment::count())->toBe(0);
})->with([
    'start day first' => [['start_date' => '15/04/2026'], 'start_date'],
    'end with a time' => [['end_date' => '2099-01-01 10:00:00'], 'end_date'],
    'end after 2100' => [['end_date' => '2101-01-01'], 'end_date'],
    'amount too big' => [['amount' => 10000000000], 'amount'],
    'installments run past 2100' => [['start_date' => '2026-04-15', 'interval_type' => 'years', 'interval_value' => 100, 'installments' => 5], 'installments'],
]);

test('a planned payment keeps an archived category while it is edited', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 2]);
    $rule->category->delete();

    actingAs($user)->put(route('planned-payments.update', $rule), [
        'name' => 'Renamed', 'account_id' => $rule->account_id, 'category_id' => $rule->category_id, 'amount' => 10,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => now()->toDateString(), 'installments' => 2,
    ])->assertSessionHasNoErrors();

    expect($rule->fresh()->name)->toBe('Renamed');
});
