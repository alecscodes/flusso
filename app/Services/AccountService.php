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
        $balance = $data['balance'] ?? 0;

        return $user->accounts()->create([
            'name' => $data['name'],
            'currency' => strtoupper($data['currency']),
            'balance' => $balance,
            'initial_balance' => $balance,
            'is_savings' => $data['is_savings'] ?? false,
        ]);
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

    public function deleteAccount(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $account->transactions()->delete();
            $account->payments()->delete();
            $account->recurringPayments()->delete();
            $account->delete();
        });
    }

    public function adjustBalance(Account $account, float $amount, string $operation): void
    {
        match ($operation) {
            'add', 'increment' => $account->increment('balance', $amount),
            'subtract', 'decrement' => $account->decrement('balance', $amount),
            default => throw new \InvalidArgumentException("Invalid operation: {$operation}"),
        };
    }

    public function recalculateBalance(Account $account): void
    {
        $account->recalculateBalance();
    }

    public function recalculateAllBalances(User $user): void
    {
        $user->accounts->each(fn (Account $account) => $account->recalculateBalance());
    }

    public function getTotalBalance(User $user, ?string $currency = null): float
    {
        $query = $user->accounts();

        if ($currency) {
            $query->where('currency', strtoupper($currency));
        }

        return (float) $query->sum('balance');
    }

    public function getAccountsByCurrency(User $user): Collection
    {
        return $user->accounts()
            ->orderByName()
            ->get()
            ->groupBy('currency');
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
