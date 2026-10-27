<?php

namespace App\Mcp\Tools;

use App\Enums\TransactionType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_transactions')]
#[Description('Search transactions, newest first. All filters are optional and combine. A transfer shows up once, for both its source and destination account. `search` matches the description, or the exact amount when it is a number.')]
#[IsReadOnly]
class SearchTransactions extends FlussoTool
{
    public function handle(Request $request): Response
    {
        $user = $this->user($request);

        $input = $this->validated($request->all(), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $user->transactions()->filter([
            'search' => $input['search'] ?? null,
            'account_id' => $input['account_id'] ?? null,
            'category_id' => $input['category_id'] ?? null,
            'type' => $input['type'] ?? null,
            'date_start' => $input['date_from'] ?? null,
            'date_end' => $input['date_to'] ?? null,
        ]);

        $total = (clone $query)->count();
        $transactions = $query->with(['account', 'category', 'toAccount'])
            ->orderByDesc('date')->orderByDesc('id')
            ->limit($input['limit'] ?? 50)->get();

        return $this->json([
            'total_matching' => $total,
            'returned' => $transactions->count(),
            'transactions' => $transactions->map(fn ($transaction) => $this->transaction($transaction))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('First day, YYYY-MM-DD (inclusive).'),
            'date_to' => $schema->string()->description('Last day, YYYY-MM-DD (inclusive).'),
            'account_id' => $schema->integer()->description('Only transactions touching this account.'),
            'category_id' => $schema->integer(),
            'type' => $schema->string()->enum(['expense', 'income', 'transfer']),
            'search' => $schema->string()->description('Text in the description, or an exact amount.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Default 50, at most 200.'),
        ];
    }
}
