<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function getAccountsForUser(User $user): Collection
    {
        return $user->accounts()
            ->orderByName()
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
        ]);
    }

    public function updateAccount(Account $account, array $data): Account
    {
        $account->update([
            'name' => $data['name'] ?? $account->name,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $account->currency,
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
}
