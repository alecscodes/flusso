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
     * A payment that runs once, on its start date.
     */
    public function once(): static
    {
        return $this->state(['installments' => 1]);
    }

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
            'name' => fake()->words(3, true),
            'amount' => 100,
            'interval_type' => IntervalType::Months,
            'interval_value' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'installments' => null,
            'is_active' => true,
        ];
    }
}
