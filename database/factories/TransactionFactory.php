<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
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
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'category_id' => Category::factory(),
            'type' => fake()->randomElement([TransactionType::Income, TransactionType::Expense, TransactionType::Transfer]),
            'amount' => fake()->randomFloat(2, 1, 1000),
            'currency' => fake()->randomElement(['EUR', 'RON', 'USD', 'GBP']),
            'description' => fake()->optional()->sentence(),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
