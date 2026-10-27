<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private const RELATIONS = ['account', 'category', 'recurringPayment', 'transaction'];

    public function __construct(
        private CurrencyService $currencyService,
        private TransactionService $transactionService,
        private RecurringPaymentService $recurringPaymentService,
    ) {}

    /**
     * Unpaid, unskipped payments due before today.
     */
    public function getOverduePayments(User $user): Collection
    {
        return $user->payments()->overdue()->with(self::RELATIONS)->orderBy('due_date')->orderBy('id')->get();
    }

    /**
     * Unpaid, unskipped payments due from today until the end date (inclusive).
     */
    public function getUpcomingPayments(User $user, CarbonInterface $until): Collection
    {
        return $user->payments()->visible()->pending()
            ->dueBetween(now()->toDateString(), $until->toDateString())
            ->with(self::RELATIONS)->orderBy('due_date')->orderBy('id')->get();
    }

    /**
     * Every payment due between two dates (inclusive), paid and skipped ones included.
     */
    public function getPaymentsBetween(User $user, string $from, string $to): Collection
    {
        return $user->payments()->visible()->dueBetween($from, $to)->with(self::RELATIONS)->orderBy('due_date')->orderBy('id')->get();
    }

    /**
     * The Payments page: what is overdue, what falls in the current financial
     * period (paid and skipped ones included, so they can be undone) and what is later.
     *
     * @return array{overdue: Collection, period: Collection, later: Collection, monthly_totals: array<string, float>}
     */
    public function getOverview(User $user): array
    {
        $period = $user->getFinancialPeriod();
        $today = now()->toDateString();

        [$overdue, $rest] = $user->payments()->visible()
            ->where(fn ($query) => $query->where('due_date', '>=', $period['start']->toDateString())->orWhere(fn ($query) => $query->pending()))
            ->with(self::RELATIONS)->orderBy('due_date')->orderBy('id')->get()
            ->partition(fn (Payment $payment) => $payment->isPending() && $payment->due_date->toDateString() < $today);

        [$inPeriod, $later] = $rest->partition(fn (Payment $payment) => $payment->due_date->toDateString() <= $period['end']->toDateString());

        $monthlyTotals = [];

        foreach ($later->filter(fn (Payment $payment) => $payment->isPending() && $payment->type === PaymentType::Expense) as $payment) {
            $month = $payment->due_date->format('Y-m');
            $monthlyTotals[$month] = ($monthlyTotals[$month] ?? 0) + ($this->currencyService->convertToPrimaryCurrency((float) $payment->amount, $payment->currency, $user) ?? 0);
        }

        return ['overdue' => $overdue->values(), 'period' => $inPeriod->values(), 'later' => $later->values(), 'monthly_totals' => $monthlyTotals];
    }

    /**
     * What is still to pay and what has been paid in a period, in the primary currency.
     */
    public function getPaymentSummaryForPeriod(User $user, CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $payments = $user->payments()->visible()
            ->dueBetween($startDate->toDateString(), $endDate->toDateString())
            ->with('transaction')->get();

        $pending = $payments->filter->isPending();
        $due = $pending->where('type', PaymentType::Expense);
        $expected = $pending->where('type', PaymentType::Income);
        $paid = $payments->filter->isPaid();

        return [
            'total_due' => $this->currencyService->sumInPrimaryCurrency($due, 'amount', 'currency', $user),
            'total_expected_income' => $this->currencyService->sumInPrimaryCurrency($expected, 'amount', 'currency', $user),
            'total_paid' => $this->currencyService->sumInPrimaryCurrency($paid->map(fn (Payment $payment) => $payment->transaction ?? $payment), 'amount', 'currency', $user),
            'unpaid_count' => $pending->count(),
            'paid_count' => $paid->count(),
            'primary_currency' => $this->currencyService->getPrimaryCurrency($user),
        ];
    }

    /**
     * Pay an occurrence: a transaction is booked through the ledger (the owner may
     * pay another amount, on another date or from another account) and linked.
     *
     * @param  array{amount?: numeric|null, date?: string|null, account_id?: int|null}  $data
     */
    public function markAsPaid(Payment $payment, array $data = []): Transaction
    {
        $this->ensurePending($payment);

        return DB::transaction(function () use ($payment, $data) {
            $transaction = $this->transactionService->createTransaction($payment->user, [
                'account_id' => $data['account_id'] ?? $payment->account_id,
                'category_id' => $payment->category_id,
                'recurring_payment_id' => $payment->recurring_payment_id,
                'type' => $payment->type->value,
                'amount' => $data['amount'] ?? $payment->amount,
                'date' => $data['date'] ?? now()->toDateString(),
                'description' => $payment->description,
            ]);

            $payment->update(['transaction_id' => $transaction->id]);

            return $transaction;
        });
    }

    /**
     * Re-open a paid occurrence; deleting the transaction (observer) clears the link.
     */
    public function markAsUnpaid(Payment $payment): void
    {
        throw_unless($payment->isPaid(), ValidationException::withMessages(['payment' => 'This payment is not paid.']));

        $this->transactionService->deleteTransaction($payment->transaction);
    }

    /**
     * Edit one occurrence by hand; the schedule then leaves it alone.
     *
     * @param  array{amount: numeric, due_date: string, account_id: int, description?: string|null}  $data
     */
    public function updatePayment(Payment $payment, array $data): Payment
    {
        $this->ensurePending($payment);

        $payment->update([...$data, 'description' => $data['description'] ?? null, 'is_overridden' => true]);

        return $payment;
    }

    public function skip(Payment $payment): void
    {
        $this->ensurePending($payment);

        $payment->update(['skipped_at' => now()]);
    }

    public function unskip(Payment $payment): void
    {
        throw_if($payment->skipped_at === null, ValidationException::withMessages(['payment' => 'This payment is not skipped.']));

        $payment->update(['skipped_at' => null]);
        $this->recurringPaymentService->sync($payment->recurringPayment);
    }

    /**
     * Only a payment that runs once can be deleted (its rule goes with it); a
     * repeating one would simply be generated again, so it has to be skipped.
     */
    public function deletePayment(Payment $payment): void
    {
        $this->ensurePending($payment);

        throw_unless(
            $payment->recurringPayment->installments === 1,
            ValidationException::withMessages(['payment' => 'A repeating payment cannot be deleted. Skip it instead.']),
        );

        $this->recurringPaymentService->deleteRecurringPayment($payment->recurringPayment);
    }

    private function ensurePending(Payment $payment): void
    {
        throw_unless($payment->isPending(), ValidationException::withMessages(['payment' => 'This payment is already paid or skipped.']));
    }
}
