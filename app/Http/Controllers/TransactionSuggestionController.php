<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\CategorySuggestionService;
use App\Services\TransactionShortcutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only hints for the add-transaction form: nothing here writes, the owner confirms in the form.
 */
class TransactionSuggestionController extends Controller
{
    public function __construct(
        private TransactionShortcutService $shortcutService,
        private CategorySuggestionService $categorySuggestionService,
    ) {}

    public function shortcuts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        return response()->json([
            'shortcuts' => $this->shortcutService->shortcutsFor($request->user()),
        ]);
    }

    public function category(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in([TransactionType::Expense->value, TransactionType::Income->value])],
        ]);

        return response()->json([
            'suggestion' => $this->categorySuggestionService->suggest(
                $request->user(),
                $data['description'],
                TransactionType::from($data['type']),
            ),
        ]);
    }
}
