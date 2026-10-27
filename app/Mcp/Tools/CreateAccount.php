<?php

namespace App\Mcp\Tools;

use App\Http\Requests\AccountStoreRequest;
use App\Services\AccountService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_account')]
#[Description('Create an account. `balance` is the opening balance (default 0); later changes come from transactions only.')]
#[IsDestructive(false)]
class CreateAccount extends FlussoTool
{
    public function __construct(private AccountService $accounts) {}

    public function handle(Request $request): Response
    {
        $input = $request->all();
        $input['currency'] = strtoupper((string) ($input['currency'] ?? ''));

        $data = $this->validated($input, (new AccountStoreRequest)->rules());

        return $this->json($this->account($this->accounts->createAccount($this->user($request), $data)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'currency' => $schema->string()->description('3-letter code, e.g. EUR or RON. Cannot be changed once the account has transactions.')->required(),
            'balance' => $schema->number()->min(0)->description('Opening balance.'),
            'is_savings' => $schema->boolean()->description('Savings accounts are left out of the available balance.'),
        ];
    }
}
