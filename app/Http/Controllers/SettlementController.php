<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettleRequest;
use App\Models\TransactionSettlement;
use App\Services\SettlementService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function __construct(private SettlementService $settlementService) {}

    public function store(SettleRequest $request): RedirectResponse
    {
        $user = $request->user();

        $income = $user->transactions()->findOrFail($request->integer('income_id'));

        $this->settlementService->allocate($user, $income, $request->validated('allocations'));

        FlashToast::success('Settled.');

        return redirect()->back(fallback: route('counterparties.index'));
    }

    public function destroy(Request $request, TransactionSettlement $settlement): RedirectResponse
    {
        $settlement->load('income');

        abort_unless($settlement->income->user_id === $request->user()->id, 403);

        $this->settlementService->remove($settlement);

        FlashToast::success('Settlement removed.');

        return redirect()->back(fallback: route('counterparties.index'));
    }
}
