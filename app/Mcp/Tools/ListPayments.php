<?php

namespace App\Mcp\Tools;

use App\Services\PaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_payments')]
#[Description('The dated payments of the planned payments. scope "overdue": unpaid ones past their due date. "upcoming": unpaid ones due from today on, within `days` days (default 30). "range": everything due between date_from and date_to, paid and skipped ones included. Each has a status: pending, overdue, paid or skipped.')]
#[IsReadOnly]
class ListPayments extends FlussoTool
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        $input = $this->validated($request->all(), [
            'scope' => ['required', 'in:overdue,upcoming,range'],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'date_from' => ['required_if:scope,range', 'nullable', 'date'],
            'date_to' => ['required_if:scope,range', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $payments = match ($input['scope']) {
            'overdue' => $this->payments->getOverduePayments($user),
            'upcoming' => $this->payments->getUpcomingPayments($user, now()->addDays($input['days'] ?? 30)),
            'range' => $this->payments->getPaymentsBetween($user, date('Y-m-d', strtotime($input['date_from'])), date('Y-m-d', strtotime($input['date_to']))),
        };

        return $this->json($payments->map(fn ($payment) => $this->payment($payment))->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(['overdue', 'upcoming', 'range'])->required(),
            'days' => $schema->integer()->min(1)->max(730)->description('upcoming only: how far ahead, default 30.'),
            'date_from' => $schema->string()->description('range only: YYYY-MM-DD.'),
            'date_to' => $schema->string()->description('range only: YYYY-MM-DD.'),
        ];
    }
}
