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
        Schema::create('transaction_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('income_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('expense_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['income_transaction_id', 'expense_transaction_id'], 'transaction_settlements_pair_unique');
            $table->index('expense_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_settlements');
    }
};
