<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DeployService
{
    public function deploy(bool $skipGit = false): void
    {
        $this->ensureDirectories();

        if (! $skipGit && File::isDirectory(base_path('.git'))) {
            $this->syncGit();
        }

        $this->run(['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction']);

        if (File::exists(base_path('package.json'))) {
            $this->run(['npm', 'ci', '--prefer-offline', '--no-audit']);
            $this->run(['npm', 'run', 'build']);
        }

        $env = base_path('.env');
        if (! File::exists($env) || ! str_contains(File::get($env), 'APP_KEY=base64:')) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        if (config('database.default') === 'sqlite' && ! File::exists(database_path('database.sqlite'))) {
            File::put(database_path('database.sqlite'), '');
        }

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize');
        Artisan::call('reload');

        $this->setPermissions();
    }

    public function updateIfNeeded(): bool
    {
        if (! $this->hasUpdates()) {
            return false;
        }

        $this->deploy();

        return true;
    }

    public function hasUpdates(): bool
    {
        if (! Process::run(['git', '--version'])->successful() || ! File::isDirectory(base_path('.git'))) {
            return false;
        }

        $git = fn (array $command): string => trim(Process::path(base_path())->run($command)->output() ?: '');

        Process::path(base_path())->run(['git', 'config', '--global', '--add', 'safe.directory', base_path()]);

        $branch = $git(['git', 'rev-parse', '--abbrev-ref', 'HEAD']) ?: 'main';
        $current = $git(['git', 'rev-parse', 'HEAD']);
        $remote = trim(strtok(Process::path(base_path())->run(['git', 'ls-remote', 'origin', "refs/heads/{$branch}"])->output(), "\t") ?: '');

        return $remote !== '' && $current !== $remote;
    }

    protected function syncGit(): void
    {
        $git = Process::path(base_path());

        $git->run(['git', 'config', '--global', '--add', 'safe.directory', base_path()]);

        foreach (['merge', 'rebase', 'cherry-pick'] as $operation) {
            $git->run(['git', $operation, '--abort']);
        }

        $branch = trim($git->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->output() ?: 'main');

        if (! $git->run(['git', 'fetch', 'origin'])->successful()) {
            throw new RuntimeException('Git fetch failed');
        }

        $reset = $git->run(['git', 'reset', '--hard', "origin/{$branch}"]);
        if (! $reset->successful()) {
            $reset = $git->run(['git', 'reset', '--hard', 'origin/main']);
        }

        if (! $reset->successful()) {
            throw new RuntimeException('Git reset failed');
        }

        $git->run(['git', 'clean', '-fd']);
    }

    protected function ensureDirectories(): void
    {
        foreach ([
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('framework/testing'),
            storage_path('app/private'),
            storage_path('app/public'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
            public_path('build'),
        ] as $directory) {
            File::ensureDirectoryExists($directory);
        }
    }

    protected function setPermissions(): void
    {
        foreach ([storage_path(), base_path('bootstrap/cache'), database_path()] as $path) {
            if (File::exists('/.dockerenv')) {
                Process::run(['chown', '-R', 'www-data:www-data', $path]);
            }
            Process::run(['chmod', '-R', '775', $path]);
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function run(array $command): void
    {
        $result = Process::path(base_path())->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                'Command failed: '.implode(' ', $command).': '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }
}
