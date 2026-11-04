<?php

use App\Ai\Agents\InsightsNarrator;
use App\Enums\AiFeature;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\DashboardService;
use App\Services\InsightsNarrationService;
use App\Services\InsightsService;
use App\Services\SettlementService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->travelTo('2026-11-03 12:00:00');
});

/**
 * A user (optionally with a reset day) and a EUR account.
 *
 * @return array{0: User, 1: Account}
 */
function insightsSetup(?int $resetDate = null): array
{
    $user = User::factory()->create(['reset_date' => $resetDate]);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);

    return [$user, $account];
}

function insightsCategory(User $user, string $name, string $type = 'expense'): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function insightsBook(User $user, Account $account, string $type, float $amount, string $date, ?Category $category = null, array $extra = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'type' => TransactionType::from($type),
        'amount' => $amount,
        'currency' => $account->currency,
        'date' => $date,
        ...$extra,
    ]);
}

function insightsEnableAi(bool $insights = true): void
{
    app(AiSettings::class)->save([
        'enabled' => true,
        'driver' => 'ollama',
        'url' => 'http://ollama.test:11434',
        'model' => 'qwen3:4b',
        'vision' => false,
        'features' => [...collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [$feature->value => true])->all(), 'insights' => $insights],
    ]);
}

test('the period starts on the reset day and ends the day before the next one', function () {
    [$user, $account] = insightsSetup(15);
    $food = insightsCategory($user, 'Food');

    foreach (['2026-10-14' => 1, '2026-10-15' => 10, '2026-11-14' => 100, '2026-11-15' => 1000] as $date => $amount) {
        insightsBook($user, $account, 'expense', $amount, $date, $food);
    }

    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['period'])->toBe(['start' => '2026-10-15', 'end' => '2026-11-14', 'is_current' => true])
        ->and($insights['previous_period'])->toBe(['start' => '2026-09-15', 'end' => '2026-10-14'])
        ->and($insights['summary']['expenses'])->toEqual(110)
        ->and($insights['previous_summary']['expenses'])->toEqual(1)
        ->and($insights['navigation'])->toBe(['previous' => '2026-09-15', 'next' => null]);

    $later = app(InsightsService::class)->forPeriod($user, now()->setDate(2026, 11, 20));

    expect($later['period']['start'])->toBe('2026-11-15')
        ->and($later['period']['is_current'])->toBeFalse()
        ->and($later['summary']['expenses'])->toEqual(1000);

    $earlier = app(InsightsService::class)->forPeriod($user, now()->setDate(2026, 10, 1));

    expect($earlier['period']['end'])->toBe('2026-10-14')
        ->and($earlier['navigation']['next'])->toBe('2026-10-15');
});

test('without a reset date the period is the calendar month', function () {
    [$user, $account] = insightsSetup();
    $food = insightsCategory($user, 'Food');
    insightsBook($user, $account, 'expense', 5, '2026-10-31', $food);
    insightsBook($user, $account, 'expense', 20, '2026-11-01', $food);
    insightsBook($user, $account, 'expense', 30, '2026-11-30', $food);
    insightsBook($user, $account, 'expense', 400, '2026-12-01', $food);

    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['period']['start'])->toBe('2026-11-01')
        ->and($insights['period']['end'])->toBe('2026-11-30')
        ->and($insights['previous_period'])->toBe(['start' => '2026-10-01', 'end' => '2026-10-31'])
        ->and($insights['summary']['expenses'])->toEqual(50)
        ->and($insights['previous_summary']['expenses'])->toEqual(5);
});

test('the totals are the dashboard totals', function () {
    [$user, $account] = insightsSetup(15);
    $food = insightsCategory($user, 'Food');
    insightsBook($user, $account, 'expense', 33.5, '2026-10-20', $food);
    insightsBook($user, $account, 'expense', 12.25, '2026-11-02', $food);
    insightsBook($user, $account, 'income', 2000, '2026-10-15', insightsCategory($user, 'Salary', 'income'));

    $dashboard = app(DashboardService::class)->getDashboardData($user);
    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['summary'])->toEqual($dashboard['summary'])
        ->and($insights['period']['start'])->toBe($dashboard['period']['start'])
        ->and($insights['period']['end'])->toBe($dashboard['period']['end'])
        ->and($insights['top_categories'][0]['total'])->toEqual($dashboard['categorySpending'][0]['total']);
});

test('categories are compared with the previous period', function () {
    [$user, $account] = insightsSetup();
    $food = insightsCategory($user, 'Food');
    $fun = insightsCategory($user, 'Fun');
    insightsBook($user, $account, 'expense', 100, '2026-10-10', $food);
    insightsBook($user, $account, 'expense', 200, '2026-11-10', $food);
    insightsBook($user, $account, 'expense', 40, '2026-11-11', $fun);

    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['expense_change'])->toBe(['amount' => 140.0, 'percent' => 140.0])
        ->and($insights['top_categories'][0])->toMatchArray([
            'name' => 'Food', 'total' => 200.0, 'previous_total' => 100.0, 'change_amount' => 100.0, 'change_percent' => 100.0,
        ])
        ->and($insights['top_categories'][1])->toMatchArray(['name' => 'Fun', 'previous_total' => 0.0, 'change_percent' => null])
        ->and($insights['sentence'])->toBe('You spent 240.00 EUR, 140% more than last period, mostly on Food.');
});

test('a category at 1.5 times its recent average and above the minimum is flagged', function () {
    [$user, $account] = insightsSetup();
    $fun = insightsCategory($user, 'Fun');
    $tiny = insightsCategory($user, 'Tiny');
    $steady = insightsCategory($user, 'Steady');
    $fresh = insightsCategory($user, 'Fresh');

    foreach (['2026-10-05', '2026-09-05', '2026-08-05'] as $date) {
        insightsBook($user, $account, 'expense', 100, $date, $fun);
        insightsBook($user, $account, 'expense', 10, $date, $tiny);
        insightsBook($user, $account, 'expense', 100, $date, $steady);
    }

    insightsBook($user, $account, 'expense', 150, '2026-11-05', $fun);
    insightsBook($user, $account, 'expense', 40, '2026-11-05', $tiny);
    insightsBook($user, $account, 'expense', 149, '2026-11-05', $steady);
    insightsBook($user, $account, 'expense', 500, '2026-11-05', $fresh);

    $unusual = app(InsightsService::class)->forPeriod($user)['unusual_categories'];

    expect($unusual)->toHaveCount(1)
        ->and($unusual[0])->toMatchArray(['name' => 'Fun', 'total' => 150.0, 'average' => 100.0, 'ratio' => 1.5]);
});

test('the five biggest expenses are listed biggest first', function () {
    [$user, $account] = insightsSetup();

    foreach ([5, 90, 20, 70, 10, 60, 30] as $index => $amount) {
        insightsBook($user, $account, 'expense', $amount, '2026-11-0'.($index + 1), null, ['description' => "E{$amount}"]);
    }

    $insights = app(InsightsService::class)->forPeriod($user);

    expect(array_column($insights['biggest_expenses'], 'description'))->toBe(['E90', 'E70', 'E60', 'E30', 'E20'])
        ->and($insights['summary']['transaction_count'])->toBe(7);
});

test('the net-of-advances setting is honoured', function () {
    [$user, $account] = insightsSetup();
    $person = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Mama']);
    $food = insightsCategory($user, 'Food');
    $book = fn (string $type, float $amount, bool $advance) => app(TransactionService::class)->createTransaction($user, [
        'type' => $type,
        'account_id' => $account->id,
        'amount' => $amount,
        'date' => '2026-11-02',
        'category_id' => $type === 'expense' ? $food->id : null,
        'counterparty_id' => $advance || $type === 'income' ? $person->id : null,
        'is_advance' => $advance,
    ]);

    $advance = $book('expense', 100, true);
    $book('expense', 40, false);
    $income = $book('income', 60, false);
    app(SettlementService::class)->allocate($user, $income, [['expense_id' => $advance->id, 'amount' => 60]]);

    $gross = app(InsightsService::class)->forPeriod($user);

    expect($gross['summary']['expenses'])->toEqual(140)
        ->and($gross['people'])->toBe([['name' => 'Mama', 'currency' => 'EUR', 'balance' => 40.0]]);

    $user->update(['count_advances_until_settled' => true]);
    $net = app(InsightsService::class)->forPeriod($user->fresh());

    expect($net['summary']['expenses'])->toEqual(80)
        ->and($net['summary']['income'])->toEqual(0)
        ->and($net['top_categories'][0]['total'])->toEqual(80);
});

test('an empty period gives zeros and empty lists', function () {
    $user = User::factory()->create();

    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['summary'])->toEqual(['income' => 0, 'expenses' => 0, 'net' => 0, 'transaction_count' => 0])
        ->and($insights['expense_change']['percent'])->toBeNull()
        ->and($insights['top_categories'])->toBe([])
        ->and($insights['unusual_categories'])->toBe([])
        ->and($insights['biggest_expenses'])->toBe([])
        ->and($insights['payments']['overdue'])->toBe([])
        ->and($insights['payments']['upcoming'])->toBe([])
        ->and($insights['people'])->toBe([])
        ->and($insights['liquidity'])->toBeNull()
        ->and($insights['is_estimate'])->toBeFalse()
        ->and($insights['sentence'])->toBe('No expenses were recorded in this period.');
});

test('overdue and upcoming payments and the accounts at risk are reported', function () {
    [$user, $account] = insightsSetup();
    $account->update(['name' => 'Main']);
    $account->forceFill(['balance' => 50])->save();
    $category = insightsCategory($user, 'Bills');
    $rule = RecurringPayment::factory()->create([
        'user_id' => $user->id, 'account_id' => $account->id, 'category_id' => $category->id,
        'name' => 'Rent', 'amount' => 100, 'start_date' => '2026-10-25',
    ]);

    foreach (['2026-10-25', '2026-11-20', '2027-03-01'] as $due) {
        Payment::factory()->create([
            'user_id' => $user->id, 'account_id' => $account->id, 'category_id' => $category->id,
            'recurring_payment_id' => $rule->id, 'amount' => 100, 'due_date' => $due,
        ]);
    }

    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['payments']['overdue'])->toHaveCount(1)
        ->and($insights['payments']['upcoming'])->toHaveCount(1)
        ->and($insights['payments']['upcoming'][0]['due_date'])->toBe('2026-11-20')
        ->and($insights['payments']['overdue_total'])->toEqual(100)
        ->and($insights['payments']['upcoming_total'])->toEqual(100)
        ->and($insights['liquidity'])->toContain('Main');
});

test('another user\'s data never appears', function () {
    [$user, $account] = insightsSetup();
    [$other, $otherAccount] = insightsSetup();
    $mine = insightsCategory($user, 'Mine');
    $theirs = insightsCategory($other, 'Theirs');
    insightsBook($user, $account, 'expense', 10, '2026-11-02', $mine);
    $foreign = insightsBook($other, $otherAccount, 'expense', 999, '2026-11-02', $theirs);
    $person = Counterparty::factory()->create(['user_id' => $other->id, 'name' => 'Stranger']);
    $foreign->update(['counterparty_id' => $person->id, 'is_advance' => true]);

    $insights = app(InsightsService::class)->forPeriod($user);

    expect($insights['summary']['expenses'])->toEqual(10)
        ->and(array_column($insights['top_categories'], 'name'))->toBe(['Mine'])
        ->and(array_column($insights['biggest_expenses'], 'id'))->not->toContain($foreign->id)
        ->and($insights['people'])->toBe([]);
});

test('the insights page needs a login, shows the period and moves between periods', function () {
    $this->get(route('insights.index'))->assertRedirect(route('login'));

    [$user] = insightsSetup();

    actingAs($user)->get(route('insights.index'))->assertInertia(fn ($page) => $page
        ->component('Insights/Index')
        ->where('insights.period.start', '2026-11-01')
        ->where('insights.navigation.next', null));

    actingAs($user)->get(route('insights.index', ['date' => '2026-10-20']))->assertInertia(fn ($page) => $page
        ->where('insights.period.start', '2026-10-01')
        ->where('insights.period.end', '2026-10-31')
        ->where('insights.navigation.next', '2026-11-01'));

    actingAs($user)->get(route('insights.index', ['date' => 'nonsense']))->assertSessionHasErrors('date');
});

test('the dashboard still renders with the insights card', function () {
    [$user] = insightsSetup();

    actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard'));
});

test('the narrator only receives figures and category names', function () {
    insightsEnableAi();
    [$user, $account] = insightsSetup();
    $account->update(['name' => 'Hidden savings']);
    $account->forceFill(['balance' => 98765.43])->save();
    $groceries = insightsCategory($user, 'Groceries');
    $person = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Mama Rosa']);
    insightsBook($user, $account, 'expense', 123.45, '2026-11-02', $groceries, [
        'description' => 'Secret dentist visit', 'counterparty_id' => $person->id, 'is_advance' => true,
    ]);

    InsightsNarrator::fake(['You spent 123.45 EUR, mostly on Groceries.']);

    $insights = app(InsightsService::class)->forPeriod($user);
    $result = app(InsightsNarrationService::class)->narrate($user, $insights, 'ro');

    expect($result)->toBe(['text' => 'You spent 123.45 EUR, mostly on Groceries.', 'source' => 'ai']);

    InsightsNarrator::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Groceries')
        && $prompt->contains('123.45')
        && $prompt->contains('Romanian')
        && ! $prompt->contains('Secret dentist')
        && ! $prompt->contains('Mama Rosa')
        && ! $prompt->contains('Hidden savings')
        && ! $prompt->contains('98765'));
});

test('the narrative is cached per input so a reload never calls the model again', function () {
    insightsEnableAi();
    [$user, $account] = insightsSetup();
    $food = insightsCategory($user, 'Food');
    insightsBook($user, $account, 'expense', 50, '2026-11-02', $food);
    InsightsNarrator::fake(['First paragraph.', 'Second paragraph.']);
    $narration = app(InsightsNarrationService::class);

    $first = $narration->narrate($user, app(InsightsService::class)->forPeriod($user), 'en');
    $again = $narration->narrate($user, app(InsightsService::class)->forPeriod($user), 'en');

    expect($first['text'])->toBe('First paragraph.')
        ->and($again['text'])->toBe('First paragraph.');

    insightsBook($user, $account, 'expense', 25, '2026-11-03', $food);

    expect($narration->narrate($user, app(InsightsService::class)->forPeriod($user), 'en')['text'])->toBe('Second paragraph.');
});

test('the template sentence is used when AI is off', function () {
    [$user, $account] = insightsSetup();
    insightsBook($user, $account, 'expense', 50, '2026-11-02', insightsCategory($user, 'Food'));
    InsightsNarrator::fake();

    $insights = app(InsightsService::class)->forPeriod($user);

    expect(app(InsightsNarrationService::class)->narrate($user, $insights))->toBe(['text' => $insights['sentence'], 'source' => 'template']);

    insightsEnableAi(false);

    expect(app(InsightsNarrationService::class)->narrate($user, $insights)['source'])->toBe('template');

    InsightsNarrator::assertNeverPrompted();
});

test('a failing model gives the template sentence and is not cached', function () {
    insightsEnableAi();
    [$user, $account] = insightsSetup();
    insightsBook($user, $account, 'expense', 50, '2026-11-02', insightsCategory($user, 'Food'));
    Http::fake(['*' => Http::response('boom', 500)]);
    $insights = app(InsightsService::class)->forPeriod($user);
    $narration = app(InsightsNarrationService::class);

    expect($narration->narrate($user, $insights))->toBe(['text' => $insights['sentence'], 'source' => 'template']);

    InsightsNarrator::fake(['Recovered.']);

    expect($narration->narrate($user, $insights))->toBe(['text' => 'Recovered.', 'source' => 'ai']);
});

test('the model output is stripped of tags, capped and never empty', function () {
    insightsEnableAi();
    [$user, $account] = insightsSetup();
    insightsBook($user, $account, 'expense', 50, '2026-11-02', insightsCategory($user, 'Food'));
    $insights = app(InsightsService::class)->forPeriod($user);
    $narration = app(InsightsNarrationService::class);

    InsightsNarrator::fake(["   \n  "]);
    expect($narration->narrate($user, $insights)['source'])->toBe('template');

    $insights['summary']['expenses'] = 51.0;
    InsightsNarrator::fake(['<b>You spent</b> <i>a lot</i>']);
    expect($narration->narrate($user, $insights)['text'])->toBe('You spent a lot');

    $insights['summary']['expenses'] = 52.0;
    InsightsNarrator::fake([str_repeat('word ', 300)]);
    expect(mb_strlen($narration->narrate($user, $insights)['text']))->toBeLessThanOrEqual(600);
});
