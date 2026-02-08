<?php

namespace Database\Factories;

use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
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
            'recurring_payment_id' => \App\Models\RecurringPayment::factory(),
            'type' => fake()->randomElement([PaymentType::Income, PaymentType::Expense]),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => fake()->randomElement(['EUR', 'RON', 'USD', 'GBP']),
            'description' => fake()->optional()->sentence(),
            'due_date' => fake()->dateTimeBetween('-1 month', '+1 month'),
            'is_paid' => false,
            'paid_at' => null,
        ];
    }
}
