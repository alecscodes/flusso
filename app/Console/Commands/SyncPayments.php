<?php

namespace App\Console\Commands;

use App\Services\RecurringPaymentService;
use Illuminate\Console\Command;

class SyncPayments extends Command
{
    protected $signature = 'payments:sync';

    protected $description = 'Bring every planned payment\'s occurrences in line with its rule (extends open-ended rules)';

    public function handle(RecurringPaymentService $service): int
    {
        $this->info("Synced {$service->syncAll()} planned payment(s).");

        return self::SUCCESS;
    }
}
