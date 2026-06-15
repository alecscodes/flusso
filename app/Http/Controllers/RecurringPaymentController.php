<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringPaymentStoreRequest;
use App\Http\Requests\RecurringPaymentUpdateRequest;
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

    public function store(RecurringPaymentStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', RecurringPayment::class);

        $this->recurringPaymentService->createRecurringPayment($request->user(), $request->validated());

        FlashToast::success('Planned payment created successfully.');

        return redirect()->route('recurring-payments.index');
    }

    public function show(RecurringPayment $recurringPayment): Response
    {
        $this->authorize('view', $recurringPayment);

        return Inertia::render('RecurringPayments/Show', [
            'recurringPayment' => $recurringPayment->load(['account', 'category', 'payments']),
            'statistics' => $this->recurringPaymentService->getStatistics($recurringPayment),
        ]);
    }

    public function update(RecurringPaymentUpdateRequest $request, RecurringPayment $recurringPayment): RedirectResponse
    {
        $this->authorize('update', $recurringPayment);

        $this->recurringPaymentService->updateRecurringPayment($recurringPayment, $request->validated());

        FlashToast::success('Planned payment updated successfully.');

        return redirect()->route('recurring-payments.index');
    }

    public function destroy(RecurringPayment $recurringPayment): RedirectResponse
    {
        $this->authorize('delete', $recurringPayment);

        $this->recurringPaymentService->deleteRecurringPayment($recurringPayment);

        FlashToast::success('Planned payment deleted successfully.');

        return redirect()->route('recurring-payments.index');
    }
}
