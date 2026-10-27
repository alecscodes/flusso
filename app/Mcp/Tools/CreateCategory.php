<?php

namespace App\Mcp\Tools;

use App\Http\Requests\CategoryStoreRequest;
use App\Services\CategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_category')]
#[Description('Create a category. Only do this when no existing category (list_categories) fits.')]
#[IsDestructive(false)]
class CreateCategory extends FlussoTool
{
    public function __construct(private CategoryService $categories) {}

    public function handle(Request $request): Response
    {
        $data = $this->validated($request->all(), (new CategoryStoreRequest)->rules());

        return $this->json($this->category($this->categories->createCategory($this->user($request), $data)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'type' => $schema->string()->enum(['expense', 'income'])->required(),
            'icon' => $schema->string()->description('Optional icon name.'),
            'color' => $schema->string()->description('Optional hex color like #7C3AED.'),
        ];
    }
}
