<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('pause_planned_payment')]
#[Description('Pause a planned payment: its untouched unpaid payments due today or later are removed and it disappears from the payment lists and totals while paused. Payments edited individually, paid and skipped ones are kept. Undo with resume_planned_payment, which schedules the payments again.')]
#[IsDestructive(false)]
#[IsIdempotent]
class PausePlannedPayment extends PlannedPaymentTool
{
    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $rule = $this->owned($request, $user->recurringPayments(), 'Planned payment');

        if ($rule instanceof Response) {
            return $rule;
        }

        $input = [...$this->currentInput($rule), 'is_active' => false];
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
