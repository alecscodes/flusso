<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;

class CategoryService
{
    public function getCategoriesForUser(User $user): Collection
    {
        return $user->categories()
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    public function getIncomeCategories(User $user): Collection
    {
        return $user->categories()
            ->income()
            ->orderBy('name')
            ->get();
    }

    public function getExpenseCategories(User $user): Collection
    {
        return $user->categories()
            ->expense()
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
        $category->update([
            'name' => $data['name'] ?? $category->name,
            'type' => $data['type'] ?? $category->type,
            'icon' => $data['icon'] ?? $category->icon,
            'color' => $data['color'] ?? $category->color,
        ]);

        return $category->fresh();
    }

    public function deleteCategory(Category $category): void
    {
        $category->delete();
    }

    public function getCategoryType(Category $category): CategoryType
    {
        return $category->type;
    }
}
