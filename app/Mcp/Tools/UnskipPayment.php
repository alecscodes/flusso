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

#[Name('unskip_payment')]
#[Description('Undo skip_payment: the payment is due again.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UnskipPayment extends FlussoTool
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): Response
    {
        $payment = $this->user($request)->payments()->find($request->integer('id'));

        if ($payment === null) {
            return $this->notFound('Payment');
        }

        $this->payments->unskip($payment);

        return $this->json($this->payment($payment->fresh(['account', 'category'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The payment id (see list_payments).')->required(),
        ];
    }
}
