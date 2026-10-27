<?php

namespace App\Mcp\Tools;

use App\Services\TransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_transaction')]
#[Description('Delete a transaction; the balances of its accounts are corrected. If it paid a planned payment, that payment becomes open again. Confirm with the user first.')]
#[IsDestructive]
class DeleteTransaction extends FlussoTool
{
    public function __construct(private TransactionService $transactions) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $transaction = $this->owned($request, $user->transactions(), 'Transaction');

        if ($transaction instanceof Response) {
            return $transaction;
        }

        $accountIds = [$transaction->account_id, $transaction->to_account_id];
        $this->transactions->deleteTransaction($transaction);

        return $this->json(['deleted' => $transaction->id, 'balances' => $this->balances($user, $accountIds)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The transaction id.')->required(),
        ];
    }
}
