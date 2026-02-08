<?php

namespace App\Console\Commands;

use App\Services\IpBanService;
use Illuminate\Console\Command;

class UnbanIp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ip:unban {ip? : The IP address to unban} {--all : Unban all IPs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unban a specific IP address or all banned IPs';

    /**
     * Execute the console command.
     */
    public function handle(IpBanService $ipBanService): int
    {
        if ($this->option('all')) {
            $count = $ipBanService->unbanAll();

            $this->info("Unbanned {$count} IP address(es).");

            return Command::SUCCESS;
        }

        $ip = $this->argument('ip');

        if (! $ip) {
            $this->error('Please provide an IP address or use --all flag.');

            return Command::FAILURE;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error("Invalid IP address: {$ip}");

            return Command::FAILURE;
        }

        $unbanned = $ipBanService->unban($ip);

        if ($unbanned) {
            $this->info("IP address {$ip} has been unbanned.");
        } else {
            $this->warn("IP address {$ip} was not found in the banned list.");
        }

        return Command::SUCCESS;
    }
}
