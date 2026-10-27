<?php

namespace Database\Factories;

use App\Enums\IntervalType;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPayment>
 */
class RecurringPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * A payment that runs once, on its start date.
     */
    public function once(): static
    {
        return $this->state(['installments' => 1]);
    }

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'category_id' => Category::factory(),
            'name' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 10, 500),
            'interval_type' => fake()->randomElement([IntervalType::Days, IntervalType::Weeks, IntervalType::Months, IntervalType::Years]),
            'interval_value' => fake()->numberBetween(1, 3),
            'start_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'end_date' => null,
            'installments' => null,
            'is_active' => true,
        ];
    }
}
