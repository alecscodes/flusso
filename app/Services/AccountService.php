<?php

namespace App\Services;

use App\Models\Account;
use App\Models\RecurringPayment;
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
            ->orderBy('name')
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
            'currency' => $data['currency'] ?? $account->currency,
            'is_savings' => $data['is_savings'] ?? $account->is_savings,
        ]);

        if ($account->wasChanged('currency')) {
            // Whatever is still owed on this account follows its currency, edited by hand or not.
            $account->payments()->pending()->update(['currency' => $account->currency]);
            $account->recurringPayments->each(fn (RecurringPayment $rule) => $this->recurringPaymentService->sync($rule));
        }

        return $account;
    }

    /**
     * Archive the account: only the account itself is soft-deleted, so its history
     * and every other account's balance stay untouched. Its rules are paused and
     * its unpaid upcoming occurrences removed (past-due ones stay; paused rules are hidden from the lists).
     */
    public function deleteAccount(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $account->recurringPayments()->update(['is_active' => false]);
            $account->payments()->pending()->where('due_date', '>=', now()->toDateString())->delete();
            $account->delete();
        });
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
