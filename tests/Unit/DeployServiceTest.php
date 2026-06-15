<?php

use App\Services\DeployService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(fn () => $this->service = new DeployService);

function fakeGit(array $responses): void
{
    Process::fake(function (PendingProcess $process) use ($responses) {
        $key = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        foreach ($responses as $pattern => $result) {
            if (str_contains($key, $pattern)) {
                return $result;
            }
        }

        return Process::result();
    });
}

test('hasUpdates returns true when local and remote differ', function () {
    fakeGit([
        'git --version' => Process::result(output: 'git version 2.0'),
        'rev-parse HEAD' => Process::result(output: "abc\n"),
        'rev-parse --abbrev-ref HEAD' => Process::result(output: "main\n"),
        'ls-remote' => Process::result(output: "def\trefs/heads/main\n"),
    ]);

    expect($this->service->hasUpdates())->toBeTrue();
});

test('hasUpdates returns false when commits match', function () {
    fakeGit([
        'git --version' => Process::result(output: 'git version 2.0'),
        'rev-parse HEAD' => Process::result(output: "abc\n"),
        'rev-parse --abbrev-ref HEAD' => Process::result(output: "main\n"),
        'ls-remote' => Process::result(output: "abc\trefs/heads/main\n"),
    ]);

    expect($this->service->hasUpdates())->toBeFalse();
});

test('hasUpdates returns false when git is unavailable', function () {
    Process::fake(fn (PendingProcess $p) => $p->command === ['git', '--version']
        ? Process::result(exitCode: 1)
        : Process::result());

    expect($this->service->hasUpdates())->toBeFalse();
});

test('updateIfNeeded skips deploy when up to date', function () {
    fakeGit([
        'git --version' => Process::result(output: 'git version 2.0'),
        'rev-parse HEAD' => Process::result(output: "abc\n"),
        'rev-parse --abbrev-ref HEAD' => Process::result(output: "main\n"),
        'ls-remote' => Process::result(output: "abc\trefs/heads/main\n"),
    ]);

    expect($this->service->updateIfNeeded())->toBeFalse();
});

test('deploy skips git sync with skipGit option', function () {
    Process::fake(fn () => Process::result(output: 'ok'));
    Artisan::shouldReceive('call')->andReturn(0);

    $this->service->deploy(skipGit: true);

    Process::assertDidntRun(fn (PendingProcess $p) => $p->command === ['git', 'fetch', 'origin']);
});

test('app deploy command completes successfully', function () {
    $mock = Mockery::mock(DeployService::class);
    $mock->shouldReceive('deploy')->once()->with(false);
    $this->app->instance(DeployService::class, $mock);

    $this->artisan('app:deploy')->assertSuccessful();
});

test('app deploy if-outdated delegates to updateIfNeeded', function () {
    $mock = Mockery::mock(DeployService::class);
    $mock->shouldReceive('updateIfNeeded')->once()->andReturn(false);
    $this->app->instance(DeployService::class, $mock);

    $this->artisan('app:deploy --if-outdated')->assertSuccessful();
});
