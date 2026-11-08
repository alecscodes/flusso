<?php

namespace App\Http\Controllers;

use App\Http\Requests\CounterpartyRequest;
use App\Models\Counterparty;
use App\Services\CounterpartyService;
use App\Support\FlashToast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CounterpartyController extends Controller
{
    public function __construct(private CounterpartyService $counterpartyService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Counterparty::class);

        $user = $request->user();

        return Inertia::render('Counterparties/Index', [
            'counterparties' => $this->counterpartyService->balances($user),
            'suggestions' => $this->counterpartyService->suggestionsForUnassignedIncomes($user),
        ]);
    }

    /**
     * The user's people for the transaction form's autocomplete.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Counterparty::class);

        return response()->json([
            'counterparties' => $request->user()->counterparties()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Counterparty $counterparty): Response
    {
        $this->authorize('view', $counterparty);

        return Inertia::render('Counterparties/Show', [
            'counterparty' => $counterparty->only(['id', 'name']),
            ...$this->counterpartyService->details($request->user(), $counterparty),
        ]);
    }

    public function store(CounterpartyRequest $request): RedirectResponse
    {
        $this->counterpartyService->create($request->user(), $request->validated());

        FlashToast::success('Person added.');

        return redirect()->route('counterparties.index');
    }

    public function update(CounterpartyRequest $request, Counterparty $counterparty): RedirectResponse
    {
        $this->counterpartyService->update($counterparty, $request->validated());

        FlashToast::success('Person updated.');

        return redirect()->back(fallback: route('counterparties.index'));
    }

    public function destroy(Counterparty $counterparty): RedirectResponse
    {
        $this->authorize('delete', $counterparty);

        $this->counterpartyService->delete($counterparty);

        FlashToast::success('Person deleted. Their transactions are kept.');

        return redirect()->route('counterparties.index');
    }

    /**
     * Link a transaction without a person to this one (what the "looks like" suggestions do).
     */
    public function assign(Request $request, Counterparty $counterparty): RedirectResponse
    {
        $this->authorize('update', $counterparty);

        $validated = $request->validate(['transaction_id' => ['required', 'integer']]);

        $this->counterpartyService->assign($request->user(), $counterparty, (int) $validated['transaction_id']);

        FlashToast::success('Transaction linked.');

        return redirect()->back(fallback: route('counterparties.index'));
    }
}
