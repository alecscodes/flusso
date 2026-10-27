<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryType;
use App\Http\Requests\CategoryUpdateRequest;
use App\Services\CategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_category')]
#[Description('Rename or change a category. Pass only the fields to change.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateCategory extends FlussoTool
{
    public function __construct(private CategoryService $categories) {}

    public function handle(Request $request): Response
    {
        $category = $this->owned($request, $this->user($request)->categories(), 'Category');

        if ($category instanceof Response) {
            return $category;
        }

        $input = [
            ...['name' => $category->name, 'type' => $category->type->value, 'icon' => $category->icon, 'color' => $category->color],
            ...$request->all(['name', 'type', 'icon', 'color']),
        ];
        $data = Validator::validate($input, (new CategoryUpdateRequest)->rules());

        return $this->json($this->category($this->categories->updateCategory($category, $data)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'name' => $schema->string(),
            'type' => $schema->string()->enum(CategoryType::class),
            'icon' => $schema->string(),
            'color' => $schema->string(),
        ];
    }
}
