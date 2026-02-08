<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'account_id' => \App\Models\Account::factory(),
            'category_id' => \App\Models\Category::factory(),
            'type' => fake()->randomElement([TransactionType::Income, TransactionType::Expense, TransactionType::Transfer]),
            'amount' => fake()->randomFloat(2, 1, 1000),
            'currency' => fake()->randomElement(['EUR', 'RON', 'USD', 'GBP']),
            'description' => fake()->optional()->sentence(),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
