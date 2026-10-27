<?php

use App\Models\RecurringPayment;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Planned payments become rules (`recurring_payments`) with numbered occurrences
 * (`payments`). An occurrence is paid when it links a live transaction, skipped
 * when `skipped_at` is set; `is_paid`, `paid_at` and soft deletes are gone.
 *
 * Plain DB::table on purpose: no model events. Existing rows are cleaned and
 * numbered first, then the table is rebuilt; `payments` is referenced by nothing
 * so the rebuild is safe.
 *
 * Runs outside Laravel's wrapper transaction (SQLite ignores `PRAGMA foreign_keys`
 * inside one) after a VACUUM INTO snapshot. Columns, data and drops are one
 * transaction of our own: all-or-nothing, so `is_paid` (dropped last) only
 * disappears when everything else went through and a retry starts clean.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();

        if (! $schema->hasColumn('payments', 'is_paid')) {
            return;
        }

        $this->backup($db);

        $this->withoutForeignKeys($db, fn () => $db->transaction(function () use ($db, $schema) {
            $schema->table('payments', function (Blueprint $table) {
                $table->unsignedInteger('occurrence')->nullable()->after('recurring_payment_id');
                $table->boolean('is_overridden')->default(false)->after('transaction_id');
                $table->timestamp('skipped_at')->nullable()->after('is_overridden');
            });

            $this->dropTrashedStandalonePayments($db);
            $this->wrapOneOffPayments($db);
            $this->settlePaidState($db);
            $this->dropStaleRows($db);
            $this->numberOccurrences($db);

            $schema->table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_user_id_due_date_is_paid_deleted_at_index');
                $table->dropIndex('payments_recurring_payment_id_deleted_at_index');
                $table->dropUnique('payments_recurring_due_date_unique');
                $table->dropForeign(['recurring_payment_id']);
            });

            $schema->table('payments', fn (Blueprint $table) => $table->dropColumn(['is_paid', 'paid_at', 'deleted_at']));
            $schema->table('payments', fn (Blueprint $table) => $table->unsignedBigInteger('recurring_payment_id')->nullable(false)->change());

            $schema->table('payments', function (Blueprint $table) {
                $table->foreign('recurring_payment_id')->references('id')->on('recurring_payments')->cascadeOnDelete();
                $table->unique(['recurring_payment_id', 'occurrence']);
                $table->index(['user_id', 'due_date']);
            });

            $violations = $db->select('pragma foreign_key_check');

            throw_if($violations !== [], RuntimeException::class, 'Foreign key violations after the planned payments migration: '.json_encode($violations));
        }));
    }

    /**
     * Schema only: soft-deleted rows and the old paid flags are not restored.
     */
    public function down(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->table('payments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'due_date']);
            $table->dropUnique(['recurring_payment_id', 'occurrence']);
            $table->dropForeign(['recurring_payment_id']);
        });

        $schema->table('payments', function (Blueprint $table) {
            $table->dropColumn(['occurrence', 'is_overridden', 'skipped_at']);
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->softDeletes();
        });

        $schema->table('payments', fn (Blueprint $table) => $table->unsignedBigInteger('recurring_payment_id')->nullable()->change());

        $schema->table('payments', function (Blueprint $table) {
            $table->foreign('recurring_payment_id')->references('id')->on('recurring_payments')->nullOnDelete();
            $table->index(['user_id', 'due_date', 'is_paid', 'deleted_at']);
            $table->index(['recurring_payment_id', 'deleted_at']);
            $table->unique(['recurring_payment_id', 'due_date'], 'payments_recurring_due_date_unique');
        });

        DB::table('payments')->whereNotNull('transaction_id')->update(['is_paid' => true]);
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

    private function backup(Connection $db): void
    {
        $path = $db->getDatabaseName();

        if ($db->getDriverName() !== 'sqlite' || ! is_file($path) || ! $db->table('payments')->exists()) {
            return;
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $db->statement('vacuum into ?', [$directory.'/pre-planned-payments-'.now()->format('Ymd-His-u').'.sqlite']);
    }

    /**
     * A standalone payment the owner deleted (and never paid) must not come back as a rule.
     */
    private function dropTrashedStandalonePayments(Connection $db): void
    {
        $db->table('payments')->whereNull('recurring_payment_id')->whereNotNull('deleted_at')
            ->where('is_paid', false)->whereNull('transaction_id')->delete();
    }

    /**
     * Every occurrence belongs to a rule now: a standalone payment becomes a rule that runs once.
     */
    private function wrapOneOffPayments(Connection $db): void
    {
        foreach ($db->table('payments')->whereNull('recurring_payment_id')->get() as $payment) {
            $ruleId = $db->table('recurring_payments')->insertGetId([
                'user_id' => $payment->user_id,
                'account_id' => $payment->account_id,
                'category_id' => $payment->category_id,
                'name' => $payment->description ?: 'Planned payment',
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'interval_type' => 'months',
                'interval_value' => 1,
                'start_date' => $payment->due_date,
                'installments' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $db->table('payments')->where('id', $payment->id)->update(['recurring_payment_id' => $ruleId]);
        }
    }

    /**
     * Paid means "linked to a live transaction". A payment that was flagged paid
     * but lost its transaction (it was deleted from the list) is closed as
     * skipped: balance-neutral, and it does not come back as an overdue bill.
     * Trashed paid payments are history and are restored.
     */
    private function settlePaidState(Connection $db): void
    {
        $db->table('payments')->where('is_paid', true)->whereNotNull('deleted_at')->update(['deleted_at' => null]);

        $liveTransactions = $db->table('transactions')->whereNull('deleted_at')->pluck('id')->flip();
        $stale = $db->table('payments')
            ->where(fn ($query) => $query->where('is_paid', true)->orWhereNotNull('transaction_id'))
            ->get()
            ->reject(fn ($payment) => $liveTransactions->has($payment->transaction_id));

        foreach ($stale as $payment) {
            $db->table('payments')->where('id', $payment->id)->update([
                'transaction_id' => null,
                'skipped_at' => $payment->is_paid ? ($payment->paid_at ?? $payment->updated_at) : null,
            ]);
        }

        Log::info('Planned payments migration: '.$stale->count().' payments lost their transaction.', $stale->pluck('id')->all());
    }

    /**
     * Unpaid occurrences of deleted rules, and trashed unpaid ones, are noise.
     * (A trashed one on a live rule that is already past due stays as skipped so
     * the schedule does not resurrect it.)
     */
    private function dropStaleRows(Connection $db): void
    {
        $live = $db->table('recurring_payments')->whereNull('deleted_at')->select('id');

        $db->table('payments')->whereNotNull('deleted_at')->whereIn('recurring_payment_id', $live)
            ->where('due_date', '<', now()->toDateString())
            ->update(['deleted_at' => null, 'skipped_at' => now()]);

        $db->table('payments')->whereNotNull('deleted_at')->delete();
        $db->table('payments')->whereNotIn('recurring_payment_id', $live)->whereNull('transaction_id')->delete();
    }

    /**
     * Occurrence n is the payment on the rule's n-th date. Payments that do not sit
     * on the schedule are marked as edited by hand and numbered above everything the
     * rule schedules or already holds, so they never take a scheduled number. A rule whose first payment
     * is not on its start date but whose whole history follows the schedule from
     * there gets that start date, so syncing does not shift today's payment.
     */
    private function numberOccurrences(Connection $db): void
    {
        foreach ($db->table('recurring_payments')->get() as $row) {
            $payments = $db->table('payments')->where('recurring_payment_id', $row->id)->orderBy('due_date')->orderBy('id')->get();

            if ($payments->isEmpty()) {
                continue;
            }

            $rule = (new RecurringPayment)->forceFill((array) $row);
            $numbers = $this->number($rule, $payments);

            if (in_array(null, $numbers->all(), true)) {
                $realigned = (new RecurringPayment)->forceFill([...(array) $row, 'start_date' => $payments->first()->due_date]);
                $realignedNumbers = $this->number($realigned, $payments);

                if (! in_array(null, $realignedNumbers->all(), true)) {
                    Log::info("Planned payments migration: rule {$row->id} start date {$row->start_date} -> {$payments->first()->due_date}.");
                    $db->table('recurring_payments')->where('id', $row->id)->update(['start_date' => $payments->first()->due_date]);
                    $rule = $realigned;
                    $numbers = $realignedNumbers;
                }
            }

            $next = max([-1, ...$numbers->filter(fn ($number) => $number !== null)->all(), ...array_keys($rule->targetOccurrences())]) + 1;

            foreach ($payments as $index => $payment) {
                $db->table('payments')->where('id', $payment->id)->update(
                    $numbers[$index] !== null
                        ? ['occurrence' => $numbers[$index]]
                        : ['occurrence' => $next++, 'is_overridden' => true],
                );
            }
        }
    }

    /**
     * The occurrence number of each payment when it sits on the schedule, else null.
     *
     * @param  Collection<int, stdClass>  $payments
     * @return Collection<int, int<0, max>|null>
     */
    private function number(RecurringPayment $rule, Collection $payments): Collection
    {
        $last = -1;
        $numbers = [];

        foreach ($payments as $key => $payment) {
            $numbers[$key] = null;

            for ($n = $last + 1; ($date = $rule->dateFor($n))->toDateString() <= $payment->due_date; $n++) {
                if ($date->toDateString() === $payment->due_date) {
                    $numbers[$key] = $last = $n;

                    break;
                }
            }
        }

        return collect($numbers);
    }
};
