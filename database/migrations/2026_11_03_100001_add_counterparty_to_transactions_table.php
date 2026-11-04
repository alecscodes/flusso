<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_advance')->default(false);
            $table->index(['user_id', 'counterparty_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'counterparty_id']);
            $table->dropConstrainedForeignId('counterparty_id');
            $table->dropColumn('is_advance');
        });
    }
};
