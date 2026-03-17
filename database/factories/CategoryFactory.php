<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
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
            'name' => fake()->word(),
            'type' => fake()->randomElement([CategoryType::Income, CategoryType::Expense]),
            'icon' => fake()->optional()->word(),
            'color' => fake()->optional()->hexColor(),
        ];
    }
}
