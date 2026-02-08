<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountStoreRequest;
use App\Http\Requests\AccountUpdateRequest;
use App\Models\Account;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(
        private AccountService $accountService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Account::class);

        return Inertia::render('Accounts/Index', [
            'accounts' => $this->accountService->getAccountsForUser($request->user()),
        ]);
    }

    public function store(AccountStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $this->accountService->createAccount($request->user(), $request->validated());

        return redirect()->route('accounts.index')
            ->with('success', 'Account created successfully.');
    }

    public function show(Account $account): Response
    {
        $this->authorize('view', $account);

        return Inertia::render('Accounts/Show', [
            'account' => $account->load(['transactions' => fn ($q) => $q->latest('date')->limit(10)]),
        ]);
    }

    public function update(AccountUpdateRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $this->accountService->updateAccount($account, $request->validated());

        return redirect()->route('accounts.index')
            ->with('success', 'Account updated successfully.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $this->accountService->deleteAccount($account);

        return redirect()->route('accounts.index')
            ->with('success', 'Account deleted successfully.');
    }
}
