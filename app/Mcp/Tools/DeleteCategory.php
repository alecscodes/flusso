<?php

namespace App\Mcp\Tools;

use App\Services\CategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_category')]
#[Description('Delete a category. Existing transactions stay but lose it from the category lists. Confirm with the user first.')]
#[IsDestructive]
class DeleteCategory extends FlussoTool
{
    public function __construct(private CategoryService $categories) {}

    public function handle(Request $request): Response
    {
        $category = $this->user($request)->categories()->find($request->integer('id'));

        if ($category === null) {
            return $this->notFound('Category');
        }

        $this->categories->deleteCategory($category);

        return $this->json(['deleted' => $category->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
        ];
    }
}
