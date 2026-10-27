<?php

namespace App\Mcp\Tools;

use App\Services\PaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('skip_payment')]
#[Description('Skip one payment (e.g. this month\'s bill is not due). It stays visible as skipped and is not generated again; undo with unskip_payment.')]
#[IsDestructive(false)]
#[IsIdempotent]
class SkipPayment extends FlussoTool
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): Response
    {
        $payment = $this->owned($request, $this->user($request)->payments(), 'Payment');

        if ($payment instanceof Response) {
            return $payment;
        }

        $this->payments->skip($payment);

        return $this->json($this->payment($payment->fresh(['account', 'category'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The payment id (see list_payments).')->required(),
        ];
    }
}
