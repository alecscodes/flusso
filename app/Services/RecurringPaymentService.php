<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecurringPaymentService
{
    public function __construct(
        private CurrencyService $currencyService
    ) {}

    /**
     * All rules of the user with their occurrences and a computed `status`.
     */
    public function getRecurringPaymentsForUser(User $user): Collection
    {
        return $user->recurringPayments()
            ->with(['account', 'category', 'payments'])
            ->orderBy('name')
            ->get()
            ->each->append('status');
    }

    /**
     * @param  array{account_id: int, category_id: int, name: string, amount: numeric, interval_type: string, interval_value: int, start_date: string, end_date?: string|null, installments?: int|null, is_active?: bool}  $data
     */
    public function createRecurringPayment(User $user, array $data): RecurringPayment
    {
        return DB::transaction(function () use ($user, $data) {
            $rule = $user->recurringPayments()->create($data + ['is_active' => true]);

            $this->sync($rule);

            return $rule;
        });
    }

    /**
     * Replace the rule's settings; an absent end date or installment count means "never ends".
     */
    public function updateRecurringPayment(RecurringPayment $rule, array $data): RecurringPayment
    {
        return DB::transaction(function () use ($rule, $data) {
            $rule->update($data + ['end_date' => null, 'installments' => null]);

            $this->sync($rule);

            return $rule;
        });
    }

    /**
     * Soft-delete the rule; its unpaid occurrences go, its paid history stays.
     */
    public function deleteRecurringPayment(RecurringPayment $rule): void
    {
        $rule->delete();
    }

    /**
     * Bring the rule's occurrences in line with the rule. Idempotent.
     *
     * Paid and skipped occurrences are history and never change. Unpaid ones due
     * today or later follow the rule (an occurrence edited by hand keeps its own
     * amount, date, account and description); past-due unpaid ones keep their
     * values; unpaid ones outside the schedule are removed; missing ones are created.
     */
    public function sync(RecurringPayment $rule): void
    {
        DB::transaction(function () use ($rule) {
            $rule->load(['account', 'category']);
            $today = now()->toDateString();
            $occurrences = $rule->payments()->get()->keyBy('occurrence');

            if ($rule->isPaused()) {
                $rule->payments()->pending()->where('due_date', '>=', $today)->delete();

                return;
            }

            if ($rule->currency !== $rule->account->currency) {
                $rule->forceFill(['currency' => $rule->account->currency])->saveQuietly();
            }

            $targets = $rule->targetOccurrences();
            $shared = [
                'category_id' => $rule->category_id,
                'type' => PaymentType::from($rule->category->type->value),
            ];
            $fromRule = [
                ...$shared,
                'account_id' => $rule->account_id,
                'amount' => $rule->amount,
                'currency' => $rule->account->currency,
                'description' => $rule->name,
            ];

            $occurrences->filter(fn (Payment $payment) => $payment->isPending())->each(function (Payment $payment) use ($targets, $today, $shared, $fromRule) {
                if (! isset($targets[$payment->occurrence])) {
                    $payment->delete();
                } elseif ($payment->due_date->toDateString() >= $today) {
                    $payment->update($payment->is_overridden
                        ? $shared
                        : [...$fromRule, 'due_date' => $targets[$payment->occurrence]->toDateString()]);
                }
            });

            foreach ($targets as $n => $date) {
                if (! $occurrences->has($n)) {
                    $rule->payments()->create([
                        ...$fromRule,
                        'user_id' => $rule->user_id,
                        'occurrence' => $n,
                        'due_date' => $date->toDateString(),
                    ]);
                }
            }
        });
    }

    /**
     * Daily maintenance: extends open-ended rules and applies rule edits to the schedule.
     */
    public function syncAll(): int
    {
        $count = 0;

        RecurringPayment::query()->with(['account', 'category'])->each(function (RecurringPayment $rule) use (&$count) {
            $this->sync($rule);
            $count++;
        });

        return $count;
    }

    /**
     * Statistics for a rule, in the user's primary currency.
     */
    public function getStatistics(RecurringPayment $rule): array
    {
        $payments = $rule->payments()->with('transaction')->get();
        $user = $rule->user;

        $paid = $payments->filter->isPaid();
        $pending = $payments->filter->isPending();

        return [
            'total_payments' => $payments->count(),
            'paid_payments' => $paid->count(),
            'unpaid_payments' => $pending->count(),
            'total_paid_amount' => $this->currencyService->sumInPrimaryCurrency($paid->map(fn (Payment $payment) => $payment->transaction ?? $payment), 'amount', 'currency', $user),
            'total_pending_amount' => $this->currencyService->sumInPrimaryCurrency($pending, 'amount', 'currency', $user),
            'next_due_date' => $pending->sortBy('due_date')->first()?->due_date->toDateString(),
        ];
    }
}
