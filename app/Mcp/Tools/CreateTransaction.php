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

#[Name('create_transaction')]
#[Description('Book an expense, an income or a transfer between two of the user\'s own accounts. `amount` is in the currency of `account_id` (for a transfer: the account the money leaves). For a transfer to an account in another currency, `to_amount` is what arrived there; if omitted, today\'s rate is used. Returns the transaction and the new balances of the touched accounts.')]
#[IsDestructive(false)]
class CreateTransaction extends FlussoTool
{
    public function __construct(private TransactionService $transactions) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        $input = $request->all();
        $input['date'] ??= now()->toDateString();

        $data = Validator::validate($input, TransactionRequest::rulesFor($user, $input));
        $transaction = $this->transactions->createTransaction($user, $data);

        return $this->json([
            'transaction' => $this->transaction($transaction),
            'balances' => $this->balances($user, [$transaction->account_id, $transaction->to_account_id]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(TransactionType::class)->required(),
            'account_id' => $schema->integer()->description('The account the money is booked on (for a transfer: the source).')->required(),
            'amount' => $schema->number()->min(0.01)->description('Positive amount, exactly as the user said.')->required(),
            'date' => $schema->string()->description('YYYY-MM-DD. Defaults to today.'),
            'category_id' => $schema->integer()->description('Not for transfers. Must be a category of the same type.'),
            'to_account_id' => $schema->integer()->description('Transfers only: the destination account.'),
            'to_amount' => $schema->number()->min(0.01)->description('Transfers only: the amount received, in the destination account\'s currency.'),
            'description' => $schema->string()->description('Free text, e.g. the merchant or note.'),
        ];
    }
}
