<?php

namespace App\Mcp\Tools;

use App\Services\AccountService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('archive_account')]
#[Description('Archive an account: it disappears from the lists and its planned payments are paused (their unpaid payments are removed). Its transaction history stays and other balances are untouched. Confirm with the user first.')]
#[IsDestructive]
class ArchiveAccount extends FlussoTool
{
    public function __construct(private AccountService $accounts) {}

    public function handle(Request $request): Response
    {
        $account = $this->user($request)->accounts()->find($request->integer('id'));

        if ($account === null) {
            return $this->notFound('Account');
        }

        $this->accounts->deleteAccount($account);

        return $this->json(['archived' => $account->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
        ];
    }
}
