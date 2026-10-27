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
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'category_id' => fn (array $attributes) => Category::factory()->create(['user_id' => $attributes['user_id']])->id,
            'recurring_payment_id' => fn (array $attributes) => RecurringPayment::factory()->create([
                'user_id' => $attributes['user_id'],
                'account_id' => $attributes['account_id'],
                'category_id' => $attributes['category_id'],
            ])->id,
            'type' => PaymentType::Expense,
            'amount' => 50,
            'description' => fake()->optional()->sentence(),
            'due_date' => now()->toDateString(),
            'occurrence' => null,
        ];
    }

    /**
     * Skipped by the owner.
     */
    public function skipped(): static
    {
        return $this->state(['skipped_at' => now()]);
    }
}
