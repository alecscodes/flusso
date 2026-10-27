<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_planned_payment')]
#[Description('Change a planned payment. Pass only the fields to change. The change applies to its unpaid payments from today on; payments edited individually keep their amount, and paid, skipped and past-due ones stay as they are.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdatePlannedPayment extends PlannedPaymentTool
{
    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $rule = $this->owned($request, $user->recurringPayments(), 'Planned payment');

        if ($rule instanceof Response) {
            return $rule;
        }

        $input = $this->mergeInput($this->currentInput($rule), $request->all());
        $rule = $this->plannedPayments->updateRecurringPayment($rule, $this->validatedInput($user, $input));

        return $this->json($this->present($rule));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('The planned payment id (see list_planned_payments).')->required(), ...$this->fields($schema)];
    }
}
