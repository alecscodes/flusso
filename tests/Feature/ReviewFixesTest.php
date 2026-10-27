<?php

use App\Mcp\Servers\FlussoServer;
use App\Mcp\Tools;
use App\Models\Account;
use App\Models\Category;
use App\Models\CurrencyExchangeRate;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionFile;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\CurrencyService;
use App\Services\PaymentService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

test('when the rate API is down the latest stored rate is used and the failure is remembered', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $currency = app(CurrencyService::class);

    CurrencyExchangeRate::create(['from_currency' => 'eur', 'to_currency' => 'ron', 'rate' => 4.9, 'date' => '2020-01-01']);
    CurrencyExchangeRate::create(['from_currency' => 'eur', 'to_currency' => 'ron', 'rate' => 5.0, 'date' => '2020-02-01']);

    expect($currency->getRate('EUR', 'RON'))->toBe(5.0);
    $calls = count(Http::recorded());
    expect($calls)->toBe(2)
        ->and($currency->getRate('EUR', 'RON'))->toBe(5.0)
        ->and(count(Http::recorded()))->toBe($calls);

    // Nothing stored either: still only one attempt per outage.
    expect($currency->getRate('EUR', 'USD'))->toBeNull();
    $calls = count(Http::recorded());
    expect($currency->getRate('EUR', 'USD'))->toBeNull()
        ->and(count(Http::recorded()))->toBe($calls);
});

test('a transaction on an archived account can be edited but not moved to another archived account', function () {
    $user = User::factory()->create();
    $old = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $other = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $transaction = app(TransactionService::class)->createTransaction($user, ['type' => 'expense', 'account_id' => $old->id, 'amount' => 10]);
    $old->delete();
    $other->delete();

    $payload = fn (array $overrides = []) => ['type' => 'expense', 'account_id' => $old->id, 'amount' => 25, 'date' => now()->toDateString(), ...$overrides];

    actingAs($user)->put(route('transactions.update', $transaction), $payload())->assertSessionHasNoErrors();
    expect($transaction->fresh()->amount)->toBe('25.00')
        ->and($old->fresh()->balance)->toBe('75.00');

    actingAs($user)->put(route('transactions.update', $transaction), $payload(['account_id' => $other->id]))->assertSessionHasErrors('account_id');
    actingAs($user)->post(route('transactions.store'), $payload())->assertSessionHasErrors('account_id');
});

test('update_transaction works on an archived account too', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $transaction = app(TransactionService::class)->createTransaction($user, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 10]);
    $account->delete();

    FlussoServer::actingAs($user)->tool(Tools\UpdateTransaction::class, ['id' => $transaction->id, 'amount' => 30])->assertOk();

    expect($transaction->fresh()->amount)->toBe('30.00');
});

test('between accounts of one currency exactly what left arrives', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $to = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 0]);

    $viaService = app(TransactionService::class)->createTransaction($user, [
        'type' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 50, 'to_amount' => 500,
    ]);
    FlussoServer::actingAs($user)->tool(Tools\CreateTransaction::class, [
        'type' => 'transfer', 'account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 20, 'to_amount' => 200,
    ])->assertOk();

    expect($viaService->to_amount)->toBe('50.00')
        ->and($to->fresh()->balance)->toBe('70.00')
        ->and($from->fresh()->balance)->toBe('30.00');
});

test('paying from an account in another currency needs the amount', function () {
    $this->travelTo('2026-05-10 12:00:00');
    $user = User::factory()->create();
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 500]);
    $payment = plannedPayment($user, ['installments' => 1, 'start_date' => '2026-05-12', 'amount' => 20])->payments()->first();

    actingAs($user)->patch(route('payments.mark-paid', $payment), ['account_id' => $ron->id])->assertSessionHasErrors('amount');
    FlussoServer::actingAs($user)->tool(Tools\MarkPaymentPaid::class, ['id' => $payment->id, 'account_id' => $ron->id])->assertHasErrors(['amount field is required']);
    expect(Transaction::count())->toBe(0);

    actingAs($user)->patch(route('payments.mark-paid', $payment), ['account_id' => $ron->id, 'amount' => 100])->assertSessionHasNoErrors();
    expect($ron->fresh()->balance)->toBe('400.00');
});

test('paying, skipping and unskipping the same stale payment twice only works once', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 2]);
    $first = Payment::where('occurrence', 0)->sole();
    $second = Payment::where('occurrence', 1)->sole();
    $service = app(PaymentService::class);

    $staleCopy = Payment::find($first->id);
    $service->markAsPaid($first);

    expect(fn () => $service->markAsPaid($staleCopy))->toThrow(ValidationException::class)
        ->and(Transaction::count())->toBe(1)
        ->and($rule->account->fresh()->balance)->toBe('990.00');

    $staleCopy = Payment::find($second->id);
    $service->skip($second);
    expect(fn () => $service->skip($staleCopy))->toThrow(ValidationException::class);

    $staleCopy = Payment::find($second->id);
    $service->unskip($second);
    expect(fn () => $service->unskip($staleCopy))->toThrow(ValidationException::class)
        ->and($second->fresh()->skipped_at)->toBeNull();
});

test('attached files are removed only when a transaction is deleted for good', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $transaction = app(TransactionService::class)->createTransaction($user, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 5]);
    Storage::disk('local')->put('receipts/a.pdf', 'x');
    TransactionFile::create(['transaction_id' => $transaction->id, 'filename' => 'a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'path' => 'receipts/a.pdf']);

    app(TransactionService::class)->deleteTransaction($transaction);
    expect(Storage::disk('local')->exists('receipts/a.pdf'))->toBeTrue()
        ->and(TransactionFile::count())->toBe(1);

    $transaction->restore();
    $transaction->forceDelete();
    expect(Storage::disk('local')->exists('receipts/a.pdf'))->toBeFalse()
        ->and(TransactionFile::count())->toBe(0);
});

test('changing a category\'s type changes the type of the pending payments that use it', function () {
    $user = User::factory()->create();
    $rule = plannedPayment($user, ['installments' => 3, 'start_date' => now()->addDay()->toDateString()]);

    app(CategoryService::class)->updateCategory($rule->category, ['type' => 'income']);

    expect($rule->payments()->pluck('type')->map->value->unique()->all())->toBe(['income']);
});

test('a payment still has its category after the category is deleted', function () {
    $user = User::factory()->create();
    $payment = plannedPayment($user, ['installments' => 1])->payments()->first();
    $payment->category->delete();

    expect($payment->fresh()->category)->not->toBeNull();
});

test('amounts with more than two decimals are rejected', function () {
    $this->travelTo('2026-05-10 12:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $rule = plannedPayment($user, ['installments' => 1, 'start_date' => '2026-05-12'], $account);
    $payment = $rule->payments()->first();
    $today = now()->toDateString();

    actingAs($user)->post(route('transactions.store'), ['type' => 'expense', 'account_id' => $account->id, 'amount' => 13.705, 'date' => $today])->assertSessionHasErrors('amount');
    actingAs($user)->patch(route('payments.update', $payment), ['amount' => 13.705, 'due_date' => $today, 'account_id' => $account->id])->assertSessionHasErrors('amount');
    actingAs($user)->patch(route('payments.mark-paid', $payment), ['amount' => 13.705])->assertSessionHasErrors('amount');
    actingAs($user)->post(route('recurring-payments.store'), [
        'name' => 'x', 'account_id' => $account->id, 'category_id' => $category->id, 'amount' => 13.705,
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => $today,
    ])->assertSessionHasErrors('amount');
    actingAs($user)->post(route('accounts.store'), ['name' => 'x', 'currency' => 'EUR', 'balance' => 1.005])->assertSessionHasErrors('balance');
    FlussoServer::actingAs($user)->tool(Tools\CreateTransaction::class, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 13.705])->assertHasErrors(['amount field must have 0-2 decimal places']);

    actingAs($user)->post(route('transactions.store'), ['type' => 'expense', 'account_id' => $account->id, 'amount' => 14, 'date' => $today])->assertSessionHasNoErrors();
});
