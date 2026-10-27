<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringPaymentRequest;
use App\Models\RecurringPayment;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\RecurringPaymentService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecurringPaymentController extends Controller
{
    public function __construct(
        private RecurringPaymentService $recurringPaymentService,
        private AccountService $accountService,
        private CategoryService $categoryService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RecurringPayment::class);

        $user = $request->user();

        return Inertia::render('RecurringPayments/Index', [
            'recurringPayments' => $this->recurringPaymentService->getRecurringPaymentsForUser($user),
            'accounts' => $this->accountService->getAccountsForUser($user),
            'categories' => $this->categoryService->getCategoriesForUser($user),
        ]);
    }

    public function store(RecurringPaymentRequest $request): RedirectResponse
    {
        $this->recurringPaymentService->createRecurringPayment($request->user(), $request->validated());

        FlashToast::success('Planned payment created successfully.');

        return redirect()->back(fallback: route('planned-payments.index'));
    }

    public function show(Request $request, RecurringPayment $recurringPayment): Response
    {
        $this->authorize('view', $recurringPayment);

        $user = $request->user();

        return Inertia::render('RecurringPayments/Show', [
            'recurringPayment' => $recurringPayment
                ->load(['account', 'category', 'payments.account', 'payments.category', 'payments.transaction'])
                ->append('status'),
            'statistics' => $this->recurringPaymentService->getStatistics($recurringPayment),
            'accounts' => $this->accountService->getAccountsForUser($user),
            'categories' => $this->categoryService->getCategoriesForUser($user),
        ]);
    }

    public function update(RecurringPaymentRequest $request, RecurringPayment $recurringPayment): RedirectResponse
    {
        $this->recurringPaymentService->updateRecurringPayment($recurringPayment, $request->validated());

        FlashToast::success('Planned payment updated successfully.');

        return redirect()->back(fallback: route('planned-payments.index'));
    }

    public function destroy(RecurringPayment $recurringPayment): RedirectResponse
    {
        $this->authorize('delete', $recurringPayment);

        $recurringPayment->delete();

        FlashToast::success('Planned payment deleted successfully.');

        return redirect()->route('planned-payments.index');
    }
}
