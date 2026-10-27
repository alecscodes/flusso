<?php

namespace App\Mcp\Tools;

use App\Services\RecurringPaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_planned_payments')]
#[Description('List planned payments (rules such as "Rent, monthly" or "Insurance, once") with their status: active, paused or finished (nothing left to pay), and the next due date. Use list_payments for the individual dated payments.')]
#[IsReadOnly]
class ListPlannedPayments extends FlussoTool
{
    public function __construct(private RecurringPaymentService $plannedPayments) {}

    public function handle(Request $request): Response
    {
        $status = $request->get('status');

        return $this->json($this->plannedPayments->getRecurringPaymentsForUser($this->user($request))
            ->when(in_array($status, ['active', 'paused', 'finished'], true), fn ($rules) => $rules->filter(fn ($rule) => $rule->status->value === $status))
            ->map(fn ($rule) => $this->plannedPayment($rule))->values()->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['active', 'paused', 'finished'])->description('Only planned payments with this status.'),
        ];
    }
}
