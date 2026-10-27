<?php

namespace App\Services;

use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    public function __construct(private RecurringPaymentService $recurringPayments) {}

    public function getCategoriesForUser(User $user): Collection
    {
        return $user->categories()
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    public function createCategory(User $user, array $data): Category
    {
        return $user->categories()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
        ]);
    }

    public function updateCategory(Category $category, array $data): Category
    {
        DB::transaction(function () use ($category, $data) {
            $category->update([
                'name' => $data['name'] ?? $category->name,
                'type' => $data['type'] ?? $category->type,
                'icon' => $data['icon'] ?? $category->icon,
                'color' => $data['color'] ?? $category->color,
            ]);

            // Pending payments take their type from the category via their rule.
            if ($category->wasChanged('type')) {
                $category->recurringPayments->each(fn (RecurringPayment $rule) => $this->recurringPayments->sync($rule));
            }
        });

        return $category->fresh();
    }
}
