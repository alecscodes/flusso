<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionStoreRequest;
use App\Http\Requests\TransactionUpdateRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Transaction;
use App\Models\TransactionFile;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\CurrencyService;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private AccountService $accountService,
        private CategoryService $categoryService,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Transaction::class);

        $user = $request->user();

        // Base query with relationships
        $query = $user->transactions()
            ->with(['account', 'category', 'linkedTransaction', 'from_account', 'to_account', 'files'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        // Apply filters
        $this->applyTransactionFilters($query, $request);

        $transactions = $query->paginate(50)->withQueryString();

        // Get category spending for filtered period
        [$categorySpending, $hasDateFilters] = $this->getCategorySpending($user, $request);

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => $this->accountService->getAccountsForUser($user),
            'categories' => $this->categoryService->getCategoriesForUser($user),
            'categorySpending' => $categorySpending,
            'hasDateFilters' => $hasDateFilters,
        ]);
    }

    private function applyTransactionFilters($query, Request $request): void
    {
        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%'.$search.'%')
                    ->orWhere('amount', 'like', '%'.$search.'%');
            });
        }

        // Account filter
        if ($request->filled('account_id')) {
            $query->forAccount((int) $request->input('account_id'));
        }

        // Category filter
        if ($request->filled('category_id')) {
            $query->forCategory((int) $request->input('category_id'));
        }

        // Type filter
        $type = $request->input('type');
        if (is_string($type) && $type !== '' && in_array($type, ['income', 'expense', 'transfer'], true)) {
            $query->where('type', $type);
        }

        // Date range filters
        if ($request->filled('date_start')) {
            $query->where('date', '>=', $request->input('date_start'));
        }

        if ($request->filled('date_end')) {
            $query->where('date', '<=', $request->input('date_end'));
        }
    }

    private function getCategorySpending(User $user, Request $request): array
    {
        $hasDateFilters = $request->filled('date_start') || $request->filled('date_end');

        if (! $hasDateFilters) {
            return [[], false];
        }

        $categoryQuery = $user->transactions()
            ->where('type', 'expense')
            ->with('category');

        // Apply same date filters
        if ($request->filled('date_start')) {
            $categoryQuery->where('date', '>=', $request->input('date_start'));
        }

        if ($request->filled('date_end')) {
            $categoryQuery->where('date', '<=', $request->input('date_end'));
        }

        $categorySpending = $categoryQuery
            ->get()
            ->groupBy('category_id')
            ->map(function ($transactions) use ($user) {
                $category = $transactions->first()->category;
                $total = $transactions->sum('amount');

                return [
                    'category' => $category,
                    'total' => $total,
                    'total_in_primary' => $this->currencyService->convertToPrimaryCurrency($total, $transactions->first()->currency, $user) ?? $total,
                ];
            })
            ->sortByDesc('total_in_primary')
            ->values()
            ->toArray();

        return [$categorySpending, true];
    }

    public function store(TransactionStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Transaction::class);

        $this->transactionService->createTransaction($request->user(), $request->validated());

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction created successfully.');
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

    public function downloadFile(Transaction $transaction, TransactionFile $file): BinaryFileResponse
    {
        $this->authorize('view', $transaction);

        abort_if($file->transaction_id !== $transaction->id, 404);
        abort_if(! Storage::disk('local')->exists($file->path), 404);

        return response()->download(
            Storage::disk('local')->path($file->path),
            $file->original_filename
        );
    }
}
