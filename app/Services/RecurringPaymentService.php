<?php

namespace App\Services;

use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecurringPaymentService
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    public function getRecurringPaymentsForUser(User $user): Collection
    {
        return $user->recurringPayments()
            ->with(['account', 'category'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function getActiveRecurringPayments(User $user): Collection
    {
        return $user->recurringPayments()
            ->active()
            ->notEnded()
            ->with(['account', 'category'])
            ->orderBy('name')
            ->get();
    }

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

            return $recurringPayment->load(['account', 'category', 'payments']);
        });
    }

    public function updateRecurringPayment(RecurringPayment $recurringPayment, array $data): RecurringPayment
    {
        return DB::transaction(function () use ($recurringPayment, $data) {
            $wasActive = $recurringPayment->is_active;
            $isNowActive = $data['is_active'] ?? $recurringPayment->is_active;

            $recurringPayment->update([
                'account_id' => $data['account_id'] ?? $recurringPayment->account_id,
                'category_id' => $data['category_id'] ?? $recurringPayment->category_id,
                'name' => $data['name'] ?? $recurringPayment->name,
                'amount' => $data['amount'] ?? $recurringPayment->amount,
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $recurringPayment->currency,
                'interval_type' => $data['interval_type'] ?? $recurringPayment->interval_type,
                'interval_value' => $data['interval_value'] ?? $recurringPayment->interval_value,
                'start_date' => $data['start_date'] ?? $recurringPayment->start_date,
                'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $recurringPayment->end_date,
                'installments' => array_key_exists('installments', $data) ? $data['installments'] : $recurringPayment->installments,
                'is_active' => $isNowActive,
            ]);

            $recurringPayment->refresh();

            if ($isNowActive) {
                $this->updateFuturePayments($recurringPayment);
                $this->paymentService->generatePaymentsForRecurringPayment($recurringPayment);
            } elseif ($wasActive && ! $isNowActive) {
                $this->deleteUnpaidPayments($recurringPayment);
            }

            return $recurringPayment->load(['account', 'category', 'payments']);
        });
    }

    public function deleteRecurringPayment(RecurringPayment $recurringPayment): void
    {
        DB::transaction(function () use ($recurringPayment) {
            $this->deleteUnpaidPayments($recurringPayment);
            $recurringPayment->delete();
        });
    }

    public function deactivate(RecurringPayment $recurringPayment): RecurringPayment
    {
        return $this->updateRecurringPayment($recurringPayment, ['is_active' => false]);
    }

    public function activate(RecurringPayment $recurringPayment): RecurringPayment
    {
        return $this->updateRecurringPayment($recurringPayment, ['is_active' => true]);
    }

    public function regeneratePayments(RecurringPayment $recurringPayment): Collection
    {
        if (! $recurringPayment->is_active) {
            return collect();
        }

        return $this->paymentService->generatePaymentsForRecurringPayment($recurringPayment);
    }

    private function deleteUnpaidPayments(RecurringPayment $recurringPayment): void
    {
        $recurringPayment->payments()
            ->where('is_paid', false)
            ->delete();
    }

    private function updateFuturePayments(RecurringPayment $recurringPayment): void
    {
        $recurringPayment->payments()
            ->where('is_paid', false)
            ->update([
                'account_id' => $recurringPayment->account_id,
                'category_id' => $recurringPayment->category_id,
                'amount' => $recurringPayment->amount,
                'currency' => $recurringPayment->currency,
                'description' => $recurringPayment->name,
            ]);
    }

    public function getStatistics(RecurringPayment $recurringPayment): array
    {
        $payments = $recurringPayment->payments;

        return [
            'total_payments' => $payments->count(),
            'paid_payments' => $payments->where('is_paid', true)->count(),
            'unpaid_payments' => $payments->where('is_paid', false)->count(),
            'total_paid_amount' => (float) $payments->where('is_paid', true)->sum('amount'),
            'total_pending_amount' => (float) $payments->where('is_paid', false)->sum('amount'),
            'next_due_date' => $recurringPayment->getNextDueDate()?->toDateString(),
        ];
    }
}
