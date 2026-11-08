<?php

use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LiquidityAlert;
use App\Services\CurrencyService;
use App\Services\LiquidityService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->travelTo('2026-11-03 12:00:00');
});

/**
 * A planned payment occurrence still owed (its own rule, so due dates never collide).
 *
 * @param  array<string, mixed>  $overrides  Payment attributes.
 */
function liquidityPayment(Account $account, float $amount, string $dueDate, array $overrides = [], bool $ruleActive = true): Payment
{
    $category = Category::factory()->create(['user_id' => $account->user_id, 'type' => 'expense']);
    $rule = RecurringPayment::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Contabilitate',
        'amount' => $amount,
        'start_date' => $dueDate,
        'is_active' => $ruleActive,
    ]);

    return Payment::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'recurring_payment_id' => $rule->id,
        'amount' => $amount,
        'due_date' => $dueDate,
        ...$overrides,
    ]);
}

/**
 * The owner's real situation: 9.74 RON, one payment overdue and two coming up.
 */
function liquidityEnzfox(User $user): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Enzfox', 'currency' => 'RON', 'balance' => 9.74]);
    liquidityPayment($account, 100, '2026-10-25');
    liquidityPayment($account, 100, '2026-11-25');
    liquidityPayment($account, 29, '2026-12-02');

    return $account;
}

function liquidityRow(User $user, int $days = 30, ?string $name = null): array
{
    return app(LiquidityService::class)->forecast($user, $days)->firstWhere('account_name', $name ?? 'Enzfox');
}

test('overdue and upcoming payments are summed against the balance with the owner\'s wording', function () {
    $user = User::factory()->create();
    liquidityEnzfox($user);

    $row = liquidityRow($user);

    expect($row)->toMatchArray([
        'balance' => 9.74,
        'due_total' => 229.0,
        'shortfall' => 219.26,
        'needed_by' => '2026-12-02',
        'negative_on' => '2026-11-03',
        'is_at_risk' => true,
        'is_estimate' => false,
    ])
        ->and($row['payments'])->toHaveCount(3)
        ->and($row['payments'][0]['overdue'])->toBeTrue()
        ->and(app(LiquidityService::class)->describe($row))
        ->toBe('Enzfox: 9.74 RON, you need 229 RON by 2 Dec, you are short 219.26 RON');
});

test('savings accounts, paid, skipped, income, paused and far-away payments are not counted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main', 'balance' => 1000]);
    $savings = Account::factory()->create(['user_id' => $user->id, 'name' => 'Rainy day', 'balance' => 0, 'is_savings' => true]);
    $other = Account::factory()->create(['user_id' => $user->id]);

    liquidityPayment($account, 10, '2026-11-10');
    liquidityPayment($account, 20, '2026-11-11', ['transaction_id' => Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $other->id])->id]);
    liquidityPayment($account, 40, '2026-11-12', ['skipped_at' => now()]);
    liquidityPayment($account, 80, '2026-11-13', ['type' => PaymentType::Income]);
    liquidityPayment($account, 160, '2026-11-14', ruleActive: false);
    liquidityPayment($account, 320, '2027-03-01');
    liquidityPayment($savings, 500, '2026-11-10');

    $rows = app(LiquidityService::class)->forecast($user);

    expect($rows->pluck('account_name')->all())->not->toContain('Rainy day')
        ->and($rows->firstWhere('account_name', 'Main'))->toMatchArray(['due_total' => 10.0, 'shortfall' => 0.0, 'is_at_risk' => false, 'negative_on' => null])
        ->and(app(LiquidityService::class)->atRisk($user))->toHaveCount(0);
});

test('the first date the balance goes below the threshold is found by applying payments in date order', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main', 'balance' => 500, 'alert_threshold' => 200]);

    liquidityPayment($account, 100, now()->addDays(5)->toDateString());
    liquidityPayment($account, 100, now()->addDays(20)->toDateString());
    liquidityPayment($account, 150, now()->addDays(10)->toDateString());

    $row = liquidityRow($user, name: 'Main');

    expect($row)->toMatchArray([
        'shortfall' => 0.0,
        'negative_on' => null,
        'below_threshold' => false,
        'threshold_crossed_on' => now()->addDays(20)->toDateString(),
        'balance_after' => 150.0,
        'is_at_risk' => true,
    ])
        ->and(app(LiquidityService::class)->describe($row))
        ->toBe('Main: 500 EUR, after 350 EUR due by '.now()->addDays(20)->format('j M').' it drops to 150 EUR, at or below your 200 EUR alert threshold');
});

test('an account already at its threshold is at risk today, and the days window is respected', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Main', 'balance' => 50, 'alert_threshold' => 50]);
    $quiet = Account::factory()->create(['user_id' => $user->id, 'name' => 'Quiet', 'balance' => 100]);
    liquidityPayment($quiet, 500, now()->addDays(10)->toDateString());

    $main = liquidityRow($user, name: 'Main');

    expect($main)->toMatchArray(['below_threshold' => true, 'threshold_crossed_on' => '2026-11-03'])
        ->and(liquidityRow($user, 7, 'Quiet'))->toMatchArray(['due_total' => 0.0, 'is_at_risk' => false])
        ->and(liquidityRow($user, 30, 'Quiet'))->toMatchArray(['due_total' => 500.0, 'shortfall' => 400.0])
        ->and(app(LiquidityService::class)->describe($main))->toBe('Main: 50 EUR, at or below your 50 EUR alert threshold');
});

test('an account that is already negative is included even with nothing due', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Popso', 'balance' => -55.23]);

    $row = liquidityRow($user, name: 'Popso');

    expect($row)->toMatchArray(['shortfall' => 55.23, 'negative_on' => '2026-11-03', 'is_at_risk' => true])
        ->and(app(LiquidityService::class)->describe($row))->toBe('Popso: -55.23 EUR, the account is overdrawn');
});

test('a payment in another currency than its account is converted and labelled an estimate', function () {
    $this->mock(CurrencyService::class)->shouldReceive('convert')->andReturn(50.0);
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Enzfox', 'currency' => 'RON', 'balance' => 20]);
    liquidityPayment($account, 10, '2026-11-10', ['currency' => 'EUR']);

    $row = liquidityRow($user);

    expect($row)->toMatchArray(['due_total' => 50.0, 'shortfall' => 30.0, 'is_estimate' => true])
        ->and(app(LiquidityService::class)->describe($row))->toEndWith('(estimate)');
});

test('a payment that cannot be converted is never counted 1:1 and says so plainly', function () {
    $this->mock(CurrencyService::class)->shouldReceive('convert')->andReturnNull();
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Enzfox', 'currency' => 'RON', 'balance' => 20]);
    liquidityPayment($account, 100, '2026-11-10', ['currency' => 'EUR']);

    $row = liquidityRow($user);

    expect($row)->toMatchArray(['due_total' => 0.0, 'shortfall' => 0.0, 'balance_after' => 20.0, 'is_estimate' => false, 'has_unconverted_payments' => true, 'is_at_risk' => true])
        ->and($row['payments'])->toHaveCount(1)
        ->and($row['payments'][0])->toMatchArray(['amount' => 100.0, 'currency' => 'EUR', 'rate_missing' => true])
        ->and(app(LiquidityService::class)->describe($row))->toBe('Enzfox: 20 RON. 1 payment in EUR could not be converted: add a rate or check it')
        ->and(app(LiquidityService::class)->atRisk($user))->toHaveCount(1);
});

test('unconverted payments are described per currency', function () {
    $this->mock(CurrencyService::class)->shouldReceive('convert')->andReturnNull();
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Enzfox', 'currency' => 'RON', 'balance' => 20]);
    liquidityPayment($account, 10, '2026-11-08', ['currency' => 'EUR']);
    liquidityPayment($account, 15, '2026-11-09');
    liquidityPayment($account, 5, '2026-11-10', ['currency' => 'EUR']);

    $row = liquidityRow($user);

    expect($row)->toMatchArray(['due_total' => 15.0, 'shortfall' => 0.0, 'balance_after' => 5.0, 'has_unconverted_payments' => true])
        ->and(collect($row['payments'])->pluck('rate_missing')->all())->toBe([true, false, true])
        ->and(app(LiquidityService::class)->describe($row))->toBe('Enzfox: 20 RON. 2 payments in EUR could not be converted: add a rate or check it');
});

test('an unconverted payment alerts once per state and re-arms when it clears', function () {
    $this->mock(CurrencyService::class)->shouldReceive('convert')->andReturnNull();
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main', 'currency' => 'RON', 'balance' => 500]);
    $payment = liquidityPayment($account, 100, '2026-11-05', ['currency' => 'EUR']);

    $this->artisan('liquidity:notify')->assertSuccessful();
    $this->artisan('liquidity:notify')->assertSuccessful();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data)->toMatchArray(['kind' => 'unconverted', 'account_id' => $account->id])
        ->and($user->notifications()->first()->data['message'])->toContain('1 payment in EUR could not be converted');

    $payment->update(['skipped_at' => now()]);
    $this->artisan('liquidity:notify');
    $payment->update(['skipped_at' => null]);
    $this->artisan('liquidity:notify');

    expect($user->notifications()->count())->toBe(2);
});

test('a user only ever sees their own accounts', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    liquidityEnzfox($stranger);
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Mine', 'balance' => 10]);

    expect(app(LiquidityService::class)->forecast($user)->pluck('account_name')->all())->toBe(['Mine']);

    actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('liquidity.at_risk', 0)
        ->has('liquidity.alerts', 0));

    actingAs($stranger)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->has('liquidity.at_risk', 1)
        ->where('liquidity.at_risk.0.message', 'Enzfox: 9.74 RON, you need 229 RON by 2 Dec, you are short 219.26 RON'));
});

test('an alert is sent once while the condition persists, and again once it cleared', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main', 'balance' => 10]);
    $payment = liquidityPayment($account, 100, '2026-11-05');

    $this->artisan('liquidity:notify')->assertSuccessful();
    $this->artisan('liquidity:notify')->assertSuccessful();

    $notification = $user->notifications()->first();

    expect($user->notifications()->count())->toBe(1)
        ->and($notification->type)->toBe(LiquidityAlert::class)
        ->and($notification->data)->toMatchArray(['kind' => 'shortfall', 'account_id' => $account->id, 'shortfall' => 90.0]);

    $this->travelTo('2026-11-04 12:00:00');
    $this->artisan('liquidity:notify');
    expect($user->notifications()->count())->toBe(1);

    $payment->update(['skipped_at' => now()]);
    $this->artisan('liquidity:notify');
    expect($user->notifications()->count())->toBe(1);

    $payment->update(['skipped_at' => null]);
    $this->artisan('liquidity:notify');
    expect($user->notifications()->count())->toBe(2);
});

test('a changed state alerts again and the threshold has its own alert', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main', 'balance' => 10, 'alert_threshold' => 50]);
    liquidityPayment($account, 100, '2026-11-05');

    $this->artisan('liquidity:notify');

    expect($user->notifications()->get()->pluck('data.kind')->sort()->values()->all())->toBe(['shortfall', 'threshold']);

    liquidityPayment($account, 20, '2026-11-06');
    $this->artisan('liquidity:notify');

    expect($user->notifications()->count())->toBe(3);
});

test('payments further away than a week do not alert, and users are notified separately', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $far = Account::factory()->create(['user_id' => $user->id, 'balance' => 10]);
    liquidityPayment($far, 100, '2026-11-20');
    liquidityEnzfox($stranger);

    $this->artisan('liquidity:notify')->expectsOutput('Sent 1 liquidity alert(s).')->assertSuccessful();

    expect($user->notifications()->count())->toBe(0)->and($stranger->notifications()->count())->toBe(1);
});

test('the dashboard lists unread alerts and they can be dismissed', function () {
    $user = User::factory()->create();
    liquidityEnzfox($user);
    $this->artisan('liquidity:notify');

    actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->has('liquidity.alerts', 1));

    actingAs($user)->post(route('liquidity-alerts.read'))->assertRedirect();

    actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->has('liquidity.alerts', 0));
});

test('the alert threshold can be set, cleared and is validated', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('accounts.store'), ['name' => 'Main', 'currency' => 'EUR', 'balance' => 10, 'alert_threshold' => '150.5'])->assertRedirect();
    $account = Account::where('user_id', $user->id)->first();
    expect($account->alert_threshold)->toBe('150.50');

    actingAs($user)->put(route('accounts.update', $account), ['name' => 'Main', 'currency' => 'EUR'])->assertRedirect();
    expect($account->fresh()->alert_threshold)->toBe('150.50');

    actingAs($user)->put(route('accounts.update', $account), ['name' => 'Main', 'currency' => 'EUR', 'alert_threshold' => ''])->assertRedirect();
    expect($account->fresh()->alert_threshold)->toBeNull();

    actingAs($user)->put(route('accounts.update', $account), ['name' => 'Main', 'currency' => 'EUR', 'alert_threshold' => 'abc'])->assertSessionHasErrors('alert_threshold');
    actingAs($user)->put(route('accounts.update', $account), ['name' => 'Main', 'currency' => 'EUR', 'alert_threshold' => '1.234'])->assertSessionHasErrors('alert_threshold');
    actingAs($user)->post(route('accounts.store'), ['name' => 'Other', 'currency' => 'EUR', 'alert_threshold' => 'abc'])->assertSessionHasErrors('alert_threshold');
});
