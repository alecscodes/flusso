<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(
        private CurrencyService $currencyService
    ) {}

    public function getAccountsForUser(User $user): Collection
    {
        return $user->accounts()
            ->orderByName()
            ->get();
    }

    public function getNonSavingsAccountsForUser(User $user): Collection
    {
        return $user->accounts()
            ->orderByName()
            ->whereNotSavings()
            ->get();
    }

    public function createAccount(User $user, array $data): Account
    {
        $account = $user->accounts()->create([
            'name' => $data['name'],
            'currency' => strtoupper($data['currency']),
            'initial_balance' => $data['balance'] ?? 0,
            'is_savings' => $data['is_savings'] ?? false,
        ]);

        $account->recalculateBalance();

        return $account;
    }

    public function updateAccount(Account $account, array $data): Account
    {
        $account->update([
            'name' => $data['name'] ?? $account->name,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $account->currency,
            'is_savings' => $data['is_savings'] ?? $account->is_savings,
        ]);

        return $account->fresh();
    }

    /**
     * Archive the account: only the account itself is soft-deleted, so its history
     * and every other account's balance stay untouched. Its rules are paused and
     * its unpaid occurrences removed.
     */
    public function deleteAccount(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $account->recurringPayments()->update(['is_active' => false]);
            $account->payments()->unpaid()->delete();
            $account->delete();
        });
    }

    public function getTotalBalanceInPrimaryCurrency(User $user): float
    {
        $accounts = $user->accounts()->whereNotSavings()->get();

        return $this->currencyService->sumInPrimaryCurrency($accounts, 'balance', 'currency', $user);
    }

    public function getFullBalanceInPrimaryCurrency(User $user): float
    {
        $accounts = $user->accounts()->get();

        return $this->currencyService->sumInPrimaryCurrency($accounts, 'balance', 'currency', $user);
    }

    public function getSavingsBalanceInPrimaryCurrency(User $user): float
    {
        $accounts = $user->accounts()->where('is_savings', true)->get();

        return $this->currencyService->sumInPrimaryCurrency($accounts, 'balance', 'currency', $user);
    }

    /**
     * Get currency-aware account summary for frontend
     */
    public function getAccountSummary(User $user): array
    {
        $accounts = $this->getAccountsForUser($user);
        $availableBalance = $this->getTotalBalanceInPrimaryCurrency($user);
        $totalBalance = $this->getFullBalanceInPrimaryCurrency($user);
        $savingsBalance = $this->getSavingsBalanceInPrimaryCurrency($user);
        $primaryCurrency = $this->currencyService->getPrimaryCurrency($user);

        return [
            'accounts' => $accounts,
            'availableBalance' => $availableBalance,
            'totalBalance' => $totalBalance,
            'savingsBalance' => $savingsBalance,
            'primaryCurrency' => $primaryCurrency,
        ];
    }
}
