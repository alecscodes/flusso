<?php

namespace App\Mcp\Tools;

use App\Http\Requests\MarkPaymentPaidRequest;
use App\Services\PaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('mark_payment_paid')]
#[Description('Pay a payment: books the transaction on the account and links it. By default it is paid as planned, today; pass amount, date or account_id to pay differently (e.g. 14 instead of 13.70); amount is required when the account is in another currency than the payment. Returns the payment, the transaction and the new account balance.')]
#[IsDestructive(false)]
class MarkPaymentPaid extends FlussoTool
{
    public function __construct(private PaymentService $payments) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $payment = $this->owned($request, $user->payments(), 'Payment');

        if ($payment instanceof Response) {
            return $payment;
        }

        $input = $request->all(['amount', 'date', 'account_id']);
        $data = Validator::validate($input, MarkPaymentPaidRequest::rulesFor($user, $payment, $input));
        $transaction = $this->payments->markAsPaid($payment, $data);

        return $this->json([
            'payment' => $this->payment($payment->fresh(['account', 'category'])),
            'transaction' => $this->transaction($transaction),
            'balances' => $this->balances($user, [$transaction->account_id]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The payment id (see list_payments).')->required(),
            'amount' => $schema->number()->min(0.01)->description('Amount actually paid; defaults to the planned amount.'),
            'date' => $schema->string()->description('YYYY-MM-DD; defaults to today.'),
            'account_id' => $schema->integer()->description('Account actually used; defaults to the planned one.'),
        ];
    }
}
