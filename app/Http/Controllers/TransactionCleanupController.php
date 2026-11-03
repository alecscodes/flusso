<?php

namespace App\Http\Controllers;

use App\Enums\AiFeature;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\CategoryService;
use App\Services\CategorySuggestionService;
use App\Services\TransactionService;
use App\Support\FlashToast;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists transactions without a category next to a suggestion for each. Applying a
 * suggestion only sets the category, through the normal update path.
 */
class TransactionCleanupController extends Controller
{
    /** The most transactions "apply all" goes through in one go. */
    private const APPLY_ALL_LIMIT = 200;

    public function __construct(
        private TransactionService $transactionService,
        private CategoryService $categoryService,
        private CategorySuggestionService $suggestionService,
        private AiManager $ai,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Transaction::class);

        $user = $request->user();

        $histories = [
            TransactionType::Expense->value => $this->suggestionService->history($user, TransactionType::Expense),
            TransactionType::Income->value => $this->suggestionService->history($user, TransactionType::Income),
        ];

        $transactions = $this->uncategorized($user)
            ->with('account')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'description' => $transaction->description,
                'date' => $transaction->date->toDateString(),
                'account' => ['id' => $transaction->account->id, 'name' => $transaction->account->name],
                'suggestion' => $this->suggestionService->fromHistory($histories[$transaction->type->value], $transaction->description),
            ]);

        return Inertia::render('Transactions/Cleanup', [
            'transactions' => $transactions,
            'categories' => $this->categoryService->getCategoriesForUser($user),
            'aiEnabled' => $this->ai->isEnabled(AiFeature::Categorize),
        ]);
    }

    public function apply(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        if ($transaction->type === TransactionType::Transfer) {
            throw ValidationException::withMessages(['category_id' => 'A transfer has no category.']);
        }

        $data = $request->validate([
            'category_id' => ['required', 'integer', $request->user()->ownedCategoryRule()->where('type', $transaction->type->value)],
        ]);

        $this->setCategory($transaction, (int) $data['category_id']);

        FlashToast::success('Category applied.');

        return redirect()->back(fallback: route('cleanup.index'));
    }

    /**
     * Apply every suggestion that comes from the owner's own history (never a model's guess).
     */
    public function applyAll(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $user = $request->user();
        $applied = 0;

        foreach ([TransactionType::Expense, TransactionType::Income] as $type) {
            $history = $this->suggestionService->history($user, $type);

            $this->uncategorized($user)
                ->where('type', $type->value)
                ->with('user')
                ->limit(self::APPLY_ALL_LIMIT)
                ->get()
                ->each(function (Transaction $transaction) use ($history, &$applied): void {
                    $suggestion = $this->suggestionService->fromHistory($history, $transaction->description);

                    if ($suggestion !== null) {
                        $this->setCategory($transaction, $suggestion['category_id']);
                        $applied++;
                    }
                });
        }

        $applied > 0
            ? FlashToast::success($applied === 1 ? 'Category applied to 1 transaction.' : "Category applied to {$applied} transactions.")
            : FlashToast::info('Nothing to apply with confidence.');

        return redirect()->back(fallback: route('cleanup.index'));
    }

    /**
     * @return HasMany<Transaction, User>
     */
    private function uncategorized(User $user): HasMany
    {
        return $user->transactions()
            ->whereIn('type', [TransactionType::Expense->value, TransactionType::Income->value])
            ->whereNull('category_id')
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    private function setCategory(Transaction $transaction, int $categoryId): void
    {
        // The update path reads the owner; load it explicitly (lazy loading is off outside production).
        $transaction->loadMissing('user');

        $this->transactionService->updateTransaction($transaction, [
            'account_id' => $transaction->account_id,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'date' => $transaction->date->toDateString(),
            'description' => $transaction->description,
            'category_id' => $categoryId,
        ]);
    }
}
