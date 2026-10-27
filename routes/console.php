<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Laravel\Passport\Client;

Schedule::command('ip:unban --expired')->daily();

Schedule::command('payments:sync')->dailyAt('00:10')->withoutOverlapping();

Schedule::command('accounts:verify-balances')->daily()
    ->onFailure(fn () => Log::error('Account balances differ from the ledger; run accounts:verify-balances.'));

Schedule::command('model:prune')->daily();

Schedule::command('passport:purge')->daily();

// Dynamic client registration is open, so clients that never got a token are dropped after a day.
Schedule::call(fn () => Client::whereDoesntHave('tokens')->where('created_at', '<', now()->subDay())->delete())
    ->name('prune unused oauth clients')->daily();

Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(5);
