<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /** Enough to list a pending occurrence; paid ones also need their transaction. */
    private const RELATIONS = ['account', 'category', 'recurringPayment'];

    public function __construct(
        private CurrencyService $currencyService,
        private TransactionService $transactionService,
        private RecurringPaymentService $recurringPaymentService,
    ) {}

    /**
     * Unpaid, unskipped payments due before today.
     *
     * @return Collection<int, Payment>
     */
    public function getOverduePayments(User $user): Collection
    {
        return $this->inDueOrder($user->payments()->overdue());
    }

    /**
     * Unpaid, unskipped payments due from today until the end date (inclusive).
     *
     * @return Collection<int, Payment>
     */
    public function getUpcomingPayments(User $user, CarbonInterface $until): Collection
    {
        return $this->inDueOrder($user->payments()->visible()->pending()->dueBetween(now()->toDateString(), $until->toDateString()));
    }

    /**
     * Every payment due between two dates (inclusive), paid and skipped ones included.
     *
     * @return Collection<int, Payment>
     */
    public function getPaymentsBetween(User $user, string $from, string $to): Collection
    {
        return $this->inDueOrder($user->payments()->visible()->dueBetween($from, $to), ['transaction']);
    }

    /**
     * What is still to pay in these payments (pending expenses), in the primary currency.
     *
     * @param  Collection<int, Payment>  $payments
     */
    public function totalDue(User $user, Collection $payments): float
    {
        return $this->currencyService->sumInPrimaryCurrency(
            $payments->filter(fn (Payment $payment) => $payment->isPending() && $payment->type === PaymentType::Expense),
            'amount',
            'currency',
            $user,
        );
    }

    /**
     * The Payments page: what is overdue, what falls in the current financial
     * period (paid and skipped ones included, so they can be undone), what is later,
     * and the totals that go with them.
     *
     * @return array{overdue: Collection<int, Payment>, period: Collection<int, Payment>, later: Collection<int, Payment>, monthly_totals: array<string, float>, totals: array<string, float|int|string>}
     */
    public function getOverview(User $user, float $availableBalance): array
    {
        $period = $user->getFinancialPeriod();
        $today = now()->toDateString();

        [$overdue, $rest] = $this->inDueOrder($user->payments()->visible()->forOverview($period['start']->toDateString()), ['transaction'])
            ->partition(fn (Payment $payment) => $payment->isPending() && $payment->due_date->toDateString() < $today);

        [$inPeriod, $later] = $rest->partition(fn (Payment $payment) => $payment->due_date->toDateString() <= $period['end']->toDateString());

        $monthlyTotals = $later
            ->filter(fn (Payment $payment) => $payment->isPending() && $payment->type === PaymentType::Expense)
            ->groupBy(fn (Payment $payment) => $payment->due_date->format('Y-m'))
            ->map(fn (Collection $payments) => $this->totalDue($user, $payments))
            ->all();

        $upcoming = $inPeriod->concat($later);
        $overdueAmount = $this->totalDue($user, $overdue);
        $periodAmount = $this->totalDue($user, $inPeriod);

        return [
            'overdue' => $overdue->values(),
            'period' => $inPeriod->values(),
            'later' => $later->values(),
            'monthly_totals' => $monthlyTotals,
            'totals' => [
                'available_balance' => $availableBalance,
                'overdue_amount' => $overdueAmount,
                'overdue_count' => $overdue->count(),
                'upcoming_amount' => $this->totalDue($user, $upcoming),
                'upcoming_count' => $upcoming->filter->isPending()->count(),
                'period_amount' => $periodAmount,
                'balance_after_planned' => $availableBalance - $overdueAmount - $periodAmount,
                'primary_currency' => $this->currencyService->getPrimaryCurrency($user),
            ],
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

            $this->claim($payment, ['transaction_id' => $transaction->id]);

            return $transaction;
        });
    }

    /**
     * Re-open a paid occurrence; deleting the transaction (observer) clears the link.
     */
    public function markAsUnpaid(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $transaction = $payment->transaction;

            throw_if($transaction === null, ValidationException::withMessages(['payment' => 'This payment is not paid.']));

            $this->transactionService->deleteTransaction($transaction);
        });
    }

    /**
     * Edit one occurrence by hand; the schedule then leaves it alone.
     *
     * @param  array{amount: numeric, due_date: string, account_id: int, description?: string|null}  $data
     */
    public function updatePayment(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $payment->refresh();
            $this->ensurePending($payment);

            $payment->update([...$data, 'description' => $data['description'] ?? null, 'is_overridden' => true]);

            return $payment;
        });
    }

    public function skip(Payment $payment): void
    {
        DB::transaction(fn () => $this->claim($payment, ['skipped_at' => now()]));
    }

    public function unskip(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $unskipped = Payment::whereKey($payment->id)->whereNotNull('skipped_at')->update(['skipped_at' => null]);
            throw_if($unskipped === 0, ValidationException::withMessages(['payment' => 'This payment is not skipped.']));

            $this->recurringPaymentService->sync($payment->recurringPayment);
        });
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

        $payment->recurringPayment->delete();
    }

    /**
     * @param  HasMany<Payment, User>  $query
     * @param  array<int, string>  $with  relations on top of RELATIONS
     * @return Collection<int, Payment>
     */
    private function inDueOrder(HasMany $query, array $with = []): Collection
    {
        return $query->with([...self::RELATIONS, ...$with])->orderBy('due_date')->orderBy('id')->get();
    }

    /**
     * Move a pending payment to paid or skipped. The update itself only matches a
     * payment that is still pending, so whichever request lost the race aborts.
     *
     * @param  array<string, mixed>  $values
     */
    private function claim(Payment $payment, array $values): void
    {
        $claimed = Payment::whereKey($payment->id)->pending()->update($values);

        throw_if($claimed === 0, ValidationException::withMessages(['payment' => 'This payment is already paid or skipped.']));

        $payment->refresh();
    }

    private function ensurePending(Payment $payment): void
    {
        throw_unless($payment->isPending(), ValidationException::withMessages(['payment' => 'This payment is already paid or skipped.']));
    }
}
