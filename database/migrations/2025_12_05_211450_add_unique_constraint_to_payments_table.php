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
        // Add unique constraint to prevent duplicate payments for the same recurring payment and due date
        // Only applies when recurring_payment_id is not null
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['recurring_payment_id', 'due_date'], 'payments_recurring_due_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_recurring_due_date_unique');
        });
    }
};
