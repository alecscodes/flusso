<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryType;
use App\Services\CategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_categories')]
#[Description('List the categories (id, name, type income|expense). A transaction\'s category must be of the same type as the transaction.')]
#[IsReadOnly]
class ListCategories extends FlussoTool
{
    public function __construct(private CategoryService $categories) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate(['type' => ['nullable', Rule::enum(CategoryType::class)]]);

        return $this->json($this->categories->getCategoriesForUser($this->user($request))
            ->when($input['type'] ?? null, fn ($categories, $type) => $categories->filter(fn ($category) => $category->type->value === $type))
            ->map(fn ($category) => $this->category($category))->values()->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(CategoryType::class)->description('Only categories of this type.'),
        ];
    }
}
