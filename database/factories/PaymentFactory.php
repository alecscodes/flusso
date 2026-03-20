<?php

namespace Database\Factories;

use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
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
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'category_id' => Category::factory(),
            'recurring_payment_id' => RecurringPayment::factory(),
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
