<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class GitUpdateService
{
    /**
     * Check if updates are available from the remote repository.
     *
     * @return array{available: bool, current_commit: string|null, remote_commit: string|null, commits_behind: int, error: string|null}
     */
    public function checkForUpdates(): array
    {
        $result = [
            'available' => false,
            'current_commit' => null,
            'remote_commit' => null,
            'commits_behind' => 0,
            'error' => null,
        ];

        try {
            $workingDir = base_path();

            // Check if git is available
            $gitCheck = Process::run('git --version');
            if (! $gitCheck->successful()) {
                $result['error'] = 'Git is not available';

                return $result;
            }

            // Check if .git directory exists
            if (! is_dir($workingDir.'/.git')) {
                $result['error'] = 'Git repository not found';

                return $result;
            }

            // Configure Git to trust this directory (fixes Docker ownership issues)
            $this->configureGitSafeDirectory($workingDir);

            // Check if we're in a git repository
            $gitDirCheck = Process::path($workingDir)->run('git rev-parse --git-dir');
            if (! $gitDirCheck->successful()) {
                $result['error'] = 'Not a git repository: '.$gitDirCheck->errorOutput();

                return $result;
            }

            // Get current commit hash
            $currentCommit = Process::path($workingDir)->run('git rev-parse HEAD');
            if ($currentCommit->successful()) {
                $result['current_commit'] = trim($currentCommit->output());
            }

            // Fetch latest changes from remote (without merging)
            $fetch = Process::path($workingDir)->run('git fetch origin --quiet');
            if (! $fetch->successful()) {
                $result['error'] = 'Failed to fetch from remote: '.$fetch->errorOutput();

                return $result;
            }

            // Get remote branch name (default to main)
            $branch = $this->getCurrentBranch($workingDir);

            // Check commits behind
            $commitsBehind = Process::path($workingDir)->run("git rev-list --count HEAD..origin/{$branch}");
            if ($commitsBehind->successful()) {
                $result['commits_behind'] = (int) trim($commitsBehind->output());
            }

            // Get remote commit hash
            $remoteCommit = Process::path($workingDir)->run("git rev-parse origin/{$branch}");
            if ($remoteCommit->successful()) {
                $result['remote_commit'] = trim($remoteCommit->output());
            }

            $result['available'] = $result['commits_behind'] > 0;
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Perform the update by pulling latest changes.
     *
     * @return array{success: bool, message: string, output: string|null, error: string|null, updated: bool}
     */
    public function performUpdate(): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'output' => null,
            'error' => null,
            'updated' => false,
        ];

        try {
            $workingDir = base_path();

            // Check if there are commits available before updating
            $updateCheck = $this->checkForUpdates();
            if (! $updateCheck['available']) {
                $result['success'] = true;
                $result['message'] = 'No updates available: local branch is up to date with remote.';
                $result['output'] = 'No commits to pull from origin.';
                $result['updated'] = false;

                return $result;
            }

            // Non-Docker: call deploy.sh
            // Docker: reset git and run deployment steps
            if ($this->isRunningInDocker()) {
                return $this->runDockerUpdate($workingDir);
            }

            return $this->runDeployScript($workingDir);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['message'] = 'Update failed: '.$e->getMessage();
        }

        return $result;
    }

    /**
     * Run Docker update process: reset git to match remote exactly, then run deployment.
     *
     * @return array{success: bool, message: string, output: string|null, error: string|null, updated: bool}
     */
    protected function runDockerUpdate(string $workingDir): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'output' => null,
            'error' => null,
            'updated' => false,
        ];

        try {
            $this->configureGitSafeDirectory($workingDir);

            $branch = $this->getCurrentBranch($workingDir);

            // Fetch latest from remote
            $fetch = Process::path($workingDir)->run('git fetch origin');
            if (! $fetch->successful()) {
                $result['error'] = 'Failed to fetch from remote: '.$fetch->errorOutput();
                $result['message'] = 'Update failed';

                return $result;
            }

            // Fix permissions before git reset (files might be owned by www-data)
            Process::path($workingDir)->run('sh -c "chown -R root:root . 2>/dev/null || true"');

            // Reset hard to remote branch (discards ALL local changes)
            $reset = Process::path($workingDir)->run("git reset --hard origin/{$branch}");
            if (! $reset->successful()) {
                $result['error'] = 'Failed to reset to remote: '.$reset->errorOutput();
                $result['message'] = 'Update failed';

                return $result;
            }

            // Clean untracked files
            Process::path($workingDir)->run('git clean -fd');

            $result['output'] = "Git reset to origin/{$branch}\n";

            // Fix permissions after git reset
            $this->fixPermissionsAfterGitReset($workingDir);

            // Run deployment steps
            $this->runDeploymentSteps($workingDir, $result);

            $result['success'] = true;
            $result['message'] = 'Update completed successfully';
            $result['updated'] = true;
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['message'] = 'Update failed: '.$e->getMessage();
        }

        return $result;
    }

    /**
     * Run deployment steps: composer, npm, build, migrate, optimize.
     *
     * @param  array<string, mixed>  $result
     */
    protected function runDeploymentSteps(string $workingDir, array &$result): void
    {
        // Install Composer dependencies
        $composer = Process::path($workingDir)->run('sh -c "composer install --no-dev --optimize-autoloader --no-interaction --no-scripts"');
        if ($composer->successful()) {
            $result['output'] .= "\n=== Composer Install ===\n".$composer->output();
        } else {
            $result['output'] .= "\n=== Composer Install Failed ===\n".$composer->errorOutput();
        }

        // Install NPM dependencies
        if (file_exists($workingDir.'/package.json')) {
            $npmInstall = Process::path($workingDir)->run('sh -c "npm ci"');
            if ($npmInstall->successful()) {
                $result['output'] .= "\n=== NPM Install ===\n".$npmInstall->output();
            } else {
                $result['output'] .= "\n=== NPM Install Failed ===\n".$npmInstall->errorOutput();
            }

            // Generate Wayfinder types before building
            $this->generateWayfinderTypes($workingDir, $result);

            // Build assets
            $npmBuild = Process::path($workingDir)->run('sh -c "npm run build"');
            if ($npmBuild->successful()) {
                $result['output'] .= "\n=== NPM Build ===\n".$npmBuild->output();
            } else {
                $result['output'] .= "\n=== NPM Build Failed ===\n".$npmBuild->errorOutput();
            }
        }

        // Restart queue worker to prevent database locks during migrations
        Process::path($workingDir)->run('sh -c "php artisan queue:restart"');
        sleep(2);

        // Run migrations
        $migrate = Process::path($workingDir)->run('sh -c "php artisan migrate --force"');
        if ($migrate->successful()) {
            $result['output'] .= "\n=== Migrations ===\n".$migrate->output();
        } else {
            $result['output'] .= "\n=== Migrations Failed ===\n".$migrate->errorOutput();
        }

        // Clear caches
        $this->clearCaches();

        // Optimize
        $optimize = Process::path($workingDir)->run('sh -c "php artisan optimize"');
        if ($optimize->successful()) {
            $result['output'] .= "\n=== Optimize ===\n".$optimize->output();
        }

        // Dump autoload
        $dumpAutoload = Process::path($workingDir)->run('sh -c "composer dump-autoload --optimize --no-interaction"');
        if ($dumpAutoload->successful()) {
            $result['output'] .= "\n=== Autoload Dump ===\n".$dumpAutoload->output();
        }

        // Fix permissions after optimize
        $this->fixPermissionsAfterGitReset($workingDir);
    }

    /**
     * Generate Wayfinder types before building assets.
     *
     * @param  array<string, mixed>  $result
     */
    protected function generateWayfinderTypes(string $workingDir, array &$result): void
    {
        try {
            // Clear config cache first
            Process::path($workingDir)->run('sh -c "php artisan config:clear"');

            // Generate wayfinder types
            $wayfinder = Process::path($workingDir)
                ->timeout(60)
                ->run('sh -c "php artisan wayfinder:generate --with-form"');

            if ($wayfinder->successful()) {
                $result['output'] .= "\n=== Wayfinder Types Generated ===\n".$wayfinder->output();
            } else {
                $result['output'] .= "\n=== Wayfinder Warning ===\n".$wayfinder->errorOutput();
            }
        } catch (\Exception $e) {
            // Silently fail - build will attempt to generate types itself
        }
    }

    /**
     * Get the current git branch.
     */
    protected function getCurrentBranch(?string $workingDir = null): string
    {
        $workingDir = $workingDir ?? base_path();
        $branch = Process::path($workingDir)->run('git rev-parse --abbrev-ref HEAD');
        if ($branch->successful()) {
            return trim($branch->output());
        }

        // Default to main if branch detection fails
        return 'main';
    }

    /**
     * Configure Git to trust the working directory.
     */
    protected function configureGitSafeDirectory(string $workingDir): void
    {
        try {
            if (! is_dir($workingDir.'/.git')) {
                return;
            }

            Process::run("git config --global --add safe.directory {$workingDir} 2>/dev/null || true");
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Fix permissions after git reset (git reset creates files as root, Laravel needs www-data).
     */
    protected function fixPermissionsAfterGitReset(string $workingDir): void
    {
        if (! $this->isRunningInDocker()) {
            return; // Only needed in Docker
        }

        try {
            // Fix database directory and file permissions
            Process::path($workingDir)->run('sh -c "chown -R www-data:www-data database 2>/dev/null || true"');
            Process::path($workingDir)->run('sh -c "chmod 775 database 2>/dev/null || true"');
            Process::path($workingDir)->run('sh -c "[ -f database/database.sqlite ] && chmod 664 database/database.sqlite 2>/dev/null || true"');

            // Fix storage and bootstrap/cache permissions
            Process::path($workingDir)->run('sh -c "chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true"');
            Process::path($workingDir)->run('sh -c "chmod -R 775 storage bootstrap/cache 2>/dev/null || true"');

            // Fix build directory permissions
            Process::path($workingDir)->run('sh -c "[ -d public/build ] && chown -R www-data:www-data public/build 2>/dev/null || true"');
            Process::path($workingDir)->run('sh -c "[ -d public/build ] && chmod -R 755 public/build 2>/dev/null || true"');
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Clear Laravel caches after update.
     */
    protected function clearCaches(): void
    {
        try {
            $workingDir = base_path();
            $commands = ['config:clear', 'route:clear', 'view:clear', 'cache:clear'];

            foreach ($commands as $command) {
                Process::path($workingDir)->run("sh -c \"php artisan {$command}\"");
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Check if the application is running in a Docker container.
     */
    protected function isRunningInDocker(): bool
    {
        // Check for .dockerenv file (standard Docker indicator)
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // Check cgroup for Docker indicator
        if (file_exists('/proc/self/cgroup')) {
            $cgroup = file_get_contents('/proc/self/cgroup');
            if ($cgroup && str_contains($cgroup, 'docker')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the deploy.sh script for non-Docker deployments.
     *
     * @return array{success: bool, message: string, output: string|null, error: string|null, updated: bool}
     */
    protected function runDeployScript(string $workingDir): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'output' => null,
            'error' => null,
            'updated' => false,
        ];

        $deployScript = $workingDir.'/deploy.sh';

        // Ensure script is executable
        @chmod($deployScript, 0755);

        // Run deploy.sh script
        $deploy = Process::path($workingDir)
            ->timeout(600) // 10 minute timeout for deployment
            ->run('sh deploy.sh');

        if ($deploy->successful()) {
            $result['success'] = true;
            $result['message'] = 'Update completed successfully';
            $result['output'] = trim($deploy->output());
            $result['updated'] = true;
        } else {
            $result['error'] = trim($deploy->errorOutput());
            $result['message'] = 'Update failed';
            $result['output'] = trim($deploy->output());
        }

        return $result;
    }
}
