<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_planned_payment')]
#[Description('Delete a planned payment and all its unpaid payments. Payments already made stay in the transaction history. Confirm with the user first.')]
#[IsDestructive]
class DeletePlannedPayment extends FlussoTool
{
    public function handle(Request $request): Response
    {
        $rule = $this->owned($request, $this->user($request)->recurringPayments(), 'Planned payment');

        if ($rule instanceof Response) {
            return $rule;
        }

        $rule->delete();

        return $this->json(['deleted' => $rule->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
        ];
    }
}
