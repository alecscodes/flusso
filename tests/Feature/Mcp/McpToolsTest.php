<?php

use App\Mcp\Servers\FlussoServer;
use App\Mcp\Tools;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Mcp\Server\Transport\FakeTransporter;

beforeEach(function () {
    $this->travelTo('2026-05-10 12:00:00');
    $this->user = User::factory()->create();
    $this->eur = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Revolut', 'currency' => 'EUR', 'balance' => 1000]);
    $this->ron = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'BT', 'currency' => 'RON', 'balance' => 500]);
    $this->food = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Food', 'type' => 'expense']);
    $this->salary = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Salary', 'type' => 'income']);
});

function mcp(string $tool, array $arguments = [], ?User $as = null)
{
    return FlussoServer::actingAs($as ?? test()->user)->tool($tool, $arguments);
}

test('tools are listed with snake_case names and read/destructive hints', function () {
    $server = app(FlussoServer::class, ['transport' => new FakeTransporter]);
    $tools = collect((fn () => $this->tools)->call($server))->map(fn ($class) => app($class)->toArray())->keyBy('name');

    expect($tools)->toHaveCount(26)
        ->and($tools['get_overview']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tools['list_payments']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tools['delete_transaction']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['archive_account']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['create_transaction']['annotations']['destructiveHint'])->toBeFalse()
        ->and($tools['create_transaction']['inputSchema']['required'])->toEqualCanonicalizing(['type', 'account_id', 'amount'])
        ->and($tools->keys()->all())->each->toMatch('/^[a-z_]+$/');
});

test('get_overview reports balances, the period and what is due', function () {
    plannedPayment($this->user, ['installments' => 1, 'start_date' => '2026-05-20', 'amount' => 80], $this->eur);
    plannedPayment($this->user, ['installments' => 1, 'start_date' => '2026-05-01', 'amount' => 30, 'name' => 'Late'], $this->eur);
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'type' => 'expense', 'amount' => 25, 'date' => '2026-05-09']);

    mcp(Tools\GetOverview::class)->assertOk()->assertSee([
        '"today":"2026-05-10"', '"primary_currency":"EUR"', '"name":"Revolut"', '"expenses":25',
        '"overdue_payments":[{', '"name":"Late"', '"upcoming_payments_30_days":[{', '"due_date":"2026-05-20"',
    ]);
});

test('list_accounts and list_categories only show the signed-in user\'s data', function () {
    $other = User::factory()->create();
    Account::factory()->create(['user_id' => $other->id, 'name' => 'Secret']);
    Category::factory()->create(['user_id' => $other->id, 'name' => 'Hidden']);

    mcp(Tools\ListAccounts::class)->assertSee(['Revolut', 'BT'])->assertDontSee('Secret');
    mcp(Tools\ListCategories::class)->assertSee(['Food', 'Salary'])->assertDontSee('Hidden');
    mcp(Tools\ListCategories::class, ['type' => 'income'])->assertSee('Salary')->assertDontSee('Food');
});

test('create_transaction books an expense with today as the default date', function () {
    mcp(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'amount' => 14, 'description' => 'Lunch'])
        ->assertOk()->assertSee(['"date":"2026-05-10"', '"balance":986']);

    expect($this->eur->fresh()->balance)->toBe('986.00')
        ->and(Transaction::first())->amount->toBe('14.00')->currency->toBe('EUR');
});

test('create_transaction handles transfers, with and without an explicit received amount', function () {
    mcp(Tools\CreateTransaction::class, ['type' => 'transfer', 'account_id' => $this->eur->id, 'to_account_id' => $this->ron->id, 'amount' => 100, 'to_amount' => 497.5])->assertOk();

    expect($this->eur->fresh()->balance)->toBe('900.00')
        ->and($this->ron->fresh()->balance)->toBe('997.50')
        ->and(Transaction::count())->toBe(1);

    $other = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR', 'balance' => 0]);
    mcp(Tools\CreateTransaction::class, ['type' => 'transfer', 'account_id' => $this->eur->id, 'to_account_id' => $other->id, 'amount' => 50])->assertOk();

    expect($other->fresh()->balance)->toBe('50.00')->and($this->eur->fresh()->balance)->toBe('850.00');
});

test('create_transaction returns validation errors as tool errors', function (array $arguments, string $message) {
    mcp(Tools\CreateTransaction::class, $arguments)->assertHasErrors([$message]);

    expect(Transaction::count())->toBe(0);
})->with([
    'no amount' => [fn () => ['type' => 'expense', 'account_id' => test()->eur->id], 'amount field is required'],
    'income category on an expense' => [fn () => ['type' => 'expense', 'account_id' => test()->eur->id, 'amount' => 5, 'category_id' => test()->salary->id], 'category id is invalid'],
    'transfer to the same account' => [fn () => ['type' => 'transfer', 'account_id' => test()->eur->id, 'to_account_id' => test()->eur->id, 'amount' => 5], 'must be different'],
]);

test('another user\'s accounts, categories and ids are rejected', function () {
    $other = User::factory()->create();
    $theirAccount = Account::factory()->create(['user_id' => $other->id, 'currency' => 'EUR']);
    $theirCategory = Category::factory()->create(['user_id' => $other->id, 'type' => 'expense']);
    $theirTransaction = Transaction::factory()->create(['user_id' => $other->id, 'account_id' => $theirAccount->id, 'type' => 'expense', 'amount' => 5, 'date' => '2026-05-01']);
    $theirRule = plannedPayment($other, ['installments' => 1]);
    $theirPayment = $theirRule->payments()->first();

    mcp(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $theirAccount->id, 'amount' => 5])->assertHasErrors(['account id is invalid']);
    mcp(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $this->eur->id, 'amount' => 5, 'category_id' => $theirCategory->id])->assertHasErrors(['category id is invalid']);
    mcp(Tools\UpdateTransaction::class, ['id' => $theirTransaction->id, 'amount' => 9])->assertHasErrors(['Transaction not found']);
    mcp(Tools\DeleteTransaction::class, ['id' => $theirTransaction->id])->assertHasErrors(['Transaction not found']);
    mcp(Tools\UpdateAccount::class, ['id' => $theirAccount->id, 'name' => 'x'])->assertHasErrors(['Account not found']);
    mcp(Tools\ArchiveAccount::class, ['id' => $theirAccount->id])->assertHasErrors(['Account not found']);
    mcp(Tools\UpdateCategory::class, ['id' => $theirCategory->id, 'name' => 'x'])->assertHasErrors(['Category not found']);
    mcp(Tools\DeleteCategory::class, ['id' => $theirCategory->id])->assertHasErrors(['Category not found']);
    mcp(Tools\GetPlannedPayment::class, ['id' => $theirRule->id])->assertHasErrors(['Planned payment not found']);
    mcp(Tools\UpdatePlannedPayment::class, ['id' => $theirRule->id, 'amount' => 9])->assertHasErrors(['Planned payment not found']);
    mcp(Tools\PausePlannedPayment::class, ['id' => $theirRule->id])->assertHasErrors(['Planned payment not found']);
    mcp(Tools\DeletePlannedPayment::class, ['id' => $theirRule->id])->assertHasErrors(['Planned payment not found']);

    foreach ([Tools\UpdatePayment::class, Tools\SkipPayment::class, Tools\UnskipPayment::class, Tools\MarkPaymentPaid::class, Tools\MarkPaymentUnpaid::class] as $tool) {
        mcp($tool, ['id' => $theirPayment->id])->assertHasErrors(['Payment not found']);
    }

    mcp(Tools\UpdatePayment::class, ['id' => $theirPayment->id])->assertHasErrors(['Payment not found']);
    mcp(Tools\MarkPaymentPaid::class, ['id' => plannedPayment($this->user, ['installments' => 1], $this->eur)->payments()->first()->id, 'account_id' => $theirAccount->id])->assertHasErrors(['account id is invalid']);

    expect($theirTransaction->fresh())->not->toBeNull()
        ->and($theirAccount->fresh()->trashed())->toBeFalse()
        ->and($theirRule->fresh()->is_active)->toBeTrue()
        ->and(Transaction::count())->toBe(1);
});

test('search_transactions filters, counts and includes the last day of the range', function () {
    $make = fn (array $attributes) => Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->eur->id, 'type' => 'expense', 'category_id' => $this->food->id, ...$attributes]);
    $make(['date' => '2026-05-01', 'amount' => 10, 'description' => 'Coffee']);
    $make(['date' => '2026-05-31', 'amount' => 13.70, 'description' => 'Pizza']);
    $make(['date' => '2026-06-01', 'amount' => 20, 'description' => 'Next month']);
    mcp(Tools\CreateTransaction::class, ['type' => 'transfer', 'account_id' => $this->eur->id, 'to_account_id' => $this->ron->id, 'amount' => 10, 'to_amount' => 50, 'date' => '2026-05-15']);

    mcp(Tools\SearchTransactions::class, ['date_from' => '2026-05-01', 'date_to' => '2026-05-31'])
        ->assertSee(['"total_matching":3', 'Pizza', 'Coffee'])->assertDontSee('Next month');
    mcp(Tools\SearchTransactions::class, ['search' => 'pizza'])->assertSee('"total_matching":1');
    mcp(Tools\SearchTransactions::class, ['search' => '13.7'])->assertSee('Pizza');
    mcp(Tools\SearchTransactions::class, ['account_id' => $this->ron->id])->assertSee(['"total_matching":1', '"type":"transfer"']);
    mcp(Tools\SearchTransactions::class, ['type' => 'expense', 'limit' => 1])->assertSee(['"total_matching":3', '"returned":1']);
    mcp(Tools\SearchTransactions::class, ['limit' => 500])->assertHasErrors(['limit']);
});

test('update_transaction keeps everything that was not passed', function () {
    $transaction = Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'type' => 'expense', 'amount' => 13.70, 'description' => 'Lunch', 'date' => '2026-05-02']);
    $this->eur->recalculateBalance();

    mcp(Tools\UpdateTransaction::class, ['id' => $transaction->id, 'amount' => 14])->assertOk();

    expect($transaction->fresh())->amount->toBe('14.00')->description->toBe('Lunch')->category_id->toBe($this->food->id)
        ->and($transaction->fresh()->date->toDateString())->toBe('2026-05-02')
        ->and($this->eur->fresh()->balance)->toBe('986.00');

    mcp(Tools\UpdateTransaction::class, ['id' => $transaction->id, 'category_id' => null, 'description' => null])->assertOk();

    expect($transaction->fresh())->category_id->toBeNull()->description->toBeNull();
});

test('update_transaction recalculates the received amount of a transfer when the amount changes', function () {
    $same = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR', 'balance' => 0]);
    mcp(Tools\CreateTransaction::class, ['type' => 'transfer', 'account_id' => $this->eur->id, 'to_account_id' => $same->id, 'amount' => 100])->assertOk();
    $transfer = Transaction::first();

    mcp(Tools\UpdateTransaction::class, ['id' => $transfer->id, 'amount' => 60])->assertOk();

    expect($same->fresh()->balance)->toBe('60.00')->and($this->eur->fresh()->balance)->toBe('940.00');

    // A cross-currency transfer keeps what arrived when only the description changes...
    mcp(Tools\UpdateTransaction::class, ['id' => $transfer->id, 'to_account_id' => $this->ron->id, 'to_amount' => 300])->assertOk();
    mcp(Tools\UpdateTransaction::class, ['id' => $transfer->id, 'description' => 'Savings'])->assertOk();

    expect($this->ron->fresh()->balance)->toBe('800.00')->and($same->fresh()->balance)->toBe('0.00');
});

test('update_transaction can move a transaction to another account and change its type', function () {
    $transaction = Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'type' => 'expense', 'amount' => 100, 'date' => '2026-05-02']);
    $this->eur->recalculateBalance();

    mcp(Tools\UpdateTransaction::class, ['id' => $transaction->id, 'account_id' => $this->ron->id])->assertOk();

    expect($this->eur->fresh()->balance)->toBe('1000.00')->and($this->ron->fresh()->balance)->toBe('400.00')
        ->and($transaction->fresh()->currency)->toBe('RON');

    mcp(Tools\UpdateTransaction::class, ['id' => $transaction->id, 'type' => 'transfer', 'to_account_id' => $this->eur->id, 'to_amount' => 20])->assertOk();

    expect($transaction->fresh())->type->value->toBe('transfer')->category_id->toBeNull()
        ->and($this->eur->fresh()->balance)->toBe('1020.00')->and($this->ron->fresh()->balance)->toBe('400.00');
});

test('delete_transaction removes it and corrects the balance', function () {
    mcp(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $this->eur->id, 'amount' => 40])->assertOk();

    mcp(Tools\DeleteTransaction::class, ['id' => Transaction::first()->id])->assertOk()->assertSee('"balance":1000');

    expect(Transaction::count())->toBe(0)->and($this->eur->fresh()->balance)->toBe('1000.00');
});

test('categories can be created, updated partially and deleted', function () {
    mcp(Tools\CreateCategory::class, ['name' => 'Pets', 'type' => 'expense'])->assertOk()->assertSee('Pets');
    mcp(Tools\CreateCategory::class, ['name' => 'Bad', 'type' => 'nope'])->assertHasErrors(['type']);

    $pets = Category::where('name', 'Pets')->first();
    mcp(Tools\UpdateCategory::class, ['id' => $pets->id, 'color' => '#ff0000'])->assertOk();

    expect($pets->fresh())->name->toBe('Pets')->color->toBe('#ff0000');

    mcp(Tools\DeleteCategory::class, ['id' => $pets->id])->assertOk();

    expect(Category::find($pets->id))->toBeNull();
});

test('accounts can be created, updated (currency locked once used) and archived', function () {
    mcp(Tools\CreateAccount::class, ['name' => 'Cash', 'currency' => 'eur', 'balance' => 20])->assertOk()->assertSee('"balance":20');
    mcp(Tools\CreateAccount::class, ['name' => 'Bad', 'currency' => 'EURO'])->assertHasErrors(['currency']);

    $cash = Account::where('name', 'Cash')->first();
    mcp(Tools\UpdateAccount::class, ['id' => $cash->id, 'currency' => 'RON'])->assertOk();

    expect($cash->fresh()->currency)->toBe('RON');

    mcp(Tools\UpdateAccount::class, ['id' => $this->eur->id, 'name' => 'Main'])->assertOk();
    expect($this->eur->fresh()->name)->toBe('Main');

    mcp(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $this->eur->id, 'amount' => 5])->assertOk();
    mcp(Tools\UpdateAccount::class, ['id' => $this->eur->id, 'currency' => 'RON'])->assertHasErrors(['currency cannot be changed']);

    mcp(Tools\ArchiveAccount::class, ['id' => $this->eur->id])->assertOk();

    expect($this->eur->fresh()->trashed())->toBeTrue()->and(Transaction::count())->toBe(1);
    mcp(Tools\ListAccounts::class)->assertDontSee('Main');
});

test('planned payments can be created once or repeating, updated, paused, resumed and deleted', function () {
    mcp(Tools\CreatePlannedPayment::class, ['name' => 'Insurance', 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'amount' => 120, 'repeat' => 'once', 'start_date' => '2026-06-01'])
        ->assertOk()->assertSee(['"repeat":"once"', '"installments":1', '"next_due_date":"2026-06-01"']);
    mcp(Tools\CreatePlannedPayment::class, ['name' => 'Rent', 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'amount' => 500, 'repeat' => 'months', 'start_date' => '2026-05-31', 'installments' => 3])
        ->assertOk()->assertSee('"repeat":"every 1 months"');
    mcp(Tools\CreatePlannedPayment::class, ['name' => 'No repeat', 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'amount' => 5, 'start_date' => '2026-05-31'])->assertHasErrors(['repeat']);
    mcp(Tools\CreatePlannedPayment::class, ['name' => 'Both', 'account_id' => $this->eur->id, 'category_id' => $this->food->id, 'amount' => 5, 'repeat' => 'months', 'start_date' => '2026-05-31', 'installments' => 2, 'end_date' => '2026-12-31'])->assertHasErrors(['prohibits installments']);

    $rent = RecurringPayment::where('name', 'Rent')->first();

    expect($rent->payments()->orderBy('occurrence')->pluck('due_date')->map->toDateString()->all())->toBe(['2026-05-31', '2026-06-30', '2026-07-31']);

    mcp(Tools\UpdatePlannedPayment::class, ['id' => $rent->id, 'amount' => 520])->assertOk();

    expect($rent->payments()->pluck('amount')->unique()->all())->toBe(['520.00'])
        ->and($rent->fresh())->installments->toBe(3);

    mcp(Tools\UpdatePlannedPayment::class, ['id' => $rent->id, 'installments' => null, 'end_date' => '2026-06-30'])->assertOk();

    expect($rent->payments()->count())->toBe(2);

    mcp(Tools\PausePlannedPayment::class, ['id' => $rent->id])->assertOk()->assertSee('"status":"paused"');

    expect($rent->payments()->count())->toBe(0);

    mcp(Tools\ResumePlannedPayment::class, ['id' => $rent->id])->assertOk()->assertSee('"status":"active"');

    expect($rent->payments()->count())->toBe(2);

    mcp(Tools\ListPlannedPayments::class, ['status' => 'active'])->assertSee(['Rent', 'Insurance']);
    mcp(Tools\ListPlannedPayments::class, ['status' => 'paused'])->assertDontSee('Rent');
    mcp(Tools\GetPlannedPayment::class, ['id' => $rent->id])->assertSee(['"payments":[{', '"occurrence":0', '"status":"pending"', '"statistics"']);

    mcp(Tools\DeletePlannedPayment::class, ['id' => $rent->id])->assertOk();

    expect(RecurringPayment::find($rent->id))->toBeNull()->and(Payment::where('recurring_payment_id', $rent->id)->count())->toBe(0);
});

test('a one-time planned payment can become a repeating one', function () {
    $rule = plannedPayment($this->user, ['installments' => 1, 'start_date' => '2026-05-20'], $this->eur);

    mcp(Tools\UpdatePlannedPayment::class, ['id' => $rule->id, 'repeat' => 'weeks', 'every' => 2])->assertOk()->assertSee('"repeat":"every 2 weeks"');

    expect($rule->fresh()->installments)->toBeNull()->and($rule->payments()->count())->toBeGreaterThan(20);
});

test('list_payments returns overdue, upcoming and range views', function () {
    plannedPayment($this->user, ['installments' => 1, 'start_date' => '2026-05-01', 'name' => 'Late bill'], $this->eur);
    plannedPayment($this->user, ['installments' => 1, 'start_date' => '2026-05-12', 'name' => 'Soon bill'], $this->eur);
    plannedPayment($this->user, ['installments' => 1, 'start_date' => '2026-08-01', 'name' => 'Far bill'], $this->eur);

    mcp(Tools\ListPayments::class, ['scope' => 'overdue'])->assertSee(['Late bill', '"status":"overdue"'])->assertDontSee('Soon bill');
    mcp(Tools\ListPayments::class, ['scope' => 'upcoming'])->assertSee('Soon bill')->assertDontSee(['Late bill', 'Far bill']);
    mcp(Tools\ListPayments::class, ['scope' => 'upcoming', 'days' => 120])->assertSee(['Soon bill', 'Far bill']);
    mcp(Tools\ListPayments::class, ['scope' => 'range', 'date_from' => '2026-05-01', 'date_to' => '2026-05-12'])->assertSee(['Late bill', 'Soon bill'])->assertDontSee('Far bill');
    mcp(Tools\ListPayments::class, ['scope' => 'range'])->assertHasErrors(['date from field is required']);
});

test('payments can be edited, skipped, unskipped, paid and unpaid again', function () {
    $rule = plannedPayment($this->user, ['installments' => 3, 'start_date' => '2026-05-12', 'amount' => 13.70], $this->eur);
    [$first, $second] = $rule->payments()->orderBy('occurrence')->get();

    mcp(Tools\UpdatePayment::class, ['id' => $first->id, 'amount' => 14])->assertOk()->assertSee('"amount":14');

    expect($first->fresh())->amount->toBe('14.00')->is_overridden->toBeTrue()
        ->and($second->fresh()->amount)->toBe('13.70');

    mcp(Tools\SkipPayment::class, ['id' => $second->id])->assertOk()->assertSee('"status":"skipped"');
    mcp(Tools\SkipPayment::class, ['id' => $second->id])->assertHasErrors(['already paid or skipped']);
    mcp(Tools\UnskipPayment::class, ['id' => $second->id])->assertOk()->assertSee('"status":"pending"');

    mcp(Tools\MarkPaymentPaid::class, ['id' => $first->id])->assertOk()->assertSee(['"status":"paid"', '"balance":986']);

    expect($this->eur->fresh()->balance)->toBe('986.00')
        ->and(Transaction::first())->amount->toBe('14.00')->recurring_payment_id->toBe($rule->id)
        ->and(Transaction::first()->date->toDateString())->toBe('2026-05-10');

    mcp(Tools\UpdatePayment::class, ['id' => $first->id, 'amount' => 20])->assertHasErrors(['already paid or skipped']);
    mcp(Tools\MarkPaymentUnpaid::class, ['id' => $first->id])->assertOk()->assertSee(['"status":"pending"', '"balance":1000']);

    expect($this->eur->fresh()->balance)->toBe('1000.00')->and(Transaction::count())->toBe(0);

    mcp(Tools\MarkPaymentPaid::class, ['id' => $second->id, 'amount' => 14, 'date' => '2026-05-03', 'account_id' => $this->ron->id])->assertOk();

    expect($this->ron->fresh()->balance)->toBe('486.00')
        ->and(Transaction::first()->date->toDateString())->toBe('2026-05-03');
    mcp(Tools\MarkPaymentUnpaid::class, ['id' => $first->id])->assertHasErrors(['not paid']);
});
