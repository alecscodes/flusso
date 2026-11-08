<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statements are a permanent archive: many belong to accounts that are not
     * in the app, so the institution and account are also free text.
     */
    public function up(): void
    {
        Schema::create('statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('institution')->nullable();
            $table->string('account_label')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('path');
            $table->longText('extracted_text')->nullable();
            $table->string('parser')->nullable();
            $table->boolean('lines_truncated')->default(false);
            $table->boolean('text_truncated')->default(false);
            $table->boolean('date_order_assumed')->default(false);
            $table->string('parse_status', 16)->default('done');
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sha256']);
            $table->index(['user_id', 'period_end']);
            $table->index(['account_id']);
        });

        Schema::create('statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('posted_on');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->nullable();
            $table->text('description');
            $table->text('raw_line')->nullable();
            $table->string('parsed_by', 16)->default('csv');
            $table->boolean('sign_uncertain')->default(false);
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('match_status', 16)->default('unmatched');
            $table->timestamps();

            $table->index(['user_id', 'posted_on', 'amount']);
            $table->index(['statement_id', 'match_status']);
            $table->index(['matched_transaction_id']);
        });

        $this->createSearchIndex();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS statement_search');
        }

        Schema::dropIfExists('statement_lines');
        Schema::dropIfExists('statements');
    }

    /**
     * The full-text index is optional: when FTS5 is not compiled into SQLite the
     * search quietly falls back to LIKE.
     */
    private function createSearchIndex(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        try {
            DB::statement("CREATE VIRTUAL TABLE IF NOT EXISTS statement_search USING fts5(text, statement_id UNINDEXED, line_id UNINDEXED, user_id UNINDEXED, tokenize = 'unicode61 remove_diacritics 1')");
        } catch (Throwable) {
            // No FTS5 in this SQLite build.
        }
    }
};
