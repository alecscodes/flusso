<?php

namespace App\Mcp\Tools;

use App\Services\RecurringPaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_planned_payment')]
#[Description('One planned payment with all its payments (occurrences, each paid, skipped, pending or overdue) and totals.')]
#[IsReadOnly]
class GetPlannedPayment extends FlussoTool
{
    public function __construct(private RecurringPaymentService $plannedPayments) {}

    public function handle(Request $request): Response
    {
        $rule = $this->user($request)->recurringPayments()->with(['account', 'category', 'payments.account', 'payments.category'])->find($request->integer('id'));

        if ($rule === null) {
            return $this->notFound('Planned payment');
        }

        return $this->json([
            ...$this->plannedPayment($rule),
            'statistics' => $this->plannedPayments->getStatistics($rule),
            'payments' => $rule->payments->sortBy('occurrence')->map(fn ($payment) => $this->payment($payment))->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
        ];
    }
}
