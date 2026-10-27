<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkPaymentPaidRequest;
use App\Http\Requests\PaymentUpdateRequest;
use App\Models\Payment;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\PaymentService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private AccountService $accountService,
        private CategoryService $categoryService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        $user = $request->user();
        $balances = $this->accountService->getAccountSummary($user);
        $overview = $this->paymentService->getOverview($user, $balances['availableBalance']);

        return Inertia::render('Payments/Index', [
            'overduePayments' => $overview['overdue'],
            'periodPayments' => $overview['period'],
            'laterPayments' => $overview['later'],
            'monthlyTotals' => $overview['monthly_totals'],
            'accounts' => $balances['accounts'],
            'categories' => $this->categoryService->getCategoriesForUser($user),
            'totals' => $overview['totals'],
        ]);
    }

    public function update(PaymentUpdateRequest $request, Payment $payment): RedirectResponse
    {
        $this->paymentService->updatePayment($payment, $request->validated());

        FlashToast::success('Payment updated.');

        return redirect()->back();
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        $this->paymentService->deletePayment($payment);

        FlashToast::success('Payment deleted.');

        return redirect()->route('payments.index');
    }

    public function skip(Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        $this->paymentService->skip($payment);

        FlashToast::success('Payment skipped.');

        return redirect()->back();
    }

    public function unskip(Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        $this->paymentService->unskip($payment);

        FlashToast::success('Payment restored.');

        return redirect()->back();
    }

    public function markPaid(MarkPaymentPaidRequest $request, Payment $payment): RedirectResponse
    {
        $this->paymentService->markAsPaid($payment, $request->validated());

        FlashToast::success('Payment marked as paid.');

        return redirect()->back();
    }

    public function markUnpaid(Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        $this->paymentService->markAsUnpaid($payment);

        FlashToast::success('Payment marked as unpaid.');

        return redirect()->back();
    }
}
