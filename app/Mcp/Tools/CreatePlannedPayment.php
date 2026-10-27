<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_planned_payment')]
#[Description('Plan a payment: a one-time one (repeat "once") or a repeating one (e.g. repeat "months", every 1). Its payments are scheduled automatically; use list_payments to see them. Returns the planned payment.')]
#[IsDestructive(false)]
class CreatePlannedPayment extends PlannedPaymentTool
{
    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $args = $request->all();

        if (! isset($args['repeat'])) {
            return Response::error('The repeat field is required: once, days, weeks, months or years.');
        }

        $input = $this->mergeInput(['interval_type' => 'months', 'interval_value' => 1, 'end_date' => null, 'installments' => null], $args);

        $rule = $this->plannedPayments->createRecurringPayment($user, $this->validatedInput($user, $input));

        return $this->json($this->present($rule));
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = $this->fields($schema);

        foreach (['name', 'account_id', 'category_id', 'amount', 'repeat', 'start_date'] as $required) {
            $fields[$required]->required();
        }

        return $fields;
    }
}
