<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

function scheduled(string $command): ?Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event) => str_contains($event->command, " {$command}"));
}

test('the daily jobs are scheduled', function () {
    expect(scheduled('payments:sync'))->expression->toBe('10 0 * * *')->withoutOverlapping->toBeTrue()
        ->and(scheduled('accounts:verify-balances'))->expression->toBe('0 0 * * *')
        ->and(scheduled('model:prune'))->expression->toBe('0 0 * * *')
        ->and(scheduled('inspire'))->toBeNull();
});

test('a balance that differs from the ledger is logged as an error', function () {
    Log::spy();
    $account = Account::factory()->create(['user_id' => User::factory()->create()->id, 'balance' => 100]);
    Account::whereKey($account->id)->update(['balance' => 1]);

    $this->artisan('accounts:verify-balances')->assertFailed();

    $event = scheduled('accounts:verify-balances');
    $event->exitCode = 1;
    $event->callAfterCallbacks(app());

    Log::shouldHaveReceived('error')->once();
});

test('a clean ledger logs nothing', function () {
    Log::spy();

    $event = scheduled('accounts:verify-balances');
    $event->exitCode = 0;
    $event->callAfterCallbacks(app());

    Log::shouldNotHaveReceived('error');
});
