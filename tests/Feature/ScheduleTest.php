<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

function scheduled(string $command): ?Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event) => str_contains($event->command, " {$command}"));
}

test('the daily jobs are scheduled', function () {
    expect(scheduled('payments:sync'))->expression->toBe('10 0 * * *')->withoutOverlapping->toBeTrue()
        ->and(scheduled('accounts:verify-balances'))->expression->toBe('0 0 * * *')
        ->and(scheduled('model:prune'))->expression->toBe('0 0 * * *')
        ->and(scheduled('passport:purge'))->expression->toBe('0 0 * * *')
        ->and(scheduled('inspire'))->toBeNull();
});

test('registered OAuth clients that never got a token are dropped after a day', function () {
    $user = User::factory()->create();
    $unused = Client::factory()->create(['created_at' => now()->subDays(2)]);
    $new = Client::factory()->create(['created_at' => now()->subHour()]);
    $used = Client::factory()->create(['created_at' => now()->subDays(2)]);
    Token::forceCreate(['id' => 't1', 'user_id' => $user->id, 'client_id' => $used->id, 'scopes' => '[]', 'revoked' => false]);

    collect(app(Schedule::class)->events())->first(fn (Event $event) => $event->description === 'prune unused oauth clients')->run(app());

    expect(Client::pluck('id')->all())->toEqualCanonicalizing([$new->id, $used->id]);
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
