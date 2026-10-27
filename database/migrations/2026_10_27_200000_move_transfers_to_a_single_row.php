<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Transfers become ONE row (`account_id` -> `to_account_id`, `amount` leaving,
 * `to_amount` arriving), dates become plain 'Y-m-d', and every balance is
 * recomputed from the ledger. Plain DB::table on purpose: no model events.
 *
 * Runs outside Laravel's wrapper transaction because SQLite ignores
 * `PRAGMA foreign_keys` inside one, and rebuilding `transactions` with foreign
 * keys on would cascade-delete its files. The work is wrapped in our own
 * transaction instead, so it is all-or-nothing, and is safe to re-run.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();

        $this->backup($db);

        $this->withoutForeignKeys($db, function () use ($db, $schema) {
            $db->transaction(function () use ($db, $schema) {
                $this->normalize($db);

                if (! $schema->hasColumn('transactions', 'to_amount')) {
                    $schema->table('transactions', fn (Blueprint $table) => $table->decimal('to_amount', 12, 2)->nullable()->after('to_account_id'));
                }

                if ($schema->hasColumn('transactions', 'linked_transaction_id')) {
                    $this->mergeTransfers($db);

                    $schema->table('transactions', function (Blueprint $table) {
                        $table->dropForeign(['linked_transaction_id']);
                        $table->dropForeign(['from_account_id']);
                        $table->dropColumn(['linked_transaction_id', 'from_account_id', 'exchange_rate']);
                        $table->index('to_account_id');
                    });
                }

                $this->recalculateBalances($db);
                $this->assertForeignKeysHold($db);
            });
        });
    }

    /**
     * Schema only: the merged data is not split back into two legs.
     */
    public function down(): void
    {
        $db = DB::connection();

        $this->withoutForeignKeys($db, function () use ($db) {
            $db->getSchemaBuilder()->table('transactions', function (Blueprint $table) {
                $table->dropIndex(['to_account_id']);
                $table->dropColumn('to_amount');
                $table->decimal('exchange_rate', 10, 6)->nullable();
                $table->foreignId('from_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('linked_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            });
        });
    }

    private function withoutForeignKeys(Connection $db, Closure $callback): void
    {
        $enabled = $db->getDriverName() === 'sqlite' && $db->scalar('pragma foreign_keys');

        if ($enabled) {
            $db->statement('pragma foreign_keys = off');
        }

        try {
            $callback();
        } finally {
            if ($enabled) {
                $db->statement('pragma foreign_keys = on');
            }
        }
    }

    /**
     * Foreign keys were off while rows moved: fail (and roll back) if any now dangle.
     */
    private function assertForeignKeysHold(Connection $db): void
    {
        $violations = $db->select('pragma foreign_key_check');

        throw_if($violations !== [], RuntimeException::class, 'Foreign key violations after the ledger migration: '.json_encode($violations));
    }

    /**
     * Consistent snapshot of a SQLite database that holds data, before anything changes.
     */
    private function backup(Connection $db): void
    {
        $path = $db->getDatabaseName();

        if ($db->getDriverName() !== 'sqlite' || ! is_file($path) || ! $db->table('transactions')->exists()) {
            return;
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $db->statement('vacuum into ?', [$directory.'/pre-ledger-'.now()->format('Ymd-His-u').'.sqlite']);
    }

    private function normalize(Connection $db): void
    {
        // Trimming rate dates to 10 characters must not collide with the unique (from, to, date) index.
        $db->statement('delete from currency_exchange_rates where id not in (select max(id) from currency_exchange_rates group by from_currency, to_currency, substr(date, 1, 10))');

        foreach ([
            'transactions' => ['date'],
            'payments' => ['due_date'],
            'recurring_payments' => ['start_date', 'end_date'],
            'currency_exchange_rates' => ['date'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $db->table($table)->whereRaw("length($column) > 10")->update([$column => DB::raw("substr($column, 1, 10)")]);
            }
        }

        $db->table('accounts')->update([
            'balance' => DB::raw('round(balance, 2)'),
            'initial_balance' => DB::raw('round(initial_balance, 2)'),
        ]);
    }

    private function mergeTransfers(Connection $db): void
    {
        $legs = $db->table('transactions')->where('type', 'transfer')->whereNull('to_amount')->orderBy('id')->get()->keyBy('id');
        $accounts = $db->table('accounts')->pluck('id')->flip();
        $this->linkUnlinkedLegs($db, $legs);
        $handled = [];
        $merged = 0;
        $orphans = [];

        $unknownSource = $legs->filter(fn ($leg) => $leg->from_account_id === null && $leg->deleted_at === null);
        throw_if($unknownSource->isNotEmpty(), RuntimeException::class, 'Transfer legs without a source account: '.$unknownSource->keys()->implode(', '));

        // The outgoing leg is the one booked on the source account.
        foreach ($legs as $leg) {
            if ($leg->from_account_id === null || (int) $leg->account_id !== (int) $leg->from_account_id) {
                continue;
            }

            $handled[$leg->id] = true;
            $partner = $legs->get($leg->linked_transaction_id);

            // The incoming leg's account wins: 379/380 in production were edited so the legs disagree.
            if ($partner && (int) $partner->linked_transaction_id === (int) $leg->id && (int) $partner->account_id !== (int) $leg->account_id) {
                $handled[$partner->id] = true;
                $merged++;

                $this->update($db, $leg, [
                    'to_account_id' => $partner->account_id,
                    'to_amount' => round($partner->amount, 2),
                ]);
                $db->table('transaction_files')->where('transaction_id', $partner->id)->update(['transaction_id' => $leg->id]);
                $db->table('payments')->where('transaction_id', $partner->id)->update(['transaction_id' => $leg->id]);
                $db->table('transactions')->where('id', $partner->id)->delete();

                continue;
            }

            $orphans[] = "outgoing leg {$leg->id} has no incoming leg";

            if (! $accounts->has($leg->to_account_id)) {
                $this->discardOrConvertToExpense($db, $leg);

                continue;
            }

            $this->update($db, $leg, [
                'to_account_id' => $leg->to_account_id,
                'to_amount' => round($leg->amount * ($leg->exchange_rate ?? 1), 2),
            ]);
        }

        // Anything left is an incoming leg without an outgoing one, or junk.
        foreach ($legs->reject(fn ($leg) => isset($handled[$leg->id])) as $leg) {
            if ($leg->deleted_at !== null) {
                $orphans[] = "trashed leg {$leg->id} removed";
                $db->table('transactions')->where('id', $leg->id)->delete();
            } elseif ($leg->from_account_id !== null && $leg->from_account_id !== $leg->account_id && $accounts->has($leg->from_account_id)) {
                $orphans[] = "incoming leg {$leg->id} became a transfer from account {$leg->from_account_id}";
                $this->update($db, $leg, [
                    'account_id' => $leg->from_account_id,
                    'to_account_id' => $leg->account_id,
                    'amount' => round($leg->amount / ($leg->exchange_rate ?? 1), 2),
                    'to_amount' => round($leg->amount, 2),
                ]);
            } else {
                $orphans[] = "incoming leg {$leg->id} became income";
                $db->table('transactions')->where('id', $leg->id)->update(['type' => 'income', 'to_account_id' => null]);
            }
        }

        Log::info("Ledger migration: merged {$merged} transfer pairs, ".count($orphans).' orphan legs.', $orphans);
    }

    /**
     * Legs that were never linked (both sides NULL) would each be promoted on their
     * own and count twice. Pair a live outgoing leg with the live incoming leg of the
     * same route and date (same amount too when both are in one currency) first.
     *
     * @param  Collection<array-key, stdClass>  $legs
     */
    private function linkUnlinkedLegs(Connection $db, Collection $legs): void
    {
        $unlinked = $legs->filter(fn ($leg) => $leg->linked_transaction_id === null && $leg->deleted_at === null && $leg->from_account_id !== null);
        $incoming = $unlinked->filter(fn ($leg) => (int) $leg->account_id !== (int) $leg->from_account_id);

        foreach ($unlinked->filter(fn ($leg) => (int) $leg->account_id === (int) $leg->from_account_id) as $outgoing) {
            $partner = $incoming->first(fn ($leg) => $leg->linked_transaction_id === null
                && (int) $leg->from_account_id === (int) $outgoing->account_id
                && (int) $leg->account_id === (int) $outgoing->to_account_id
                && $leg->date === $outgoing->date
                && ($leg->currency !== $outgoing->currency || (float) $leg->amount === (float) $outgoing->amount));

            if ($partner === null) {
                continue;
            }

            $outgoing->linked_transaction_id = $partner->id;
            $partner->linked_transaction_id = $outgoing->id;
            $db->table('transactions')->where('id', $outgoing->id)->update(['linked_transaction_id' => $partner->id]);
            $db->table('transactions')->where('id', $partner->id)->update(['linked_transaction_id' => $outgoing->id]);
        }
    }

    /**
     * Writes the merged transfer; the auto-generated "Transfer to/from X" text is dropped
     * because the UI renders "A -> B", and transfers carry no category.
     *
     * @param  array<string, mixed>  $values
     */
    private function update(Connection $db, object $leg, array $values): void
    {
        $values['category_id'] = null;

        if ($leg->description !== null && preg_match('/^Transfer (to|from) /i', $leg->description)) {
            $values['description'] = null;
        }

        $db->table('transactions')->where('id', $leg->id)->update($values);
    }

    private function discardOrConvertToExpense(Connection $db, object $leg): void
    {
        $query = $db->table('transactions')->where('id', $leg->id);

        if ($leg->deleted_at !== null) {
            $query->delete();
        } else {
            $query->update(['type' => 'expense', 'to_account_id' => null]);
        }
    }

    private function recalculateBalances(Connection $db): void
    {
        $db->statement(<<<'SQL'
            update accounts set balance = round(initial_balance + coalesce((
                select sum(case when t.to_account_id = accounts.id then t.to_amount when t.type = 'income' then t.amount else -t.amount end)
                from transactions t
                where t.deleted_at is null and (t.account_id = accounts.id or t.to_account_id = accounts.id)
            ), 0), 2)
        SQL);
    }
};
