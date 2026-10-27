<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('resume_planned_payment')]
#[Description('Resume a paused planned payment: its upcoming payments are scheduled again from today on and it shows up in the payment lists and totals again.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ResumePlannedPayment extends PlannedPaymentTool
{
    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $rule = $this->owned($request, $user->recurringPayments(), 'Planned payment');

        if ($rule instanceof Response) {
            return $rule;
        }

        $input = [...$this->currentInput($rule), 'is_active' => true];
        $rule = $this->plannedPayments->updateRecurringPayment($rule, $this->validatedInput($user, $input));

        return $this->json($this->present($rule));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The planned payment id.')->required(),
        ];
    }
}
