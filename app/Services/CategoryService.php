<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryService
{
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

    /**
     * @param  array{name?: string, type?: string|CategoryType, icon?: string|null, color?: string|null}  $data  icon and color are cleared when passed as null
     */
    public function updateCategory(Category $category, array $data): Category
    {
        DB::transaction(function () use ($category, $data) {
            $category->update([
                'name' => $data['name'] ?? $category->name,
                'type' => $data['type'] ?? $category->type,
                'icon' => array_key_exists('icon', $data) ? $data['icon'] : $category->icon,
                'color' => array_key_exists('color', $data) ? $data['color'] : $category->color,
            ]);

            // Every unpaid occurrence, past-due ones too, takes its type from the category.
            if ($category->wasChanged('type')) {
                $category->payments()->pending()->update(['type' => $category->type->value]);
            }
        });

        return $category;
    }
}
