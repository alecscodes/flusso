<?php

namespace App\Mcp\Tools;

use App\Enums\TransactionType;
use App\Http\Requests\TransactionRequest;
use App\Services\TransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_transaction')]
#[Description('Change a transaction. Pass only the fields to change; the rest keeps its value (pass null to clear category_id or description). For a transfer, `to_amount` is recalculated when the amount or an account changes, unless you pass it. Changing a transfer into an expense or income needs no category (categories are optional). Returns the transaction and the new balances of every account it touched (previous accounts included).')]
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
        $changes = $request->all(self::FIELDS);
        $input = [...$current, ...$changes];

        if (! array_key_exists('to_amount', $changes) && ! $this->keepsToAmount($current, $changes)) {
            $input['to_amount'] = null;
        }

        if ($input['type'] === TransactionType::Transfer->value) {
            $input['category_id'] = null;
        } else {
            $input['to_account_id'] = $input['to_amount'] = null;
        }

        $before = [$transaction->account_id, $transaction->to_account_id];
        $data = Validator::validate($input, TransactionRequest::rulesFor($user, $input, $transaction));
        $transaction = $this->transactions->updateTransaction($transaction, $data);

        return $this->json([
            'transaction' => $this->transaction($transaction),
            'balances' => $this->balances($user, [...$before, $transaction->account_id, $transaction->to_account_id]),
        ]);
    }

    /**
     * What arrived in the destination only stays when nothing it depends on changed.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     */
    private function keepsToAmount(array $current, array $changes): bool
    {
        foreach (['type', 'amount', 'account_id', 'to_account_id'] as $key) {
            if (array_key_exists($key, $changes) && $changes[$key] != $current[$key]) {
                return false;
            }
        }

        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The transaction id (see search_transactions).')->required(),
            'type' => $schema->string()->enum(TransactionType::class),
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
