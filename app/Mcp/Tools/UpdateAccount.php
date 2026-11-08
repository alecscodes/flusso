<?php

namespace App\Mcp\Tools;

use App\Http\Requests\AccountUpdateRequest;
use App\Services\AccountService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_account')]
#[Description('Rename an account, change whether it is a savings account or set/clear its low-balance alert threshold. The currency is locked once the account has transactions. Balances only change through transactions.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateAccount extends FlussoTool
{
    public function __construct(private AccountService $accounts) {}

    public function handle(Request $request): Response
    {
        $account = $this->owned($request, $this->user($request)->accounts(), 'Account');

        if ($account instanceof Response) {
            return $account;
        }

        $input = [
            ...['name' => $account->name, 'currency' => $account->currency, 'is_savings' => $account->is_savings],
            ...$request->all(['name', 'currency', 'is_savings', 'alert_threshold']),
        ];
        $input['currency'] = strtoupper((string) $input['currency']);

        $data = Validator::validate($input, AccountUpdateRequest::rulesFor($account));

        return $this->json($this->account($this->accounts->updateAccount($account, $data)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'name' => $schema->string(),
            'currency' => $schema->string()->description('3-letter code. Only changeable while the account has no transactions.'),
            'is_savings' => $schema->boolean(),
            'alert_threshold' => $schema->number()->nullable()->description('Low-balance alert level in the account\'s currency. null removes the alert; leave it out to keep the current one.'),
        ];
    }
}
