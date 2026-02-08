<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionStoreRequest;
use App\Http\Requests\TransactionUpdateRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private AccountService $accountService,
        private CategoryService $categoryService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Transaction::class);

        $user = $request->user();

        $query = $user->transactions()
            ->with(['account', 'category', 'linkedTransaction'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%'.$search.'%')
                    ->orWhere('amount', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('account_id')) {
            $query->forAccount((int) $request->input('account_id'));
        }

        if ($request->filled('category_id')) {
            $query->forCategory((int) $request->input('category_id'));
        }

        $type = $request->input('type');
        if (is_string($type) && $type !== '' && in_array($type, ['income', 'expense', 'transfer'], true)) {
            $query->where('type', $type);
        }

        $transactions = $query->paginate(50)->withQueryString();

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => $this->accountService->getAccountsForUser($user),
            'categories' => $this->categoryService->getCategoriesForUser($user),
        ]);
    }

    public function store(TransactionStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Transaction::class);

        $this->transactionService->createTransaction($request->user(), $request->validated());

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction created successfully.');
    }

    public function show(Transaction $transaction): Response
    {
        $this->authorize('view', $transaction);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction->load(['account', 'category', 'linkedTransaction']),
        ]);
    }

    public function update(TransactionUpdateRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        $this->transactionService->updateTransaction($transaction, $request->validated());

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction updated successfully.');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $this->authorize('delete', $transaction);

        $this->transactionService->deleteTransaction($transaction);

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction deleted successfully.');
    }

    public function transfer(TransferRequest $request): RedirectResponse
    {
        $this->authorize('create', Transaction::class);

        try {
            $this->transactionService->createTransfer($request->user(), $request->validated());

            return redirect()->route('transactions.index')
                ->with('success', 'Transfer completed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => $e->getMessage()]);
        }
    }
}
