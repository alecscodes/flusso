<?php

namespace App\Console\Commands;

use App\Services\DeployService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeployCommand extends Command
{
    protected $signature = 'app:deploy
                            {--skip-git : Skip syncing with the remote git repository}
                            {--if-outdated : Only deploy when remote has new commits}';

    protected $description = 'Deploy the application';

    public function handle(DeployService $deploy): int
    {
        try {
            if ($this->option('if-outdated')) {
                if ($deploy->updateIfNeeded()) {
                    Log::channel('database')->info('Application updated', ['category' => 'system', 'action' => 'update']);
                    $this->info('Application updated.');
                }

                return Command::SUCCESS;
            }

            $deploy->deploy($this->option('skip-git'));
            $this->info('Deployment completed.');

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            Log::channel('database')->error('Deployment failed', [
                'category' => 'system',
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
