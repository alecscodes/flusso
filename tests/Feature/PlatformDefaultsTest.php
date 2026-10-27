<?php

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;

test('dates are immutable by default', function () {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);

    $tomorrow = now()->addDay();

    expect($tomorrow->isSameDay(now()))->toBeFalse();
});

test('today is the owner\'s day: after 23:00 UTC it is already tomorrow', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 23:30:00', 'UTC'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = app(TransactionService::class)->createTransaction($user, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 5]);

    expect(config('app.timezone'))->toBe('Europe/Rome')
        ->and($transaction->date->toDateString())->toBe('2026-03-11');
});

test('SQLite takes the write lock when a transaction starts', function () {
    expect(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE');
});
