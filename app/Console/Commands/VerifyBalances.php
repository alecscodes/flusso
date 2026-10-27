<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

class VerifyBalances extends Command
{
    protected $signature = 'accounts:verify-balances';

    protected $description = 'Compare every account balance with the ledger (read-only); exits 1 on any mismatch';

    public function handle(): int
    {
        $mismatches = Account::withTrashed()->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'stored' => number_format((float) $account->balance, 2, '.', ''),
                'ledger' => $account->ledgerBalance(),
            ])
            ->filter(fn (array $row) => $row['stored'] !== $row['ledger'])
            ->values();

        if ($mismatches->isEmpty()) {
            $this->info('0 mismatches: every account balance matches the ledger.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Account', 'Stored', 'Ledger'], $mismatches->all());
        $this->error($mismatches->count().' mismatch(es).');

        return self::FAILURE;
    }
}
