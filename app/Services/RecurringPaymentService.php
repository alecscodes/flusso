<?php

namespace App\Services;

use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecurringPaymentService
{
    public function __construct(
        private PaymentService $paymentService,
        private CurrencyService $currencyService
    ) {}

    /**
     * Get all recurring payments for a user.
     */
    public function getRecurringPaymentsForUser(User $user): Collection
    {
        return $user->recurringPayments()
            ->with(['account', 'category'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get active recurring payments for a user.
     */
    public function getActiveRecurringPayments(User $user): Collection
    {
        return $user->recurringPayments()
            ->active()
            ->notEnded()
            ->with(['account', 'category'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new recurring payment.
     */
    public function createRecurringPayment(User $user, array $data): RecurringPayment
    {
        return DB::transaction(function () use ($user, $data) {
            $recurringPayment = $user->recurringPayments()->create([
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'amount' => $data['amount'],
                'currency' => strtoupper($data['currency']),
                'interval_type' => $data['interval_type'],
                'interval_value' => $data['interval_value'] ?? 1,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'installments' => $data['installments'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($recurringPayment->is_active) {
                $this->paymentService->generatePaymentsForRecurringPayment($recurringPayment);
            }

            return $recurringPayment;
        })->load(['account', 'category', 'payments']);
    }

    /**
     * Update an existing recurring payment.
     */
    public function updateRecurringPayment(RecurringPayment $recurringPayment, array $data): RecurringPayment
    {
        return DB::transaction(function () use ($recurringPayment, $data) {
            $recurringPayment->update([
                'account_id' => $data['account_id'] ?? $recurringPayment->account_id,
                'category_id' => $data['category_id'] ?? $recurringPayment->category_id,
                'name' => $data['name'] ?? $recurringPayment->name,
                'amount' => $data['amount'] ?? $recurringPayment->amount,
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $recurringPayment->currency,
                'interval_type' => $data['interval_type'] ?? $recurringPayment->interval_type,
                'interval_value' => $data['interval_value'] ?? $recurringPayment->interval_value,
                'start_date' => $data['start_date'] ?? $recurringPayment->start_date,
                'end_date' => $data['end_date'] ?? $recurringPayment->end_date,
                'installments' => $data['installments'] ?? $recurringPayment->installments,
                'is_active' => $data['is_active'] ?? $recurringPayment->is_active,
            ]);

            // Regenerate all future payments if the recurring payment is active
            if ($recurringPayment->is_active) {
                $this->regeneratePayments($recurringPayment);
            }

            return $recurringPayment->fresh();
        });
    }

    /**
     * Delete a recurring payment and its associated data.
     */
    public function deleteRecurringPayment(RecurringPayment $recurringPayment): void
    {
        DB::transaction(function () use ($recurringPayment) {
            $recurringPayment->payments()->delete();
            $recurringPayment->delete();
        });
    }

    /**
     * Deactivate a recurring payment.
     */
    public function deactivate(RecurringPayment $recurringPayment): RecurringPayment
    {
        return DB::transaction(function () use ($recurringPayment) {
            $recurringPayment->update(['is_active' => false]);
            $recurringPayment->payments()->unpaid()->delete();

            return $recurringPayment->fresh();
        });
    }

    public function activate(RecurringPayment $recurringPayment): RecurringPayment
    {
        return $this->updateRecurringPayment($recurringPayment, ['is_active' => true]);
    }

    /**
     * Regenerate all future payments for a recurring payment.
     * This deletes all unpaid payments and creates new ones based on current settings.
     */
    public function regeneratePayments(RecurringPayment $recurringPayment): Collection
    {
        if (! $recurringPayment->is_active || $recurringPayment->hasEnded()) {
            return collect();
        }

        return DB::transaction(function () use ($recurringPayment) {
            // Hard delete all unpaid payments for this recurring payment to avoid unique constraint issues
            $recurringPayment->payments()
                ->where('is_paid', false)
                ->forceDelete();

            // Generate new payments based on current settings
            return $this->paymentService->generatePaymentsForRecurringPayment($recurringPayment);
        });
    }

    /**
     * Get statistics for a recurring payment with currency conversion.
     */
    public function getStatistics(RecurringPayment $recurringPayment): array
    {
        $payments = $recurringPayment->payments;
        $user = $recurringPayment->user;

        $paidPayments = $payments->where('is_paid', true);
        $unpaidPayments = $payments->where('is_paid', false);

        $totalPaidAmount = $this->currencyService->sumInPrimaryCurrency($paidPayments, 'amount', 'currency', $user);
        $totalPendingAmount = $this->currencyService->sumInPrimaryCurrency($unpaidPayments, 'amount', 'currency', $user);

        return [
            'total_payments' => $payments->count(),
            'paid_payments' => $paidPayments->count(),
            'unpaid_payments' => $unpaidPayments->count(),
            'total_paid_amount' => $totalPaidAmount,
            'total_pending_amount' => $totalPendingAmount,
            'next_due_date' => $recurringPayment->getNextDueDate()?->toDateString(),
        ];
    }
}
