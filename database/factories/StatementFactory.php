<?php

namespace Database\Factories;

use App\Enums\StatementParseStatus;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Statement>
 */
class StatementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => null,
            'institution' => 'Test Bank',
            'account_label' => null,
            'period_start' => null,
            'period_end' => null,
            'original_filename' => 'statement.csv',
            'mime_type' => 'text/csv',
            'size' => 100,
            'sha256' => fake()->sha256(),
            'path' => 'statements/test/'.fake()->uuid().'.csv',
            'extracted_text' => null,
            'parser' => null,
            'parse_status' => StatementParseStatus::Done,
            'parsed_at' => null,
        ];
    }
}
