<?php

use App\Enums\TransactionType;
use App\Mcp\Servers\FlussoServer;
use App\Mcp\Tools;
use App\Models\Account;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Transaction;
use App\Models\TransactionSettlement;
use App\Models\User;
use App\Services\CounterpartyService;
use App\Services\SettlementService;
use App\Services\TransactionService;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

/**
 * A user with a EUR account and a person ("Mama").
 *
 * @return array{0: User, 1: Account, 2: Counterparty}
 */
function advancesSetup(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
    $person = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Mama']);

    return [$user, $account, $person];
}

/**
 * Books an expense (an advance when a person is given) or an income through the service.
 *
 * @param  array<string, mixed>  $extra
 */
function advancesBook(User $user, Account $account, string $type, float $amount, ?Counterparty $person, array $extra = []): Transaction
{
    return app(TransactionService::class)->createTransaction($user, [
        'type' => $type,
        'account_id' => $account->id,
        'amount' => $amount,
        'date' => '2026-04-12',
        'counterparty_id' => $person?->id,
        'is_advance' => $type === 'expense' && $person !== null,
        ...$extra,
    ]);
}

/**
 * @return list<array{expense_id: int, amount: float}>
 */
function advancesAllocation(Transaction $expense, float $amount): array
{
    return [['expense_id' => $expense->id, 'amount' => $amount]];
}

test('an income settles several advances and an advance can be settled in parts', function () {
    [$user, $account, $person] = advancesSetup();
    $first = advancesBook($user, $account, 'expense', 100, $person);
    $second = advancesBook($user, $account, 'expense', 50, $person);
    $income = advancesBook($user, $account, 'income', 120, $person);
    $later = advancesBook($user, $account, 'income', 30, $person);

    $service = app(SettlementService::class);
    $service->allocate($user, $income, [['expense_id' => $first->id, 'amount' => 100], ['expense_id' => $second->id, 'amount' => 20]]);
    $service->allocate($user, $later, advancesAllocation($second, 30));

    expect(TransactionSettlement::count())->toBe(3)
        ->and($service->settledCents([$first->id, $second->id], TransactionType::Expense))->toBe([$first->id => 10000, $second->id => 5000])
        ->and($service->settledCents([$income->id], TransactionType::Income))->toBe([$income->id => 12000]);

    $balance = app(CounterpartyService::class)->balances($user)->first()['balances'][0];

    expect($balance)->toMatchArray([
        'currency' => 'EUR', 'advanced' => 150.0, 'settled' => 150.0, 'outstanding' => 0.0,
        'received' => 150.0, 'unallocated' => 0.0, 'balance' => 0.0,
    ]);
});

test('settling the same pair again adds to it', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);

    $service = app(SettlementService::class);
    $service->allocate($user, $income, advancesAllocation($expense, 30));
    $service->allocate($user, $income, advancesAllocation($expense, 20));

    expect(TransactionSettlement::count())->toBe(1)
        ->and(TransactionSettlement::first()->amount)->toBe('50.00');
});

test('an expense cannot be settled for more than what is left on it', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 300, $person);

    $service = app(SettlementService::class);
    $service->allocate($user, $income, advancesAllocation($expense, 70));

    expect(fn () => $service->allocate($user, $income, advancesAllocation($expense, 30.01)))
        ->toThrow(ValidationException::class, 'Only 30.00 is left to settle on that expense');

    $service->allocate($user, $income, advancesAllocation($expense, 30));

    expect(TransactionSettlement::first()->amount)->toBe('100.00');
});

test('an income cannot allocate more than it has, and a failed call settles nothing', function () {
    [$user, $account, $person] = advancesSetup();
    $first = advancesBook($user, $account, 'expense', 100, $person);
    $second = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 150, $person);

    expect(fn () => app(SettlementService::class)->allocate($user, $income, [
        ['expense_id' => $first->id, 'amount' => 100],
        ['expense_id' => $second->id, 'amount' => 60],
    ]))->toThrow(ValidationException::class, 'Only 50.00 of this income is left to allocate');

    expect(TransactionSettlement::count())->toBe(0);
});

test('the amount to settle must be positive', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);

    expect(fn () => app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 0)))
        ->toThrow(ValidationException::class, 'greater than zero');
});

test('only an income can settle and only expenses can be settled', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $otherExpense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);
    $service = app(SettlementService::class);

    expect(fn () => $service->allocate($user, $expense, advancesAllocation($otherExpense, 10)))
        ->toThrow(ValidationException::class, 'Pick one of your incomes');

    expect(fn () => $service->allocate($user, $income, advancesAllocation($income, 10)))
        ->toThrow(ValidationException::class, 'Pick one of your expenses');
});

test('an expense that is not an advance cannot be settled', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person, ['is_advance' => false]);
    $income = advancesBook($user, $account, 'income', 100, $person);

    expect(fn () => app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 10)))
        ->toThrow(ValidationException::class, 'not an advance');
});

test('settlements need the same person on both sides', function () {
    [$user, $account, $person] = advancesSetup();
    $irina = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Irina']);
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $irina);
    $withoutPerson = advancesBook($user, $account, 'income', 100, null);
    $service = app(SettlementService::class);

    expect(fn () => $service->allocate($user, $income, advancesAllocation($expense, 10)))
        ->toThrow(ValidationException::class, 'different people');

    expect(fn () => $service->allocate($user, $withoutPerson, advancesAllocation($expense, 10)))
        ->toThrow(ValidationException::class, 'not from anyone');
});

test('settlements between different currencies are rejected', function () {
    [$user, $account, $person] = advancesSetup();
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 1000]);
    $expense = advancesBook($user, $ron, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);

    expect(fn () => app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 10)))
        ->toThrow(ValidationException::class, 'different currencies is not supported yet');
});

test('settlements never reach another user\'s transactions', function () {
    [$user, $account, $person] = advancesSetup();
    [$other, $otherAccount, $otherPerson] = advancesSetup();
    $theirExpense = advancesBook($other, $otherAccount, 'expense', 100, $otherPerson);
    $theirIncome = advancesBook($other, $otherAccount, 'income', 100, $otherPerson);
    $myIncome = advancesBook($user, $account, 'income', 100, $person);
    $service = app(SettlementService::class);

    expect(fn () => $service->allocate($user, $myIncome, advancesAllocation($theirExpense, 10)))
        ->toThrow(ValidationException::class, 'Pick one of your expenses');

    expect(fn () => $service->allocate($user, $theirIncome, advancesAllocation($theirExpense, 10)))
        ->toThrow(ValidationException::class, 'Pick one of your incomes');
});

test('the balance shows what they overpaid instead of hiding it', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 150, $person);
    app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 100));

    $props = actingAs($user)->get(route('counterparties.index'))->viewData('page')['props'];

    expect($props['counterparties'])->toHaveCount(1)
        ->and($props['counterparties'][0]['balances'][0])->toMatchArray([
            'currency' => 'EUR', 'advanced' => 100.0, 'settled' => 100.0, 'outstanding' => 0.0,
            'received' => 150.0, 'unallocated' => 50.0, 'balance' => -50.0,
        ]);
});

test('money received but not matched yet lowers what they owe', function () {
    [$user, $account, $person] = advancesSetup();
    advancesBook($user, $account, 'expense', 269, $person);
    advancesBook($user, $account, 'expense', 160, $person);
    advancesBook($user, $account, 'income', 200, $person);

    $balance = app(CounterpartyService::class)->balances($user)->first()['balances'][0];

    expect($balance)->toMatchArray(['advanced' => 429.0, 'settled' => 0.0, 'outstanding' => 429.0, 'received' => 200.0, 'unallocated' => 200.0, 'balance' => 229.0]);
});

test('balances are kept per currency', function () {
    [$user, $account, $person] = advancesSetup();
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 1000]);
    advancesBook($user, $account, 'expense', 10, $person);
    advancesBook($user, $ron, 'expense', 40, $person);

    $balances = app(CounterpartyService::class)->balances($user)->first()['balances'];

    expect(array_column($balances, 'currency'))->toBe(['EUR', 'RON'])
        ->and(array_column($balances, 'balance'))->toEqual([10, 40]);
});

test('the suggestion settles the oldest advances first', function () {
    [$user, $account, $person] = advancesSetup();
    $newest = advancesBook($user, $account, 'expense', 30, $person, ['date' => '2026-04-20']);
    $middle = advancesBook($user, $account, 'expense', 80, $person, ['date' => '2026-04-10']);
    $oldest = advancesBook($user, $account, 'expense', 50, $person, ['date' => '2026-04-01']);
    $income = advancesBook($user, $account, 'income', 100, $person);
    $service = app(SettlementService::class);

    expect($service->suggestAllocations($income))->toBe([
        ['expense_id' => $oldest->id, 'amount' => '50.00'],
        ['expense_id' => $middle->id, 'amount' => '50.00'],
    ]);

    $partly = advancesBook($user, $account, 'income', 20, $person);
    $service->allocate($user, $partly, advancesAllocation($oldest, 20));

    expect($service->suggestAllocations($income))->toBe([
        ['expense_id' => $oldest->id, 'amount' => '30.00'],
        ['expense_id' => $middle->id, 'amount' => '70.00'],
    ]);

    $big = advancesBook($user, $account, 'income', 500, $person);

    expect(array_column($service->suggestAllocations($big), 'expense_id'))->toBe([$oldest->id, $middle->id, $newest->id]);
});

test('the person page lists advances and repayments with the suggestion', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 60, $person);
    advancesBook($user, $account, 'expense', 5, null);

    $props = actingAs($user)->get(route('counterparties.show', $person))->assertSuccessful()->viewData('page')['props'];

    expect($props['counterparty'])->toBe(['id' => $person->id, 'name' => 'Mama'])
        ->and($props['advances'])->toHaveCount(1)
        ->and($props['advances'][0])->toMatchArray(['id' => $expense->id, 'settled' => 0.0, 'remaining' => 100.0])
        ->and($props['repayments'])->toHaveCount(1)
        ->and($props['repayments'][0]['id'])->toBe($income->id)
        ->and($props['repayments'][0]['suggestion'])->toBe([['expense_id' => $expense->id, 'amount' => '60.00']]);
});

test('an income that names a person is suggested for them but never assigned', function () {
    [$user, $account] = advancesSetup();
    $irina = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Irina']);
    $income = advancesBook($user, $account, 'income', 100, null, ['description' => 'Irina, utilitati']);
    advancesBook($user, $account, 'income', 10, null, ['description' => 'Sirina bakery']);
    $service = app(CounterpartyService::class);

    expect($service->suggestForDescription($user, 'irina, Amazon')?->is($irina))->toBeTrue()
        ->and($service->suggestForDescription($user, 'Sirina bakery'))->toBeNull()
        ->and($service->suggestForDescription($user, null))->toBeNull()
        ->and($income->fresh()->counterparty_id)->toBeNull();

    $props = actingAs($user)->get(route('counterparties.index'))->viewData('page')['props'];

    expect($props['suggestions'])->toHaveCount(1)
        ->and($props['suggestions'][0])->toMatchArray(['transaction_id' => $income->id, 'counterparty_id' => $irina->id]);

    actingAs($user)->post(route('counterparties.assign', $irina), ['transaction_id' => $income->id])->assertSessionHasNoErrors();

    expect($income->fresh()->counterparty_id)->toBe($irina->id);
});

test('the longest matching name wins', function () {
    [$user] = advancesSetup();
    Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Irina']);
    $full = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Irina Popescu']);

    expect(app(CounterpartyService::class)->suggestForDescription($user, 'Irina Popescu utilitati')?->is($full))->toBeTrue();
});

/**
 * Today is 2026-04-15. The owner advanced 100 for Mama (Food) and spent 40 on Food himself; Mama paid 60 back.
 *
 * @return array{0: User, 1: Counterparty, 2: Category}
 */
function advancesNetScenario(): array
{
    test()->travelTo('2026-04-15 12:00:00');

    [$user, $account, $person] = advancesSetup();
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Food']);
    $refunds = Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'name' => 'Reimbursements']);

    $advance = advancesBook($user, $account, 'expense', 100, $person, ['category_id' => $food->id]);
    advancesBook($user, $account, 'expense', 40, null, ['category_id' => $food->id]);
    $income = advancesBook($user, $account, 'income', 60, $person, ['category_id' => $refunds->id]);

    app(SettlementService::class)->allocate($user, $income, advancesAllocation($advance, 60));

    return [$user, $person, $food];
}

test('totals count advances in full by default', function () {
    [$user, , $food] = advancesNetScenario();
    $service = app(TransactionService::class);

    $summary = $service->getTransactionSummaryForPeriod($user, now()->startOfMonth(), now()->endOfMonth());
    $spending = $service->getCategorySpendingForPeriod($user, now()->startOfMonth(), now()->endOfMonth());

    expect($user->count_advances_until_settled)->toBeFalse()
        ->and($summary['expenses'])->toEqual(140)
        ->and($summary['income'])->toEqual(60)
        ->and($spending)->toHaveCount(1)
        ->and($spending[0]['category']->is($food))->toBeTrue()
        ->and($spending[0]['total'])->toEqual(140);
});

test('with the setting on, settled amounts leave the spending and the income', function () {
    [$user, , $food] = advancesNetScenario();
    $user->update(['count_advances_until_settled' => true]);
    $service = app(TransactionService::class);

    $summary = $service->getTransactionSummaryForPeriod($user->fresh(), now()->startOfMonth(), now()->endOfMonth());
    $spending = $service->getCategorySpendingForPeriod($user->fresh(), now()->startOfMonth(), now()->endOfMonth());

    expect($summary['expenses'])->toEqual(80)
        ->and($summary['income'])->toEqual(0)
        ->and($summary['transaction_count'])->toBe(3)
        ->and($spending[0]['category']->is($food))->toBeTrue()
        ->and($spending[0]['total'])->toEqual(80);

    $dashboard = actingAs($user->fresh())->get(route('dashboard'))->viewData('page')['props'];

    expect($dashboard['summary']['expenses'])->toEqual(80);
});

test('a fully settled category drops out of the net breakdown', function () {
    test()->travelTo('2026-04-15 12:00:00');
    [$user, $account, $person] = advancesSetup();
    $gifts = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $advance = advancesBook($user, $account, 'expense', 50, $person, ['category_id' => $gifts->id]);
    $income = advancesBook($user, $account, 'income', 50, $person);
    app(SettlementService::class)->allocate($user, $income, advancesAllocation($advance, 50));
    $user->update(['count_advances_until_settled' => true]);

    expect(app(TransactionService::class)->getCategorySpendingForPeriod($user->fresh(), now()->startOfMonth(), now()->endOfMonth()))->toHaveCount(0);
});

test('a settlement reduces the spending of the period of the expense', function () {
    test()->travelTo('2026-04-15 12:00:00');
    [$user, $account, $person] = advancesSetup();
    $march = advancesBook($user, $account, 'expense', 100, $person, ['date' => '2026-03-20']);
    $april = advancesBook($user, $account, 'income', 100, $person, ['date' => '2026-04-12']);
    app(SettlementService::class)->allocate($user, $april, advancesAllocation($march, 100));
    $service = app(TransactionService::class);

    $marchStart = now()->setDate(2026, 3, 1)->startOfDay();
    $marchEnd = now()->setDate(2026, 3, 31)->endOfDay();

    expect($service->getTransactionSummaryForPeriod($user, $marchStart, $marchEnd)['expenses'])->toEqual(100);

    $user->update(['count_advances_until_settled' => true]);

    expect($service->getTransactionSummaryForPeriod($user->fresh(), $marchStart, $marchEnd)['expenses'])->toEqual(0)
        ->and($service->getTransactionSummaryForPeriod($user->fresh(), now()->startOfMonth(), now()->endOfMonth())['expenses'])->toEqual(0);
});

test('the setting is off by default and can be changed in the finance settings', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('settings.finance.edit'))->assertInertia(fn ($page) => $page
        ->component('Settings/Finance')
        ->where('countAdvancesUntilSettled', false));

    actingAs($user)->patch(route('settings.finance.update'), ['reset_date' => 5, 'count_advances_until_settled' => 1])->assertSessionHasNoErrors();

    expect($user->fresh()->count_advances_until_settled)->toBeTrue()
        ->and($user->fresh()->reset_date)->toBe(5);

    actingAs($user)->patch(route('settings.finance.update'), ['reset_date' => 5, 'count_advances_until_settled' => 0]);

    expect($user->fresh()->count_advances_until_settled)->toBeFalse();

    actingAs($user)->patch(route('settings.finance.update'), ['count_advances_until_settled' => 1]);
    actingAs($user)->patch(route('settings.finance.update'), ['reset_date' => 7]);

    expect($user->fresh()->count_advances_until_settled)->toBeTrue();
});

test('a transaction form can book an advance and an income from a person', function () {
    [$user, $account, $person] = advancesSetup();
    $base = ['account_id' => $account->id, 'amount' => 25, 'date' => '2026-04-12'];

    actingAs($user)->post(route('transactions.store'), [...$base, 'type' => 'expense', 'counterparty_id' => $person->id, 'is_advance' => true])->assertSessionHasNoErrors();
    actingAs($user)->post(route('transactions.store'), [...$base, 'type' => 'income', 'counterparty_id' => $person->id, 'is_advance' => false])->assertSessionHasNoErrors();
    actingAs($user)->post(route('transactions.store'), [...$base, 'type' => 'expense'])->assertSessionHasNoErrors();

    $expense = Transaction::where('type', 'expense')->whereNotNull('counterparty_id')->first();

    expect($expense->is_advance)->toBeTrue()
        ->and($expense->counterparty_id)->toBe($person->id)
        ->and(Transaction::where('type', 'income')->first())->is_advance->toBeFalse()->counterparty_id->toBe($person->id)
        ->and(Transaction::whereNull('counterparty_id')->first())->is_advance->toBeFalse();
});

test('a new person can be created from the form and an existing one is reused ignoring case', function () {
    [$user, $account, $person] = advancesSetup();
    $base = ['account_id' => $account->id, 'amount' => 25, 'date' => '2026-04-12', 'type' => 'expense', 'is_advance' => true];

    actingAs($user)->post(route('transactions.store'), [...$base, 'counterparty_name' => 'Irina'])->assertSessionHasNoErrors();
    actingAs($user)->post(route('transactions.store'), [...$base, 'counterparty_name' => ' mama '])->assertSessionHasNoErrors();

    expect($user->counterparties()->pluck('name')->sort()->values()->all())->toBe(['Irina', 'Mama'])
        ->and(Transaction::where('counterparty_id', $person->id)->count())->toBe(1);
});

test('the form validates the person and the advance flag', function () {
    [$user, $account, $person] = advancesSetup();
    [, , $otherPerson] = advancesSetup();
    $second = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $base = ['account_id' => $account->id, 'amount' => 25, 'date' => '2026-04-12'];
    $post = fn (array $data) => actingAs($user)->post(route('transactions.store'), [...$base, ...$data]);

    $post(['type' => 'expense', 'is_advance' => true])->assertSessionHasErrors('is_advance');
    $post(['type' => 'income', 'counterparty_id' => $person->id, 'is_advance' => true])->assertSessionHasErrors('is_advance');
    $post(['type' => 'expense', 'counterparty_id' => $otherPerson->id])->assertSessionHasErrors('counterparty_id');
    $post(['type' => 'expense', 'counterparty_id' => 9999])->assertSessionHasErrors('counterparty_id');
    $post(['type' => 'expense', 'counterparty_name' => str_repeat('a', 101)])->assertSessionHasErrors('counterparty_name');
    $post(['type' => 'transfer', 'to_account_id' => $second->id, 'counterparty_id' => $person->id])->assertSessionHasErrors('counterparty_id');

    expect(Transaction::count())->toBe(0);
});

test('editing a transaction through the form can change or clear its person', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 25, $person);
    $payload = ['type' => 'expense', 'account_id' => $account->id, 'amount' => 25, 'date' => '2026-04-12'];

    actingAs($user)->put(route('transactions.update', $expense), [...$payload, 'counterparty_id' => $person->id, 'is_advance' => false])->assertSessionHasNoErrors();

    expect($expense->fresh())->is_advance->toBeFalse()->counterparty_id->toBe($person->id);

    actingAs($user)->put(route('transactions.update', $expense), [...$payload, 'counterparty_id' => null, 'is_advance' => null])->assertSessionHasNoErrors();

    expect($expense->fresh())->is_advance->toBeFalse()->counterparty_id->toBeNull();
});

test('an update that does not mention the person leaves it alone, even through the MCP tools', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 25, $person);

    FlussoServer::actingAs($user)->tool(Tools\UpdateTransaction::class, ['id' => $expense->id, 'description' => 'Amazon', 'amount' => 30])->assertOk();

    expect($expense->fresh())->is_advance->toBeTrue()->counterparty_id->toBe($person->id)->description->toBe('Amazon');

    FlussoServer::actingAs($user)->tool(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 5])->assertOk();

    expect(Transaction::latest('id')->first())->is_advance->toBeFalse()->counterparty_id->toBeNull();
});

test('turning an expense into an income drops the advance flag but keeps the person', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 25, $person);

    app(TransactionService::class)->updateTransaction($expense, ['type' => 'income', 'account_id' => $account->id, 'amount' => 25, 'date' => '2026-04-12']);

    expect($expense->fresh())->type->toBe(TransactionType::Income)->is_advance->toBeFalse()->counterparty_id->toBe($person->id);
});

test('an edit that would break an existing settlement is refused and rolled back', function () {
    [$user, $account, $person] = advancesSetup();
    $irina = Counterparty::factory()->create(['user_id' => $user->id, 'name' => 'Irina']);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 1000]);
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);
    app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 80));
    $service = app(TransactionService::class);
    $payload = ['type' => 'expense', 'account_id' => $account->id, 'amount' => 100, 'date' => '2026-04-12', 'counterparty_id' => $person->id, 'is_advance' => true];
    $edit = fn (array $changes) => $service->updateTransaction(Transaction::findOrFail($expense->id), [...$payload, ...$changes]);

    expect(fn () => $edit(['amount' => 70]))->toThrow(ValidationException::class, 'already settled');
    expect(fn () => $edit(['counterparty_id' => $irina->id]))->toThrow(ValidationException::class, 'another person');
    expect(fn () => $edit(['is_advance' => false]))->toThrow(ValidationException::class, 'stops being an advance');
    expect(fn () => $edit(['account_id' => $ron->id]))->toThrow(ValidationException::class, 'another currency');
    expect(fn () => $service->updateTransaction(Transaction::findOrFail($income->id), ['type' => 'income', 'account_id' => $account->id, 'amount' => 50, 'date' => '2026-04-12', 'counterparty_id' => $person->id]))
        ->toThrow(ValidationException::class, 'already settled');

    expect($expense->fresh())->amount->toBe('100.00')->counterparty_id->toBe($person->id)->is_advance->toBeTrue()
        ->and($account->fresh()->balance)->toBe('1000.00');

    $edit(['amount' => 90, 'description' => 'ok']);

    expect($expense->fresh()->amount)->toBe('90.00');
});

test('a deleted transaction keeps its settlements but they count for nothing until it is restored', function () {
    [$user, $account, $person] = advancesSetup();
    $first = advancesBook($user, $account, 'expense', 100, $person);
    $second = advancesBook($user, $account, 'expense', 50, $person);
    $income = advancesBook($user, $account, 'income', 120, $person);
    app(SettlementService::class)->allocate($user, $income, [['expense_id' => $first->id, 'amount' => 100], ['expense_id' => $second->id, 'amount' => 20]]);
    $balances = fn () => app(CounterpartyService::class)->balances($user)->first()['balances'][0];

    app(TransactionService::class)->deleteTransaction($first);

    expect(TransactionSettlement::count())->toBe(2)
        ->and(TransactionSettlement::live()->count())->toBe(1)
        ->and($balances())->toMatchArray([
            'advanced' => 50.0, 'settled' => 20.0, 'outstanding' => 30.0, 'received' => 120.0, 'unallocated' => 100.0, 'balance' => -70.0,
        ]);

    $first->restore();

    expect(TransactionSettlement::live()->count())->toBe(2)
        ->and($balances())->toMatchArray([
            'advanced' => 150.0, 'settled' => 120.0, 'outstanding' => 30.0, 'received' => 120.0, 'unallocated' => 0.0, 'balance' => 30.0,
        ]);

    app(TransactionService::class)->deleteTransaction($income);

    expect(TransactionSettlement::count())->toBe(2)
        ->and(TransactionSettlement::live()->count())->toBe(0)
        ->and($balances())->toMatchArray(['advanced' => 150.0, 'settled' => 0.0, 'received' => 0.0, 'balance' => 150.0]);

    $newIncome = advancesBook($user, $account, 'income', 150, $person);
    app(SettlementService::class)->allocate($user, $newIncome, advancesAllocation($first, 100));

    expect(TransactionSettlement::live()->count())->toBe(1);
});

/**
 * An expense of 100 settled by income A, A deleted, income B settling it too, then A restored.
 *
 * @return array{0: User, 1: Counterparty, 2: Transaction, 3: Transaction, 4: Transaction}
 */
function advancesOverSettledSetup(): array
{
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $first = advancesBook($user, $account, 'income', 100, $person);
    $second = advancesBook($user, $account, 'income', 100, $person);
    $service = app(SettlementService::class);
    $service->allocate($user, $first, advancesAllocation($expense, 100));

    app(TransactionService::class)->deleteTransaction($first);
    $service->allocate($user, $second, advancesAllocation($expense, 100));
    $first->restore();

    return [$user, $person, $expense, $first, $second];
}

test('restoring a transaction never changes a settlement, even when that over-settles the other side', function () {
    [, , $expense, $first] = advancesOverSettledSetup();

    expect(TransactionSettlement::live()->count())->toBe(2)
        ->and(TransactionSettlement::pluck('amount')->all())->toBe(['100.00', '100.00'])
        ->and(app(SettlementService::class)->settledCents([$expense->id], TransactionType::Expense))->toBe([$expense->id => 20000])
        ->and(app(SettlementService::class)->settledCents([$first->id], TransactionType::Income))->toBe([$first->id => 10000]);
});

test('an over-settled advance is reported with the settlements to remove and the balance stays honest', function () {
    [$user, $person, $expense, $first, $second] = advancesOverSettledSetup();
    $settlementIds = TransactionSettlement::orderBy('id')->pluck('id')->all();

    $props = actingAs($user)->get(route('counterparties.show', $person))->assertSuccessful()->viewData('page')['props'];

    expect($props['over_settled'])->toHaveCount(1)
        ->and($props['over_settled'][0])->toMatchArray([
            'transaction_id' => $expense->id, 'side' => 'expense', 'currency' => 'EUR',
            'amount' => 100.0, 'settled' => 200.0, 'over' => 100.0, 'settlement_ids' => $settlementIds,
        ])
        ->and($props['balances'][0])->toMatchArray(['advanced' => 100.0, 'settled' => 200.0, 'outstanding' => -100.0, 'received' => 200.0, 'balance' => -100.0])
        ->and(actingAs($user)->get(route('counterparties.index'))->viewData('page')['props']['counterparties'][0]['over_settled_count'])->toBe(1);
});

test('an income allocated past its amount is reported too', function () {
    [$user, $account, $person] = advancesSetup();
    $income = advancesBook($user, $account, 'income', 100, $person);
    $firstExpense = advancesBook($user, $account, 'expense', 100, $person);
    $secondExpense = advancesBook($user, $account, 'expense', 100, $person);
    $service = app(SettlementService::class);
    $service->allocate($user, $income, advancesAllocation($firstExpense, 100));
    app(TransactionService::class)->deleteTransaction($firstExpense);
    $service->allocate($user, $income, advancesAllocation($secondExpense, 100));
    $firstExpense->restore();

    $over = app(CounterpartyService::class)->overSettled($user);

    expect($over)->toHaveCount(1)
        ->and($over[0])->toMatchArray(['transaction_id' => $income->id, 'side' => 'income', 'amount' => 100.0, 'settled' => 200.0, 'over' => 100.0]);
});

test('removing one of the settlements clears the over-settlement', function () {
    [$user, $person] = advancesOverSettledSetup();
    $service = app(CounterpartyService::class);
    $settlement = TransactionSettlement::orderBy('id')->first();

    expect($service->overSettled($user))->toHaveCount(1);

    actingAs($user)->delete(route('settlements.destroy', $settlement))->assertRedirect();

    expect($service->overSettled($user))->toBe([])
        ->and($service->balances($user)->first()['over_settled_count'])->toBe(0)
        ->and(actingAs($user)->get(route('counterparties.show', $person))->viewData('page')['props']['over_settled'])->toBe([]);
});

test('over-settlements are only reported to their owner', function () {
    [$user] = advancesOverSettledSetup();
    $stranger = User::factory()->create();

    expect(app(CounterpartyService::class)->overSettled($user))->toHaveCount(1)
        ->and(app(CounterpartyService::class)->overSettled($stranger))->toBe([]);
});

test('settled amounts are compared in cents, so float noise is not an over-settlement', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 0.3, $person);
    $small = advancesBook($user, $account, 'income', 0.1, $person);
    $big = advancesBook($user, $account, 'income', 0.2, $person);
    $extra = advancesBook($user, $account, 'income', 0.1, $person);
    TransactionSettlement::create(['income_transaction_id' => $small->id, 'expense_transaction_id' => $expense->id, 'amount' => '0.10']);
    TransactionSettlement::create(['income_transaction_id' => $big->id, 'expense_transaction_id' => $expense->id, 'amount' => '0.20']);

    expect(app(CounterpartyService::class)->overSettled($user))->toBe([]);

    TransactionSettlement::create(['income_transaction_id' => $extra->id, 'expense_transaction_id' => $expense->id, 'amount' => '0.01']);

    expect(app(CounterpartyService::class)->overSettled($user)[0])->toMatchArray(['amount' => 0.3, 'settled' => 0.31, 'over' => 0.01]);
});

test('deleting a person unlinks their transactions and drops the settlements', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);
    app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 100));

    actingAs($user)->delete(route('counterparties.destroy', $person))->assertRedirect(route('counterparties.index'));

    expect(Counterparty::count())->toBe(0)
        ->and(TransactionSettlement::count())->toBe(0)
        ->and(Transaction::count())->toBe(2)
        ->and($expense->fresh())->counterparty_id->toBeNull()->is_advance->toBeFalse();
});

test('people have unique names per user ignoring case', function () {
    [$user, , $person] = advancesSetup();
    [$other] = advancesSetup();

    actingAs($user)->post(route('counterparties.store'), ['name' => 'mama'])->assertSessionHasErrors('name');
    actingAs($user)->post(route('counterparties.store'), ['name' => ''])->assertSessionHasErrors('name');
    actingAs($user)->post(route('counterparties.store'), ['name' => 'Irina'])->assertSessionHasNoErrors();
    actingAs($other)->post(route('counterparties.store'), ['name' => 'Irina'])->assertSessionHasNoErrors();
    actingAs($user)->put(route('counterparties.update', $person), ['name' => 'MAMA'])->assertSessionHasNoErrors();
    actingAs($user)->put(route('counterparties.update', $person), ['name' => 'irina'])->assertSessionHasErrors('name');

    expect($person->fresh()->name)->toBe('MAMA')
        ->and(Counterparty::where('name', 'Irina')->count())->toBe(2);
});

test('the settle routes settle, validate and unsettle', function () {
    [$user, $account, $person] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 60, $person);

    actingAs($user)->post(route('settlements.store'), ['income_id' => $income->id, 'allocations' => [['expense_id' => $expense->id, 'amount' => 70]]])
        ->assertSessionHasErrors('allocations.0.amount');

    actingAs($user)->post(route('settlements.store'), ['income_id' => $income->id, 'allocations' => []])->assertSessionHasErrors('allocations');

    actingAs($user)->post(route('settlements.store'), ['income_id' => $income->id, 'allocations' => [['expense_id' => $expense->id, 'amount' => 60]]])
        ->assertSessionHasNoErrors();

    $settlement = TransactionSettlement::first();

    expect($settlement->amount)->toBe('60.00');

    actingAs($user)->delete(route('settlements.destroy', $settlement))->assertRedirect();

    expect(TransactionSettlement::count())->toBe(0);
});

test('every counterparty and settlement route is closed to other users', function () {
    [$user, $account, $person] = advancesSetup();
    [$other, $otherAccount, $otherPerson] = advancesSetup();
    $expense = advancesBook($user, $account, 'expense', 100, $person);
    $income = advancesBook($user, $account, 'income', 100, $person);
    $settlement = app(SettlementService::class)->allocate($user, $income, advancesAllocation($expense, 50))->first();
    $theirIncome = advancesBook($other, $otherAccount, 'income', 100, $otherPerson);

    $as = fn () => actingAs($other);

    $as()->get(route('counterparties.show', $person))->assertForbidden();
    $as()->put(route('counterparties.update', $person), ['name' => 'Hacked'])->assertForbidden();
    $as()->delete(route('counterparties.destroy', $person))->assertForbidden();
    $as()->post(route('counterparties.assign', $person), ['transaction_id' => $theirIncome->id])->assertForbidden();
    $as()->delete(route('settlements.destroy', $settlement))->assertForbidden();
    $as()->post(route('settlements.store'), ['income_id' => $income->id, 'allocations' => [['expense_id' => $expense->id, 'amount' => 10]]])->assertNotFound();
    $as()->post(route('settlements.store'), ['income_id' => $theirIncome->id, 'allocations' => [['expense_id' => $expense->id, 'amount' => 10]]])->assertSessionHasErrors('allocations.0.expense_id');
    $as()->post(route('counterparties.assign', $otherPerson), ['transaction_id' => $income->id])->assertSessionHasErrors('transaction_id');
    $as()->post(route('transactions.store'), ['type' => 'expense', 'account_id' => $otherAccount->id, 'amount' => 1, 'date' => '2026-04-12', 'counterparty_id' => $person->id])->assertSessionHasErrors('counterparty_id');

    $index = $as()->get(route('counterparties.index'))->viewData('page')['props'];
    $options = $as()->get(route('counterparties.options'))->assertSuccessful()->json('counterparties');

    expect($index['counterparties'])->toHaveCount(1)
        ->and($index['counterparties'][0]['id'])->toBe($otherPerson->id)
        ->and($index['counterparties'][0]['balances'][0]['advanced'])->toEqual(0)
        ->and($options)->toHaveCount(1)
        ->and($options[0]['id'])->toBe($otherPerson->id)
        ->and(TransactionSettlement::count())->toBe(1)
        ->and($person->fresh()->name)->toBe('Mama');
});

test('guests are sent to log in', function () {
    [, , $person] = advancesSetup();

    $this->get(route('counterparties.index'))->assertRedirect(route('login'));
    $this->get(route('counterparties.show', $person))->assertRedirect(route('login'));
    $this->post(route('settlements.store'), [])->assertRedirect(route('login'));
});

test('the transactions list carries the person for the edit form', function () {
    [$user, $account, $person] = advancesSetup();
    advancesBook($user, $account, 'expense', 100, $person);
    advancesBook($user, $account, 'expense', 10, null);

    actingAs($user)->get(route('transactions.index'))->assertInertia(fn ($page) => $page
        ->component('Transactions/Index')
        ->where('transactions.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['counterparty_id'] === $person->id
            && $row['is_advance'] === true
            && $row['counterparty']['name'] === 'Mama')));
});

test('a settlement can still be removed while its income is in the trash', function () {
    [$user] = advancesOverSettledSetup();
    $settlement = TransactionSettlement::orderBy('id')->first();
    $settlement->income()->withTrashed()->firstOrFail()->delete();

    actingAs($user)->delete(route('settlements.destroy', $settlement))->assertRedirect();

    expect(TransactionSettlement::query()->whereKey($settlement->id)->exists())->toBeFalse();
});
