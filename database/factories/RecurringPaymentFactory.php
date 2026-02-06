<?php

namespace Database\Factories;

use App\Enums\IntervalType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RecurringPayment>
 */
class RecurringPaymentFactory extends Factory
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
            'name' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => fake()->randomElement(['EUR', 'RON', 'USD', 'GBP']),
            'interval_type' => fake()->randomElement([IntervalType::Days, IntervalType::Weeks, IntervalType::Months, IntervalType::Years]),
            'interval_value' => fake()->numberBetween(1, 12),
            'start_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'end_date' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            'installments' => fake()->optional()->numberBetween(1, 12),
            'is_active' => true,
        ];
    }
}
