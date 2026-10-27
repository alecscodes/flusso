<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ip:unban --expired')->daily();

Schedule::command('payments:sync')->dailyAt('00:10')->withoutOverlapping();

Schedule::command('accounts:verify-balances')->daily()
    ->onFailure(fn () => Log::error('Account balances differ from the ledger; run accounts:verify-balances.'));

Schedule::command('model:prune')->daily();

Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(5);
