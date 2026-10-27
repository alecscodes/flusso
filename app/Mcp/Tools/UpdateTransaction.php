<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TransactionRequest;
use App\Services\TransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_transaction')]
#[Description('Change a transaction. Pass only the fields to change; the rest keeps its value (pass null to clear category_id or description). For a transfer, `to_amount` is recalculated when the amount or an account changes, unless you pass it. Returns the transaction and the new balances of every account it touched (previous accounts included).')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateTransaction extends FlussoTool
{
    private const array FIELDS = ['type', 'account_id', 'category_id', 'to_account_id', 'amount', 'to_amount', 'description', 'date'];

    public function __construct(private TransactionService $transactions) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $transaction = $this->owned($request, $user->transactions(), 'Transaction');

        if ($transaction instanceof Response) {
            return $transaction;
        }

        $current = [
            'type' => $transaction->type->value,
            'account_id' => $transaction->account_id,
            'category_id' => $transaction->category_id,
            'to_account_id' => $transaction->to_account_id,
            'amount' => $transaction->amount,
            'to_amount' => $transaction->to_amount,
            'description' => $transaction->description,
            'date' => $transaction->date->toDateString(),
        ];
        $changes = $this->passed($request, self::FIELDS);
        $input = [...$current, ...$changes];

        // What arrived in the destination only stays when nothing it depends on changed.
        $unchanged = fn (string $key) => ! array_key_exists($key, $changes) || $changes[$key] == $current[$key];

        if (! array_key_exists('to_amount', $changes) && ! ($unchanged('type') && $unchanged('amount') && $unchanged('account_id') && $unchanged('to_account_id'))) {
            $input['to_amount'] = null;
        }

        if ($input['type'] === 'transfer') {
            $input['category_id'] = null;
        } else {
            $input['to_account_id'] = $input['to_amount'] = null;
        }

        $before = [$transaction->account_id, $transaction->to_account_id];
        $data = $this->validated($input, TransactionRequest::rulesFor($user, $input, $transaction));
        $transaction = $this->transactions->updateTransaction($transaction, $data);

        return $this->json([
            'transaction' => $this->transaction($transaction),
            'balances' => $this->balances($user, [...$before, $transaction->account_id, $transaction->to_account_id]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The transaction id (see search_transactions).')->required(),
            'type' => $schema->string()->enum(['expense', 'income', 'transfer']),
            'account_id' => $schema->integer(),
            'amount' => $schema->number()->min(0.01),
            'date' => $schema->string()->description('YYYY-MM-DD.'),
            'category_id' => $schema->integer()->nullable(),
            'to_account_id' => $schema->integer()->description('Transfers only.'),
            'to_amount' => $schema->number()->min(0.01)->description('Transfers only: the amount received.'),
            'description' => $schema->string()->nullable(),
        ];
    }
}
