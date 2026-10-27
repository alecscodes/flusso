<?php

namespace App\Mcp\Tools;

use App\Services\AccountService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_accounts')]
#[Description('List the accounts with id, name, currency, current balance (in the account\'s own currency) and whether it is a savings account. Archived accounts are not listed.')]
#[IsReadOnly]
class ListAccounts extends FlussoTool
{
    public function __construct(private AccountService $accounts) {}

    public function handle(Request $request): Response
    {
        return $this->json($this->accounts->getAccountsForUser($this->user($request))->map(fn ($account) => $this->account($account))->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
