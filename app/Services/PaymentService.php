<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Enums\PaymentType;
use App\Enums\TransactionType;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    private const DEFAULT_MONTHS_AHEAD = 12;

    public function getPaymentsForUser(User $user): Collection
    {
        return $user->payments()
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->orderBy('is_paid')
            ->get();
    }

    public function getUpcomingPayments(User $user, ?Carbon $until = null): Collection
    {
        $until = $until ?? now()->addMonths(self::DEFAULT_MONTHS_AHEAD);

        return $user->payments()
            ->unpaid()
            ->where('due_date', '>=', now()->toDateString())
            ->where('due_date', '<=', $until->toDateString())
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get();
    }

    public function getOverduePayments(User $user): Collection
    {
        return $user->payments()
            ->overdue()
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get();
    }

    public function getPaymentsForPeriod(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return $user->payments()
            ->dueBetween($startDate->toDateString(), $endDate->toDateString())
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get();
    }

    public function createManualPayment(User $user, array $data): Payment
    {
        $paymentType = $this->determinePaymentType($data);

        return $user->payments()->create([
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'type' => $paymentType,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'is_paid' => false,
        ]);
    }

    public function generatePaymentsForRecurringPayment(
        RecurringPayment $recurringPayment,
        ?Carbon $until = null
    ): Collection {
        if (! $recurringPayment->is_active || $recurringPayment->hasEnded()) {
            return collect();
        }

        $until = $until ?? now()->addMonths(self::DEFAULT_MONTHS_AHEAD);
        $payments = collect();

        $latestPayment = $recurringPayment->payments()
            ->latest('due_date')
            ->first();

        $nextDate = $latestPayment
            ? $recurringPayment->calculateNextDate(Carbon::parse($latestPayment->due_date))
            : Carbon::parse($recurringPayment->start_date);

        $paymentType = $this->getPaymentTypeFromCategory($recurringPayment->category);
        $existingCount = $recurringPayment->payments()->count();

        while ($nextDate->lte($until)) {
            if ($recurringPayment->end_date && $nextDate->gt(Carbon::parse($recurringPayment->end_date))) {
                break;
            }

            if ($recurringPayment->installments && $existingCount >= $recurringPayment->installments) {
                break;
            }

            $existingPayment = $recurringPayment->payments()
                ->whereDate('due_date', $nextDate->toDateString())
                ->exists();

            if (! $existingPayment) {
                $payment = $recurringPayment->payments()->create([
                    'user_id' => $recurringPayment->user_id,
                    'account_id' => $recurringPayment->account_id,
                    'category_id' => $recurringPayment->category_id,
                    'type' => $paymentType,
                    'amount' => $recurringPayment->amount,
                    'currency' => $recurringPayment->currency,
                    'description' => $recurringPayment->name,
                    'due_date' => $nextDate->toDateString(),
                    'is_paid' => false,
                ]);

                $payments->push($payment);
                $existingCount++;
            }

            $nextDate = $recurringPayment->calculateNextDate($nextDate);
        }

        return $payments;
    }

    public function generateAllPaymentsForUser(User $user, ?Carbon $until = null): Collection
    {
        $until = $until ?? now()->addMonths(self::DEFAULT_MONTHS_AHEAD);
        $allPayments = collect();

        $activeRecurringPayments = $user->recurringPayments()
            ->active()
            ->notEnded()
            ->with('category')
            ->get();

        foreach ($activeRecurringPayments as $recurringPayment) {
            $payments = $this->generatePaymentsForRecurringPayment($recurringPayment, $until);
            $allPayments = $allPayments->merge($payments);
        }

        return $allPayments;
    }

    public function markAsPaid(Payment $payment, ?Carbon $paidDate = null): Transaction
    {
        if ($payment->is_paid) {
            throw new \RuntimeException('Payment is already marked as paid');
        }

        return DB::transaction(function () use ($payment, $paidDate) {
            $transactionType = match ($payment->type) {
                PaymentType::Income => TransactionType::Income,
                PaymentType::Expense => TransactionType::Expense,
            };

            $transaction = $payment->user->transactions()->create([
                'account_id' => $payment->account_id,
                'category_id' => $payment->category_id,
                'recurring_payment_id' => $payment->recurring_payment_id,
                'type' => $transactionType,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'description' => $payment->description,
                'date' => $paidDate?->toDateString() ?? $payment->due_date->toDateString(),
            ]);

            $payment->update([
                'is_paid' => true,
                'paid_at' => now(),
                'transaction_id' => $transaction->id,
            ]);

            match ($payment->type) {
                PaymentType::Income => $payment->account->increment('balance', $payment->amount),
                PaymentType::Expense => $payment->account->decrement('balance', $payment->amount),
            };

            if ($payment->recurring_payment_id) {
                $recurringPayment = $payment->recurringPayment;
                if ($recurringPayment && $recurringPayment->is_active) {
                    $this->generatePaymentsForRecurringPayment($recurringPayment);
                }
            }

            return $transaction->load(['account', 'category']);
        });
    }

    public function markAsUnpaid(Payment $payment, bool $deleteTransaction = true): void
    {
        if (! $payment->is_paid) {
            throw new \RuntimeException('Payment is already marked as unpaid');
        }

        DB::transaction(function () use ($payment, $deleteTransaction) {
            if ($payment->transaction_id && $deleteTransaction) {
                $transaction = $payment->transaction;

                match ($payment->type) {
                    PaymentType::Income => $payment->account->decrement('balance', $payment->amount),
                    PaymentType::Expense => $payment->account->increment('balance', $payment->amount),
                };

                $transaction->delete();
            }

            $payment->update([
                'is_paid' => false,
                'paid_at' => null,
                'transaction_id' => null,
            ]);
        });
    }

    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            if ($payment->is_paid && $payment->transaction_id) {
                $this->markAsUnpaid($payment, true);
            }

            $payment->delete();
        });
    }

    public function getPaymentSummaryForPeriod(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $payments = $this->getPaymentsForPeriod($user, $startDate, $endDate);

        $totalDue = $payments->where('type', PaymentType::Expense)->where('is_paid', false)->sum('amount');
        $totalExpected = $payments->where('type', PaymentType::Income)->where('is_paid', false)->sum('amount');
        $totalPaid = $payments->where('is_paid', true)->sum('amount');

        return [
            'total_due' => (float) $totalDue,
            'total_expected_income' => (float) $totalExpected,
            'total_paid' => (float) $totalPaid,
            'unpaid_count' => $payments->where('is_paid', false)->count(),
            'paid_count' => $payments->where('is_paid', true)->count(),
        ];
    }

    private function getPaymentTypeFromCategory($category): PaymentType
    {
        return match ($category->type) {
            CategoryType::Income => PaymentType::Income,
            CategoryType::Expense => PaymentType::Expense,
        };
    }

    private function determinePaymentType(array $data): PaymentType
    {
        if (isset($data['type'])) {
            return $data['type'] instanceof PaymentType ? $data['type'] : PaymentType::from($data['type']);
        }

        if (isset($data['category_id'])) {
            $category = \App\Models\Category::find($data['category_id']);
            if ($category) {
                return $this->getPaymentTypeFromCategory($category);
            }
        }

        return PaymentType::Expense;
    }
}
