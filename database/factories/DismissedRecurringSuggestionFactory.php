<?php

namespace Database\Factories;

use App\Enums\SuggestionDismissalKind;
use App\Models\DismissedRecurringSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DismissedRecurringSuggestion>
 */
class DismissedRecurringSuggestionFactory extends Factory
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
            'normalized_key' => fake()->unique()->word(),
            'kind' => SuggestionDismissalKind::NotRecurring,
            'snooze_until' => null,
        ];
    }

    /**
     * Only being tried: hidden until the given day.
     */
    public function temporary(string $until): static
    {
        return $this->state(['kind' => SuggestionDismissalKind::Temporary, 'snooze_until' => $until]);
    }
}
