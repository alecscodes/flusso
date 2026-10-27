<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_category')]
#[Description('Delete a category. Existing transactions stay but show no category; planned payments keep it. It is no longer offered for new entries. Confirm with the user first.')]
#[IsDestructive]
class DeleteCategory extends FlussoTool
{
    public function handle(Request $request): Response
    {
        $category = $this->owned($request, $this->user($request)->categories(), 'Category');

        if ($category instanceof Response) {
            return $category;
        }

        $category->delete();

        return $this->json(['deleted' => $category->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
        ];
    }
}
