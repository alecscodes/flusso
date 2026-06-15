<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryStoreRequest;
use App\Http\Requests\CategoryUpdateRequest;
use App\Models\Category;
use App\Services\CategoryService;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryService $categoryService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        return Inertia::render('Categories/Index', [
            'categories' => $this->categoryService->getCategoriesForUser($request->user()),
        ]);
    }

    public function store(CategoryStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $this->categoryService->createCategory($request->user(), $request->validated());

        FlashToast::success('Category created successfully.');

        return redirect()->route('categories.index');
    }

    public function show(Category $category): Response
    {
        $this->authorize('view', $category);

        return Inertia::render('Categories/Show', [
            'category' => $category,
        ]);
    }

    public function update(CategoryUpdateRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $this->categoryService->updateCategory($category, $request->validated());

        FlashToast::success('Category updated successfully.');

        return redirect()->route('categories.index');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $this->categoryService->deleteCategory($category);

        FlashToast::success('Category deleted successfully.');

        return redirect()->route('categories.index');
    }
}
