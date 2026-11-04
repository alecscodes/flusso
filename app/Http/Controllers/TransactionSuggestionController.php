<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\CategorySuggestionRequest;
use App\Http\Requests\QuickEntryParseRequest;
use App\Models\Transaction;
use App\Services\CategorySuggestionService;
use App\Services\QuickEntry\QuickEntryService;
use App\Services\TransactionShortcutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only hints for the add-transaction form: nothing here writes, the owner confirms in the form.
 */
class TransactionSuggestionController extends Controller
{
    public function __construct(
        private TransactionShortcutService $shortcutService,
        private CategorySuggestionService $categorySuggestionService,
        private QuickEntryService $quickEntryService,
    ) {}

    public function shortcuts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        return response()->json([
            'shortcuts' => $this->shortcutService->shortcutsFor($request->user()),
        ]);
    }

    public function category(CategorySuggestionRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'suggestion' => $this->categorySuggestionService->suggest(
                $request->user(),
                $data['description'],
                TransactionType::from($data['type']),
            ),
        ]);
    }

    /**
     * Read a typed line ("cafea 2.5 cash") into proposed transactions. Nothing is saved.
     */
    public function parseText(QuickEntryParseRequest $request): JsonResponse
    {
        return response()->json($this->quickEntryService->parse($request->user(), $request->validated('text')));
    }
}
