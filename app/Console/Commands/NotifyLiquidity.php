<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LiquidityService;
use Illuminate\Console\Command;

class NotifyLiquidity extends Command
{
    protected $signature = 'liquidity:notify';

    protected $description = 'Notify users whose accounts are at their alert threshold or face a shortfall in the next days';

    public function handle(LiquidityService $service): int
    {
        $sent = 0;

        User::query()->each(function (User $user) use ($service, &$sent) {
            $sent += $service->notifyUser($user);
        });

        $this->info("Sent {$sent} liquidity alert(s).");

        return self::SUCCESS;
    }
}
