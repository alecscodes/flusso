<?php

namespace App\Mcp\Tools;

use App\Services\InsightsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_period_insights')]
#[Description('The numbers behind the Insights page for one financial period: income, expenses and net against the previous period, top and unusual categories, biggest expenses, overdue and upcoming payments, people balances and the liquidity outlook. `date` (YYYY-MM-DD) picks the period that contains it; the current period by default. Amounts are in the primary currency. Computed from the data, never narrated by a model.')]
#[IsReadOnly]
class GetPeriodInsights extends FlussoTool
{
    public function __construct(private InsightsService $insights) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $anchor = isset($input['date']) ? CarbonImmutable::parse($input['date'])->startOfDay() : null;

        return $this->json($this->insights->forPeriod($this->user($request), $anchor));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Any day of the wanted period, YYYY-MM-DD. Default: today.'),
        ];
    }
}
