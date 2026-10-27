<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the migration against a file-backed SQLite database rebuilt in the legacy
 * `payments` shape (is_paid / paid_at / soft deletes, nullable rule).
 */
beforeEach(function () {
    $this->legacyFile = tempnam(sys_get_temp_dir(), 'planned-').'.sqlite';
    touch($this->legacyFile);
    $this->backupsBefore = File::glob(storage_path('app/backups/pre-planned-*'));
    $this->originalConnection = DB::getDefaultConnection();

    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => $this->legacyFile, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('legacy');
    Schema::clearResolvedInstance('db.schema');

    Artisan::call('migrate', ['--database' => 'legacy', '--force' => true]);
    $this->migration = require database_path('migrations/2026_10_28_100000_redesign_planned_payments.php');
    $this->migration->down();
});

afterEach(function () {
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('legacy');
    Schema::clearResolvedInstance('db.schema');
    File::delete($this->legacyFile);
    File::delete(array_diff(File::glob(storage_path('app/backups/pre-planned-*')), $this->backupsBefore));
});

function seedLegacyPlans(): void
{
    $at = ['created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'];
    DB::table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x'] + $at);
    DB::table('categories')->insert(['id' => 1, 'user_id' => 1, 'name' => 'Bills', 'type' => 'expense'] + $at);
    DB::table('accounts')->insert(['id' => 1, 'user_id' => 1, 'name' => 'Popso', 'currency' => 'EUR', 'balance' => 0, 'initial_balance' => 0] + $at);

    foreach ([1 => null, 2 => '2026-04-30 10:00:00', 3 => null] as $id => $deletedAt) {
        DB::table('transactions')->insert(['id' => $id, 'user_id' => 1, 'account_id' => 1, 'category_id' => 1, 'type' => 'expense', 'amount' => 10, 'currency' => 'EUR', 'date' => '2026-03-27', 'deleted_at' => $deletedAt] + $at);
    }

    $rule = fn (int $id, string $start, ?string $deletedAt = null) => DB::table('recurring_payments')->insert([
        'id' => $id, 'user_id' => 1, 'account_id' => 1, 'category_id' => 1, 'name' => "Rule $id", 'amount' => 10, 'currency' => 'EUR',
        'interval_type' => 'months', 'interval_value' => 1, 'start_date' => $start, 'installments' => null, 'is_active' => true, 'deleted_at' => $deletedAt,
    ] + $at);

    $payment = fn (int $id, ?int $rule, string $due, bool $paid, ?int $transaction = null, ?string $deletedAt = null) => DB::table('payments')->insert([
        'id' => $id, 'user_id' => 1, 'account_id' => 1, 'category_id' => 1, 'recurring_payment_id' => $rule, 'type' => 'expense', 'amount' => 10,
        'currency' => 'EUR', 'due_date' => $due, 'is_paid' => $paid, 'paid_at' => $paid ? '2026-04-01 10:00:00' : null,
        'transaction_id' => $transaction, 'deleted_at' => $deletedAt,
    ] + $at);

    // Rule 1: start date is a day before its payments; one paid, one "paid" whose transaction was
    // deleted, one trashed past due (kept as skipped), one trashed future (gone), one live future.
    $rule(1, '2026-03-26');
    $payment(1, 1, '2026-03-27', true, 1);
    $payment(2, 1, '2026-04-27', true, 2);
    $payment(3, 1, '2026-05-27', false, null, '2026-06-01 10:00:00');
    $payment(4, 1, '2026-12-27', false, null, '2026-06-01 10:00:00');
    $payment(5, 1, '2026-11-27', false);

    // Rule 2 was deleted by hand: its paid history stays, what was owed goes.
    $rule(2, '2026-01-01', '2026-05-01 10:00:00');
    $payment(6, 2, '2026-01-01', true, 3, '2026-05-01 10:00:00');
    $payment(7, 2, '2026-12-01', false);

    // A standalone payment becomes a rule that runs once.
    $payment(8, null, '2026-12-05', false);

    // A rule that already follows its schedule is left alone.
    $rule(3, '2026-01-31');
    $payment(9, 3, '2026-01-31', false);
    $payment(10, 3, '2026-02-28', false);
}

test('the migration numbers the occurrences, settles the paid state and tidies deleted rows', function () {
    $this->travelTo('2026-10-27 12:00:00');
    seedLegacyPlans();

    $this->migration->up();

    $payments = DB::table('payments')->orderBy('id')->get()->keyBy('id');

    expect($payments->keys()->all())->toBe([1, 2, 3, 5, 6, 8, 9, 10])
        ->and($payments->pluck('occurrence', 'id')->only([1, 2, 3, 5, 9, 10])->all())->toBe([1 => 0, 2 => 1, 3 => 2, 5 => 8, 9 => 0, 10 => 1])
        ->and($payments[1])->transaction_id->toBe(1)->skipped_at->toBeNull()
        ->and($payments[2])->transaction_id->toBeNull()->skipped_at->not->toBeNull() // paid, but its transaction was deleted
        ->and($payments[3])->transaction_id->toBeNull()->skipped_at->not->toBeNull() // trashed past due: never resurrected
        ->and($payments[5])->transaction_id->toBeNull()->skipped_at->toBeNull()
        ->and($payments[6])->transaction_id->toBe(3)                                  // paid history of a deleted rule
        ->and($payments->only([1, 2, 3, 5, 6, 9, 10])->pluck('is_overridden')->unique()->all())->toBe([0]);

    $rules = DB::table('recurring_payments')->orderBy('id')->get()->keyBy('id');
    $wrapped = $rules->last();

    expect($rules[1]->start_date)->toBe('2026-03-27')
        ->and($rules[3]->start_date)->toBe('2026-01-31')
        ->and($payments[8]->recurring_payment_id)->toBe($wrapped->id)
        ->and($wrapped)->installments->toBe(1)->start_date->toBe('2026-12-05')
        ->and($payments[8]->occurrence)->toBe(0);

    expect(Schema::hasColumns('payments', ['is_paid', 'paid_at', 'deleted_at']))->toBeFalse()
        ->and(DB::select('pragma foreign_key_check'))->toBe([])
        ->and(DB::scalar('pragma foreign_keys'))->toBe(1);

    // The schedule takes over from there without moving anything that was paid or skipped.
    $this->artisan('payments:sync')->assertSuccessful();
    expect(DB::table('payments')->where('recurring_payment_id', 1)->orderBy('occurrence')->pluck('due_date', 'occurrence')->only([0, 1, 2, 8])->all())
        ->toBe([0 => '2026-03-27', 1 => '2026-04-27', 2 => '2026-05-27', 8 => '2026-11-27']);
});

test('running the migration twice changes nothing', function () {
    seedLegacyPlans();

    $this->migration->up();
    $snapshot = DB::table('payments')->orderBy('id')->get()->toJson();
    $this->migration->up();

    expect(DB::table('payments')->orderBy('id')->get()->toJson())->toBe($snapshot);
});
