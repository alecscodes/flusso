<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

class BelongsToUser implements ValidationRule
{
    public function __construct(
        private string $modelClass,
        private ?int $userId = null
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        $userId = $this->userId ?? auth()->id();

        if (! $userId) {
            $fail('User not authenticated.');

            return;
        }

        /** @var Model $model */
        $model = $this->modelClass::find($value);

        if (! $model) {
            $fail("The selected {$attribute} does not exist.");

            return;
        }

        if ($model->user_id !== $userId) {
            $fail("The selected {$attribute} does not belong to you.");
        }
    }
}
