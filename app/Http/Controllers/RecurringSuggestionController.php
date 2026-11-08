<?php

namespace App\Http\Controllers;

use App\Enums\SuggestionDismissalKind;
use App\Http\Requests\RecurringSuggestionDismissalRequest;
use App\Services\RecurringSuggestionService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;

/**
 * Waving away (or taking back) a recurring-payment suggestion. Creating the planned payment itself
 * goes through the normal planned payment form.
 */
class RecurringSuggestionController extends Controller
{
    public function __construct(private RecurringSuggestionService $suggestionService) {}

    public function dismiss(RecurringSuggestionDismissalRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $kind = SuggestionDismissalKind::from($data['kind']);

        $this->suggestionService->dismiss($request->user(), $data['key'], $kind, $data['snooze_until'] ?? null);

        FlashToast::success($kind === SuggestionDismissalKind::Temporary ? 'Hidden for now.' : 'Got it, it will not be suggested again.');

        return redirect()->back(fallback: route('planned-payments.index'));
    }

    public function restore(RecurringSuggestionDismissalRequest $request): RedirectResponse
    {
        $this->suggestionService->restore($request->user(), $request->validated('key'));

        FlashToast::success('Suggestion brought back.');

        return redirect()->back(fallback: route('planned-payments.index'));
    }
}
