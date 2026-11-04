<?php

use App\Enums\SuggestionDismissalKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\DismissedRecurringSuggestion;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RecurringPaymentService;
use App\Services\RecurringSuggestionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;

beforeEach(fn () => $this->travelTo('2026-06-15 12:00:00'));

/**
 * Pays an occurrence; the service reads the owner, which lazy loading would otherwise refuse.
 *
 * @param  array<string, mixed>  $data
 */
function subscriptionsPay(Payment $payment, array $data = []): void
{
    app(PaymentService::class)->markAsPaid($payment->loadMissing('user'), $data);
}

/**
 * @return array{0: User, 1: Account, 2: Category}
 */
function subscriptionsSetup(string $currency = 'EUR'): array
{
    $user = User::factory()->create();

    return [
        $user,
        Account::factory()->create(['user_id' => $user->id, 'currency' => $currency, 'balance' => 1000]),
        Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Software']),
    ];
}

/**
 * Books one expense per date.
 *
 * @param  array<int, string>  $dates
 * @param  array<string, mixed>  $overrides
 */
function subscriptionsCharge(User $user, Account $account, ?Category $category, string $description, float|array $amount, array $dates, array $overrides = []): void
{
    foreach ($dates as $index => $date) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'type' => 'expense',
            'amount' => is_array($amount) ? $amount[$index] : $amount,
            'description' => $description,
            'date' => $date,
            ...$overrides,
        ]);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function subscriptionsFor(User $user): array
{
    return app(RecurringSuggestionService::class)->suggestionsFor($user)->all();
}

test('a monthly charge is suggested with its latest amount, category and next expected date', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Netflix', [12.99, 12.99, 13.49], ['2026-03-20', '2026-04-20', '2026-05-20']);

    $suggestions = subscriptionsFor($user);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0])->toMatchArray([
            'key' => 'netflix',
            'label' => 'Netflix',
            'interval_type' => 'months',
            'interval_value' => 1,
            'amount' => '13.49',
            'previous_amount' => '12.99',
            'currency' => 'EUR',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'occurrences' => 3,
            'last_date' => '2026-05-20',
            'next_expected' => '2026-06-20',
            'price_jumped' => false,
        ]);
});

test('descriptions that differ only in case, diacritics and punctuation are one merchant', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Spotify', 9.99, ['2026-04-10']);
    subscriptionsCharge($user, $account, $category, 'SPOTIFY!', 9.99, ['2026-05-10']);

    expect(subscriptionsFor($user))->toHaveCount(1);
});

test('a yearly charge is suggested', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Github', 40, ['2025-06-10', '2026-06-10']);

    $suggestions = subscriptionsFor($user);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0])->toMatchArray(['interval_type' => 'years', 'next_expected' => '2027-06-10']);
});

test('a weekly charge needs three occurrences', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Pizza night', 15, ['2026-06-01', '2026-06-08']);
    subscriptionsCharge($user, $account, $category, 'Cleaner', 30, ['2026-05-25', '2026-06-01', '2026-06-08']);

    $suggestions = subscriptionsFor($user);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0])->toMatchArray(['key' => 'cleaner', 'interval_type' => 'weeks', 'next_expected' => '2026-06-15']);
});

test('gaps of 28 to 35 days count as monthly and anything else does not', function (string $secondDate, bool $suggested) {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Hosting', 10, ['2026-05-01', $secondDate]);

    expect(subscriptionsFor($user))->toHaveCount($suggested ? 1 : 0);
})->with([
    'too short' => ['2026-05-28', false],
    'shortest' => ['2026-05-29', true],
    'longest' => ['2026-06-05', true],
    'too long' => ['2026-06-06', false],
]);

test('every gap has to agree, not only the average', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Gym', 30, ['2026-03-01', '2026-04-01', '2026-05-05']);

    expect(subscriptionsFor($user))->toHaveCount(1);

    subscriptionsCharge($user, $account, $category, 'Irregular', 30, ['2026-03-01', '2026-04-01', '2026-04-10']);

    expect(subscriptionsFor($user))->toHaveCount(1);
});

test('amounts may differ by 15 percent but not more', function (float $second, bool $suggested) {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Cloud', [10, $second], ['2026-04-20', '2026-05-20']);

    expect(subscriptionsFor($user))->toHaveCount($suggested ? 1 : 0);
})->with([
    'same' => [10.0, true],
    'a bit more' => [11.4, true],
    'a bit less' => [8.7, true],
    'too much more' => [11.7, false],
    'twice as much' => [20.0, false],
]);

test('one-off charges, income and transfers are not suggested', function () {
    [$user, $account, $category] = subscriptionsSetup();
    $other = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    subscriptionsCharge($user, $account, $category, 'Dentist', 80, ['2026-05-20']);
    subscriptionsCharge($user, $account, $category, 'Salary', 2000, ['2026-04-20', '2026-05-20'], ['type' => 'income']);
    Transaction::factory()->transfer($other)->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 50, 'description' => 'Savings', 'date' => '2026-05-20']);
    Transaction::factory()->transfer($other)->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 50, 'description' => 'Savings', 'date' => '2026-04-20']);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('a charge without a description has no merchant to suggest', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, '', 10, ['2026-04-20', '2026-05-20']);
    Transaction::query()->update(['description' => null]);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('a service that stopped charging is no longer suggested', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Old tool', 10, ['2026-01-10', '2026-02-10', '2026-03-10']);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('charges older than 13 months are not looked at', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Ancient', 10, ['2025-04-01', '2025-05-01']);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('transactions booked by a planned payment are ignored', function () {
    [$user, $account, $category] = subscriptionsSetup();
    $rule = plannedPayment($user, ['name' => 'Gym membership', 'start_date' => '2026-06-20']);
    subscriptionsCharge($user, $account, $category, 'Fitness club', 30, ['2026-04-20', '2026-05-20'], ['recurring_payment_id' => $rule->id]);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('a merchant that already is a planned payment is not suggested', function () {
    [$user, $account, $category] = subscriptionsSetup();
    plannedPayment($user, ['name' => 'Netflix', 'start_date' => '2026-06-20']);
    subscriptionsCharge($user, $account, $category, 'NETFLIX.com', 12, ['2026-04-20', '2026-05-20']);
    subscriptionsCharge($user, $account, $category, 'Cursor', 20, ['2026-04-21', '2026-05-21']);

    $suggestions = subscriptionsFor($user);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['key'])->toBe('cursor');
});

test('amounts stay in the currency of their account', function () {
    [$user, $account, $category] = subscriptionsSetup('RON');
    subscriptionsCharge($user, $account, $category, 'Digi', 45.5, ['2026-04-12', '2026-05-12']);

    expect(subscriptionsFor($user)[0])->toMatchArray(['currency' => 'RON', 'amount' => '45.50']);
});

test('the same merchant on two accounts is judged per account', function () {
    [$user, $account, $category] = subscriptionsSetup();
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON']);
    subscriptionsCharge($user, $account, $category, 'Hosting', 10, ['2026-04-20', '2026-05-20']);
    subscriptionsCharge($user, $ron, $category, 'Hosting', 50, ['2026-04-20', '2026-05-20']);

    expect(collect(subscriptionsFor($user))->pluck('currency')->sort()->values()->all())->toBe(['EUR', 'RON']);
});

test('the most used category is suggested', function () {
    [$user, $account, $category] = subscriptionsSetup();
    $fun = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Fun']);
    subscriptionsCharge($user, $account, $fun, 'Tool', 10, ['2026-03-20']);
    subscriptionsCharge($user, $account, $category, 'Tool', 10, ['2026-04-20', '2026-05-20']);

    expect(subscriptionsFor($user)[0]['category_id'])->toBe($category->id);
});

test('a renewal at three times the price is flagged even though the amounts differ', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'wedev domain', [2, 20], ['2025-06-10', '2026-06-10']);

    $suggestions = subscriptionsFor($user);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0])->toMatchArray(['price_jumped' => true, 'amount' => '20.00', 'previous_amount' => '2.00', 'interval_type' => 'years']);
});

test('a monthly charge that triples is flagged but a mere increase is not', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Jumper', [10, 10, 30], ['2026-03-20', '2026-04-20', '2026-05-20']);
    subscriptionsCharge($user, $account, $category, 'Climber', [10, 10, 29], ['2026-03-21', '2026-04-21', '2026-05-21']);

    $suggestions = collect(subscriptionsFor($user))->keyBy('key');

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions['jumper']['price_jumped'])->toBeTrue();
});

test('the same charge only jumps when the earlier ones agree', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Messy', [5, 10, 40], ['2026-03-20', '2026-04-20', '2026-05-20']);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('a dismissed as not recurring merchant stays hidden', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Cursor', 20, ['2026-04-20', '2026-05-20']);

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'not_recurring'])->assertRedirect();

    expect(subscriptionsFor($user))->toBeEmpty();

    $this->travelTo('2026-06-30 12:00:00');
    subscriptionsCharge($user, $account, $category, 'Cursor', 20, ['2026-06-20']);

    expect(subscriptionsFor($user))->toBeEmpty();
});

test('a temporary dismissal hides the merchant until the snooze ends', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Elevenlabs', 22, ['2026-04-20', '2026-05-20']);
    DismissedRecurringSuggestion::factory()->temporary('2026-06-20')->create(['user_id' => $user->id, 'normalized_key' => 'elevenlabs']);

    expect(subscriptionsFor($user))->toBeEmpty();

    $this->travelTo('2026-06-19 23:00:00');
    expect(subscriptionsFor($user))->toBeEmpty();

    $this->travelTo('2026-06-20 08:00:00');
    expect(subscriptionsFor($user))->toHaveCount(1);
});

test('a temporary dismissal hides for six months by default', function () {
    [$user] = subscriptionsSetup();

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'elevenlabs', 'kind' => 'temporary'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message');

    $dismissal = DismissedRecurringSuggestion::firstOrFail();

    expect($dismissal->kind)->toBe(SuggestionDismissalKind::Temporary)
        ->and($dismissal->snooze_until->toDateString())->toBe('2026-12-15')
        ->and($dismissal->normalized_key)->toBe('elevenlabs');
});

test('a temporary dismissal can name the day it ends', function () {
    [$user] = subscriptionsSetup();

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'temporary', 'snooze_until' => '2026-09-01'])->assertRedirect();

    expect(DismissedRecurringSuggestion::firstOrFail()->snooze_until->toDateString())->toBe('2026-09-01');
});

test('dismissing again changes the existing dismissal instead of adding one', function () {
    [$user] = subscriptionsSetup();

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'temporary'])->assertRedirect();
    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'not_recurring'])->assertRedirect();

    $dismissal = DismissedRecurringSuggestion::firstOrFail();

    expect(DismissedRecurringSuggestion::count())->toBe(1)
        ->and($dismissal->kind)->toBe(SuggestionDismissalKind::NotRecurring)
        ->and($dismissal->snooze_until)->toBeNull();
});

test('a dismissal can be taken back', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Cursor ai', 20, ['2026-04-20', '2026-05-20']);

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor ai', 'kind' => 'not_recurring']);
    expect(subscriptionsFor($user))->toBeEmpty();

    actingAs($user)->delete(route('planned-payments.suggestions.restore', ['key' => 'cursor ai']))->assertRedirect();

    expect(subscriptionsFor($user))->toHaveCount(1)
        ->and(DismissedRecurringSuggestion::count())->toBe(0);
});

test('dismissals are validated', function (array $input, string $invalidField) {
    [$user] = subscriptionsSetup();

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), $input)->assertSessionHasErrors($invalidField);

    expect(DismissedRecurringSuggestion::count())->toBe(0);
})->with([
    'no key' => [['kind' => 'not_recurring'], 'key'],
    'a key that is not normalised' => [['key' => 'Cursor AI', 'kind' => 'not_recurring'], 'key'],
    'a key that is too long' => [['key' => str_repeat('a', 192), 'kind' => 'not_recurring'], 'key'],
    'no kind' => [['key' => 'cursor'], 'kind'],
    'an unknown kind' => [['key' => 'cursor', 'kind' => 'forever'], 'kind'],
    'a snooze in the past' => [['key' => 'cursor', 'kind' => 'temporary', 'snooze_until' => '2026-06-15'], 'snooze_until'],
    'a snooze that is not a date' => [['key' => 'cursor', 'kind' => 'temporary', 'snooze_until' => 'soon'], 'snooze_until'],
]);

test('guests cannot reach the dismissal routes', function () {
    post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'not_recurring'])->assertRedirect(route('login'));
    delete(route('planned-payments.suggestions.restore', ['key' => 'cursor']))->assertRedirect(route('login'));
});

test('the dismissal routes are throttled', function () {
    [$user] = subscriptionsSetup();

    foreach (range(1, 60) as $attempt) {
        actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'not_recurring'])->assertRedirect();
    }

    actingAs($user)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'not_recurring'])->assertStatus(429);
});

test('suggestions and dismissals never cross users', function () {
    [$user, $account, $category] = subscriptionsSetup();
    [$stranger, $strangerAccount, $strangerCategory] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Cursor', 20, ['2026-04-20', '2026-05-20']);
    subscriptionsCharge($stranger, $strangerAccount, $strangerCategory, 'Midjourney', 30, ['2026-04-20', '2026-05-20']);

    expect(collect(subscriptionsFor($user))->pluck('key')->all())->toBe(['cursor'])
        ->and(collect(subscriptionsFor($stranger))->pluck('key')->all())->toBe(['midjourney']);

    actingAs($stranger)->post(route('planned-payments.suggestions.dismiss'), ['key' => 'cursor', 'kind' => 'not_recurring']);

    expect(subscriptionsFor($user))->toHaveCount(1);

    actingAs($user)->delete(route('planned-payments.suggestions.restore', ['key' => 'cursor']))->assertRedirect();

    assertDatabaseHas('dismissed_recurring_suggestions', ['user_id' => $stranger->id, 'normalized_key' => 'cursor']);
    assertDatabaseMissing('dismissed_recurring_suggestions', ['user_id' => $user->id]);
});

test('another user planned payment does not hide a suggestion', function () {
    [$user, $account, $category] = subscriptionsSetup();
    [$stranger] = subscriptionsSetup();
    plannedPayment($stranger, ['name' => 'Cursor', 'start_date' => '2026-06-20']);
    subscriptionsCharge($user, $account, $category, 'Cursor', 20, ['2026-04-20', '2026-05-20']);

    expect(subscriptionsFor($user))->toHaveCount(1);
});

test('the planned payments page loads the suggestions deferred', function () {
    [$user, $account, $category] = subscriptionsSetup();
    subscriptionsCharge($user, $account, $category, 'Cursor', 20, ['2026-04-20', '2026-05-20']);

    actingAs($user)->get(route('planned-payments.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('RecurringPayments/Index')->missing('suggestions'));
});

test('a planned payment whose latest payment cost over 10 percent more or less shows a price change', function (float $paid, bool $changed) {
    [$user] = subscriptionsSetup();
    $rule = plannedPayment($user, ['name' => 'Hosting', 'start_date' => '2026-06-01', 'amount' => 20, 'installments' => 3]);

    subscriptionsPay($rule->payments()->orderBy('occurrence')->first(), ['amount' => $paid]);

    $price = app(RecurringPaymentService::class)->getRecurringPaymentsForUser($user)->first()->price_change;

    expect($price)->toBe($changed ? ['rule_amount' => '20.00', 'last_paid_amount' => number_format($paid, 2, '.', '')] : null);
})->with([
    'the same' => [20.0, false],
    'ten percent more' => [22.0, false],
    'eleven percent more' => [22.2, true],
    'a lot less' => [10.0, true],
]);

test('only the latest paid payment decides the price change', function () {
    [$user] = subscriptionsSetup();
    $rule = plannedPayment($user, ['name' => 'Hosting', 'start_date' => '2026-04-15', 'amount' => 20, 'installments' => 3]);
    $payments = $rule->payments()->orderBy('occurrence')->get();

    subscriptionsPay($payments[0], ['amount' => 30, 'date' => '2026-04-15']);
    subscriptionsPay($payments[1], ['amount' => 20, 'date' => '2026-05-15']);

    expect(app(RecurringPaymentService::class)->getRecurringPaymentsForUser($user)->first()->price_change)->toBeNull();
});

test('a payment made from an account in another currency is not compared', function () {
    [$user] = subscriptionsSetup();
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 1000]);
    $rule = plannedPayment($user, ['name' => 'Hosting', 'start_date' => '2026-06-01', 'amount' => 20, 'installments' => 2]);

    subscriptionsPay($rule->payments()->orderBy('occurrence')->first(), ['amount' => 100, 'account_id' => $ron->id]);

    expect(app(RecurringPaymentService::class)->getRecurringPaymentsForUser($user)->first()->price_change)->toBeNull();
});

test('a paused planned payment shows no price change', function () {
    [$user] = subscriptionsSetup();
    $rule = plannedPayment($user, ['name' => 'Hosting', 'start_date' => '2026-06-01', 'amount' => 20, 'installments' => 2]);
    subscriptionsPay($rule->payments()->orderBy('occurrence')->first(), ['amount' => 40]);
    $rule->update(['is_active' => false]);

    expect(app(RecurringPaymentService::class)->getRecurringPaymentsForUser($user)->first()->price_change)->toBeNull();
});

test('a payment marked unpaid again no longer counts as the latest paid', function () {
    [$user] = subscriptionsSetup();
    $rule = plannedPayment($user, ['name' => 'Hosting', 'start_date' => '2026-06-01', 'amount' => 20, 'installments' => 2]);
    $payment = $rule->payments()->orderBy('occurrence')->first();
    subscriptionsPay($payment, ['amount' => 40]);
    app(PaymentService::class)->markAsUnpaid($payment->fresh());

    expect(app(RecurringPaymentService::class)->getRecurringPaymentsForUser($user)->first()->price_change)->toBeNull();
});

test('the price change reaches the planned payment pages of its owner only', function () {
    [$user] = subscriptionsSetup();
    [$stranger] = subscriptionsSetup();
    $rule = plannedPayment($user, ['name' => 'Hosting', 'start_date' => '2026-06-01', 'amount' => 20, 'installments' => 2]);
    subscriptionsPay($rule->payments()->orderBy('occurrence')->first(), ['amount' => 25]);

    actingAs($user)->get(route('planned-payments.index'))->assertInertia(fn ($page) => $page
        ->where('recurringPayments.0.price_change.rule_amount', '20.00')
        ->where('recurringPayments.0.price_change.last_paid_amount', '25.00'));

    actingAs($user)->get(route('planned-payments.show', $rule))->assertInertia(fn ($page) => $page
        ->where('recurringPayment.price_change.last_paid_amount', '25.00'));

    actingAs($stranger)->get(route('planned-payments.show', $rule))->assertForbidden();
    actingAs($stranger)->get(route('planned-payments.index'))->assertInertia(fn ($page) => $page->has('recurringPayments', 0));
});

test('the dismissed suggestions table can be dropped and created again', function () {
    $migration = require database_path('migrations/2026_11_03_100004_create_dismissed_recurring_suggestions_table.php');

    $migration->down();
    expect(Schema::hasTable('dismissed_recurring_suggestions'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('dismissed_recurring_suggestions'))->toBeTrue()
        ->and(Schema::hasColumns('dismissed_recurring_suggestions', ['user_id', 'normalized_key', 'kind', 'snooze_until']))->toBeTrue();
});

test('one user cannot have two dismissals for the same merchant', function () {
    [$user] = subscriptionsSetup();
    DismissedRecurringSuggestion::factory()->create(['user_id' => $user->id, 'normalized_key' => 'cursor']);

    expect(fn () => DismissedRecurringSuggestion::factory()->create(['user_id' => $user->id, 'normalized_key' => 'cursor']))
        ->toThrow(QueryException::class);
});
