<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountStoreRequest;
use App\Http\Requests\AccountUpdateRequest;
use App\Models\Account;
use App\Services\AccountService;
use App\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(
        private AccountService $accountService,
        private CategoryService $categoryService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Account::class);

        $accountSummary = $this->accountService->getAccountSummary($request->user());

        return Inertia::render('Accounts/Index', $accountSummary);
    }

    public function store(AccountStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $this->accountService->createAccount($request->user(), $request->validated());

        return redirect()->route('accounts.index')
            ->with('success', 'Account created successfully.');
    }

    public function show(Account $account, Request $request): Response
    {
        $this->authorize('view', $account);

        $user = $request->user();

        return Inertia::render('Accounts/Show', [
            'account' => $account->load(['transactions' => fn ($q) => $q->with(['account', 'category', 'linkedTransaction', 'from_account', 'to_account', 'files'])
                ->latest('date')->limit(10),
            ]),
            'accounts' => $this->accountService->getAccountsForUser($user),
            'categories' => $this->categoryService->getCategoriesForUser($user),
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
