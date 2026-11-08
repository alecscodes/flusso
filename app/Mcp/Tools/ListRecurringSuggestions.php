<?php

namespace App\Mcp\Tools;

use App\Services\RecurringSuggestionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_recurring_suggestions')]
#[Description('Charges in the expense history that keep coming back (weekly, monthly or yearly) and could become a planned payment, with the latest amount, how often it was seen, the next expected date and whether the price jumped. Merchants the owner dismissed or already planned are left out. Nothing is created: use create_planned_payment only when the owner agrees.')]
#[IsReadOnly]
class ListRecurringSuggestions extends FlussoTool
{
    public function __construct(private RecurringSuggestionService $suggestions) {}

    public function handle(Request $request): Response
    {
        return $this->json($this->suggestions->suggestionsFor($this->user($request))->values()->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
