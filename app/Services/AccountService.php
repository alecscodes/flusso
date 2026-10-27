<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(
        private CurrencyService $currencyService,
        private RecurringPaymentService $recurringPaymentService,
    ) {}

    public function getAccountsForUser(User $user): Collection
    {
        return $user->accounts()
            ->orderByName()
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

        if ($account->wasChanged('currency')) {
            $account->recurringPayments->each(fn ($rule) => $this->recurringPaymentService->sync($rule));
        }

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
            $account->payments()->pending()->delete();
            $account->delete();
        });
    }

    /**
     * Balance of the accounts that are not savings, in the primary currency.
     */
    public function getTotalBalanceInPrimaryCurrency(User $user): float
    {
        return $this->sumBalances($user, $user->accounts()->whereNotSavings()->get());
    }

    public function getFullBalanceInPrimaryCurrency(User $user): float
    {
        return $this->sumBalances($user, $user->accounts()->get());
    }

    public function getSavingsBalanceInPrimaryCurrency(User $user): float
    {
        return $this->sumBalances($user, $user->accounts()->where('is_savings', true)->get());
    }

    /**
     * The accounts with their balances in the primary currency (one query for the accounts).
     */
    public function getAccountSummary(User $user): array
    {
        $accounts = $this->getAccountsForUser($user);

        return [
            'accounts' => $accounts,
            'availableBalance' => $this->sumBalances($user, $accounts->where('is_savings', false)),
            'totalBalance' => $this->sumBalances($user, $accounts),
            'savingsBalance' => $this->sumBalances($user, $accounts->where('is_savings', true)),
            'primaryCurrency' => $this->currencyService->getPrimaryCurrency($user),
        ];
    }

    private function sumBalances(User $user, Collection $accounts): float
    {
        return $this->currencyService->sumInPrimaryCurrency($accounts, 'balance', 'currency', $user);
    }
}
