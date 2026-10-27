<?php

namespace App\Mcp\Tools;

use App\Http\Requests\PaymentUpdateRequest;
use App\Services\PaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_payment')]
#[Description('Change one unpaid payment (amount, due date, account or description) without touching the planned payment; the schedule then leaves this payment alone. Paid or skipped payments cannot be changed.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdatePayment extends FlussoTool
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $payment = $this->owned($request, $user->payments(), 'Payment');

        if ($payment instanceof Response) {
            return $payment;
        }

        $input = [
            ...['amount' => $payment->amount, 'due_date' => $payment->due_date->toDateString(), 'account_id' => $payment->account_id, 'description' => $payment->description],
            ...$this->passed($request, ['amount', 'due_date', 'account_id', 'description']),
        ];
        $data = $this->validated($input, PaymentUpdateRequest::rulesFor($user));

        return $this->json($this->payment($this->payments->updatePayment($payment, $data)->load(['account', 'category'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The payment id (see list_payments).')->required(),
            'amount' => $schema->number()->min(0.01),
            'due_date' => $schema->string()->description('YYYY-MM-DD.'),
            'account_id' => $schema->integer(),
            'description' => $schema->string()->nullable(),
        ];
    }
}
