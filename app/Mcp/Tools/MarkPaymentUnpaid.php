<?php

namespace App\Mcp\Tools;

use App\Services\PaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('mark_payment_unpaid')]
#[Description('Undo a payment: its transaction is deleted, the account balance is corrected and the payment is open again. Confirm with the user first.')]
#[IsDestructive]
class MarkPaymentUnpaid extends FlussoTool
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $payment = $user->payments()->find($request->integer('id'));

        if ($payment === null) {
            return $this->notFound('Payment');
        }

        $accountId = $payment->transaction?->account_id;
        $this->payments->markAsUnpaid($payment);

        return $this->json([
            'payment' => $this->payment($payment->fresh(['account', 'category'])),
            'balances' => $this->balances($user, [$accountId]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The payment id.')->required(),
        ];
    }
}
