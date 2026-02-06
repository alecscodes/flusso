<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentStoreRequest;
use App\Models\Payment;
use App\Services\AccountService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private AccountService $accountService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        $user = $request->user();

        $this->paymentService->generateAllPaymentsForUser($user);

        $period = $user->getFinancialPeriod();
        $paymentSummary = $this->paymentService->getPaymentSummaryForPeriod(
            $user,
            $period['start'],
            $period['end']
        );
        $totalBalance = $this->accountService->getTotalBalance($user);
        $paymentSummary['balance_after_planned'] = $totalBalance - $paymentSummary['total_due'];

        $overduePayments = $this->paymentService->getOverduePayments($user);
        $overdueTotal = (float) $overduePayments->sum('amount');

        $upcomingPayments = $this->paymentService->getUpcomingPayments($user);
        $upcomingTotal = (float) $upcomingPayments->sum('amount');

        $primaryCurrency = $user->accounts()->first()?->currency ?? 'EUR';

        return Inertia::render('Payments/Index', [
            'payments' => $this->paymentService->getPaymentsForUser($user),
            'overduePayments' => $overduePayments,
            'upcomingPayments' => $upcomingPayments,
            'totals' => [
                'total_balance' => $totalBalance,
                'overdue_amount' => $overdueTotal,
                'upcoming_amount' => $upcomingTotal,
                'total_due_this_period' => $paymentSummary['total_due'],
                'balance_after_planned' => $paymentSummary['balance_after_planned'],
                'primary_currency' => $primaryCurrency,
            ],
        ]);
    }

    public function store(PaymentStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $this->paymentService->createManualPayment($request->user(), $request->validated());

        return redirect()->route('payments.index')
            ->with('success', 'Payment created successfully.');
    }

    public function markPaid(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        try {
            $this->paymentService->markAsPaid($payment);

            return redirect()->back()
                ->with('success', 'Payment marked as paid.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function markUnpaid(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        try {
            $deleteTransaction = $request->boolean('delete_transaction', true);
            $this->paymentService->markAsUnpaid($payment, $deleteTransaction);

            return redirect()->back()
                ->with('success', 'Payment marked as unpaid.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }
}
