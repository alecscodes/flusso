<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\Transaction;
use App\Models\TransactionFile;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\CurrencyService;
use App\Services\TransactionService;
use App\Support\FlashToast;
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

        $request->validate([
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
        ]);

        $query = $user->transactions()
            ->with(['account', 'category', 'toAccount', 'files'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        $this->applyTransactionFilters($query, $request);

        $hasDateFilters = $request->filled('date_start') || $request->filled('date_end');

        return Inertia::render('Transactions/Index', [
            'transactions' => $query->paginate(50)->withQueryString(),
            'accounts' => $this->accountService->getAccountsForUser($user),
            'categories' => $this->categoryService->getCategoriesForUser($user),
            'categorySpending' => $hasDateFilters
                ? $this->transactionService->getCategorySpendingForPeriod(
                    $user,
                    $request->date('date_start'),
                    $request->date('date_end'),
                    limit: 100,
                )
                : [],
            'hasDateFilters' => $hasDateFilters,
            'primaryCurrency' => $this->currencyService->getPrimaryCurrency($user),
        ]);
    }

    private function applyTransactionFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%'.$search.'%');

                if (is_numeric($search)) {
                    $q->orWhere('amount', $search)->orWhere('to_amount', $search);
                }
            });
        }

        if ($request->filled('account_id')) {
            $query->forAccount((int) $request->input('account_id'));
        }

        if ($request->filled('category_id')) {
            $query->forCategory((int) $request->input('category_id'));
        }

        $type = $request->input('type');
        if (is_string($type) && in_array($type, ['income', 'expense', 'transfer'], true)) {
            $query->where('type', $type);
        }

        $query->inPeriod($request->input('date_start'), $request->input('date_end'));
    }

    public function store(TransactionRequest $request): RedirectResponse
    {
        $this->transactionService->createTransaction($request->user(), $request->validated());

        FlashToast::success('Transaction created successfully.');

        return redirect()->route('transactions.index');
    }

    public function update(TransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->transactionService->updateTransaction($transaction, $request->validated());

        FlashToast::success('Transaction updated successfully.');

        return redirect()->route('transactions.index');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $this->authorize('delete', $transaction);

        $this->transactionService->deleteTransaction($transaction);

        FlashToast::success('Transaction deleted successfully.');

        return redirect()->route('transactions.index');
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
