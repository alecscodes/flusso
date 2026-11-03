<?php

namespace Database\Factories;

use App\Enums\StatementLineStatus;
use App\Models\Statement;
use App\Models\StatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementLine>
 */
class StatementLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement_id' => Statement::factory(),
            'user_id' => fn (array $attributes) => Statement::query()->find($attributes['statement_id'])?->user_id,
            'posted_on' => now()->toDateString(),
            'amount' => -12.50,
            'currency' => 'EUR',
            'description' => fake()->words(3, true),
            'raw_line' => null,
            'parsed_by' => 'csv',
            'matched_transaction_id' => null,
            'match_status' => StatementLineStatus::Unmatched,
        ];
    }
}
