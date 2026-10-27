<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the migration against a file-backed SQLite database rebuilt in the legacy
 * two-row shape (the default test connection lives inside a transaction, where
 * `PRAGMA foreign_keys` cannot be toggled).
 */
beforeEach(function () {
    $this->legacyFile = tempnam(sys_get_temp_dir(), 'ledger-').'.sqlite';
    touch($this->legacyFile);
    $this->backupsBefore = File::glob(storage_path('app/backups/pre-ledger-*'));
    $this->originalConnection = DB::getDefaultConnection();

    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => $this->legacyFile, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('legacy');
    Schema::clearResolvedInstance('db.schema');

    Artisan::call('migrate', ['--database' => 'legacy', '--force' => true]);
    $this->migration = require database_path('migrations/2026_10_27_200000_move_transfers_to_a_single_row.php');
    $this->migration->down();
});

afterEach(function () {
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('legacy');
    Schema::clearResolvedInstance('db.schema');
    File::delete($this->legacyFile);
    File::delete(array_diff(File::glob(storage_path('app/backups/pre-ledger-*')), $this->backupsBefore));
});

/**
 * Accounts: 1 Popso EUR, 2 Revolut EUR, 3 Reserved EUR, 4 Enzfox RON.
 *
 * @param  float  $popsoBalance  what the old app had stored for Popso
 */
function seedLegacyLedger(float $popsoBalance): void
{
    $at = ['created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-05-01 10:00:00'];
    DB::table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x'] + $at);
    DB::table('categories')->insert(['id' => 1, 'user_id' => 1, 'name' => 'Food', 'type' => 'expense'] + $at);

    foreach ([[1, 'Popso', 'EUR', $popsoBalance], [2, 'Revolut', 'EUR', -9.93], [3, 'Reserved', 'EUR', 50], [4, 'Enzfox', 'RON', 100.004754]] as [$id, $name, $currency, $balance]) {
        DB::table('accounts')->insert(['id' => $id, 'user_id' => 1, 'name' => $name, 'currency' => $currency, 'balance' => $balance, 'initial_balance' => 0] + $at);
    }

    $leg = fn (int $id, int $account, string $type, float $amount, string $currency, ?string $description, ?int $from, ?int $to, ?float $rate = null, ?string $deletedAt = null) => DB::table('transactions')->insert([
        'id' => $id, 'user_id' => 1, 'account_id' => $account, 'type' => $type, 'amount' => $amount, 'currency' => $currency,
        'description' => $description, 'date' => '2026-05-11 00:00:00', 'from_account_id' => $from, 'to_account_id' => $to,
        'exchange_rate' => $rate, 'deleted_at' => $deletedAt, 'category_id' => 1,
    ] + $at);

    $leg(1, 1, 'income', 1000, 'EUR', 'Salary', null, null);
    $leg(2, 1, 'expense', 100, 'EUR', null, null, null);
    // A: clean pair Popso -> Reserved
    $leg(3, 1, 'transfer', 50, 'EUR', 'Transfer to Reserved', 1, 3, 1);
    $leg(4, 3, 'transfer', 50, 'EUR', 'Transfer from Popso', 1, 3, 1);
    // B: corrupted like 379/380 in production: the incoming leg was moved to Revolut, to_account_id still says Reserved
    $leg(5, 1, 'transfer', 10, 'EUR', 'Rent share', 1, 3, 1);
    $leg(6, 2, 'transfer', 10, 'EUR', 'Transfer from Popso', 1, 3, 1);
    // C: cross currency Revolut -> Enzfox
    $leg(7, 2, 'transfer', 19.93, 'EUR', 'Transfer to Enzfox', 2, 4, 5.0178);
    $leg(8, 4, 'transfer', 100.004754, 'RON', 'Transfer from Revolut', 2, 4, 5.0178);
    // D: trashed pair and a trashed orphan with no accounts at all
    $leg(9, 1, 'transfer', 15, 'EUR', 'Transfer to Reserved', 1, 3, 1, '2026-05-12 00:00:00');
    $leg(10, 3, 'transfer', 15, 'EUR', 'Transfer from Popso', 1, 3, 1, '2026-05-12 00:00:00');
    $leg(11, 1, 'transfer', 50, 'EUR', null, null, null, null, '2026-05-12 00:00:00');

    foreach ([3 => 4, 4 => 3, 5 => 6, 6 => 5, 7 => 8, 8 => 7, 9 => 10, 10 => 9] as $id => $linked) {
        DB::table('transactions')->where('id', $id)->update(['linked_transaction_id' => $linked]);
    }

    // Things attached to an incoming leg must follow the surviving row.
    DB::table('transaction_files')->insert(['transaction_id' => 6, 'filename' => 'a', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'path' => 'a'] + $at);
    DB::table('recurring_payments')->insert(['id' => 1, 'user_id' => 1, 'account_id' => 1, 'category_id' => 1, 'name' => 'Rent', 'amount' => 10, 'currency' => 'EUR', 'interval_type' => 'months', 'start_date' => '2026-05-01 00:00:00', 'end_date' => '2026-06-30 00:00:00'] + $at);
    DB::table('payments')->insert(['user_id' => 1, 'account_id' => 1, 'category_id' => 1, 'recurring_payment_id' => 1, 'type' => 'expense', 'amount' => 10, 'currency' => 'EUR', 'due_date' => '2026-05-31 00:00:00', 'transaction_id' => 6] + $at);
}

function balances(): array
{
    return DB::table('accounts')->orderBy('id')->pluck('balance', 'id')->map(fn ($balance) => round($balance, 2))->all();
}

test('the migration merges each pair into one row and keeps correct balances identical', function () {
    seedLegacyLedger(popsoBalance: 840);

    $this->migration->up();

    $transfers = DB::table('transactions')->where('type', 'transfer')->orderBy('id')->get()->keyBy('id');

    // 4 legs -> 1 row for pairs A, B, C and D (trashed pair stays trashed); the junk orphan is gone.
    expect($transfers->keys()->all())->toBe([3, 5, 7, 9])
        ->and($transfers[3])->to_account_id->toBe(3)->to_amount->toEqual(50)->description->toBeNull()
        ->and($transfers[5])->to_account_id->toBe(2)->to_amount->toEqual(10)->description->toBe('Rent share') // the incoming leg's account wins
        ->and($transfers[7])->to_account_id->toBe(4)->to_amount->toEqual(100)
        ->and($transfers[9])->deleted_at->not->toBeNull()
        ->and($transfers->pluck('category_id')->filter()->all())->toBe([])
        ->and(DB::table('transactions')->count())->toBe(6)
        ->and(balances())->toEqual([1 => 840, 2 => -9.93, 3 => 50, 4 => 100]);

    // Files and payments follow the surviving row; dates are plain Y-m-d.
    expect(DB::table('transaction_files')->value('transaction_id'))->toBe(5)
        ->and(DB::table('payments')->value('transaction_id'))->toBe(5)
        ->and(DB::table('transactions')->pluck('date')->unique()->all())->toBe(['2026-05-11'])
        ->and(DB::table('payments')->value('due_date'))->toBe('2026-05-31')
        ->and(DB::table('recurring_payments')->first())->start_date->toBe('2026-05-01')->end_date->toBe('2026-06-30');

    // The legacy columns are gone and nothing dangles.
    expect(Schema::hasColumns('transactions', ['linked_transaction_id', 'from_account_id', 'exchange_rate']))->toBeFalse()
        ->and(Schema::hasColumn('transactions', 'to_amount'))->toBeTrue()
        ->and(DB::select('pragma foreign_key_check'))->toBe([])
        ->and(DB::scalar('pragma foreign_keys'))->toBe(1);

    $this->artisan('accounts:verify-balances')->assertSuccessful();
});

test('the migration makes the ledger the truth when stored balances drifted', function () {
    seedLegacyLedger(popsoBalance: 868.12);

    $this->migration->up();

    expect(balances()[1])->toEqual(840.0);
    $this->artisan('accounts:verify-balances')->assertSuccessful();
});

test('the migration snapshots a database that has data before each run and is safe to run twice', function () {
    seedLegacyLedger(popsoBalance: 840);

    $this->migration->up();
    $after = DB::table('transactions')->orderBy('id')->get()->all();
    $this->migration->up();

    expect(File::glob(storage_path('app/backups/pre-ledger-*')))->toHaveCount(count($this->backupsBefore) + 2)
        ->and(DB::table('transactions')->orderBy('id')->get()->all())->toEqual($after)
        ->and(balances())->toEqual([1 => 840, 2 => -9.93, 3 => 50, 4 => 100]);
});

test('the migration copes with legs that are missing their partner', function () {
    seedLegacyLedger(popsoBalance: 840);
    // Outgoing leg whose incoming partner vanished, and an incoming leg whose outgoing partner vanished.
    DB::statement('pragma foreign_keys = off');
    DB::table('transactions')->where('id', 4)->delete();
    DB::table('transactions')->where('id', 5)->delete();
    DB::statement('pragma foreign_keys = on');

    $this->migration->up();

    $outgoing = DB::table('transactions')->find(3);
    $incoming = DB::table('transactions')->find(6);

    expect($outgoing)->to_account_id->toBe(3)->to_amount->toEqual(50)
        ->and($incoming)->type->toBe('transfer')->account_id->toBe(1)->to_account_id->toBe(2)->to_amount->toEqual(10);
    $this->artisan('accounts:verify-balances')->assertSuccessful();
});
