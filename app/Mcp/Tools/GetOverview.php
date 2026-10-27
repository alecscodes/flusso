<?php

namespace App\Mcp\Tools;

use App\Services\AccountService;
use App\Services\PaymentService;
use App\Services\TransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_overview')]
#[Description('Where the money stands: today\'s date, every account balance, available/savings/total balance in the primary currency, income and expenses of the current financial period with the top spending categories, and the payments that are overdue or due in the next 30 days. Start here for questions like "how much do I have" or "what is coming up".')]
#[IsReadOnly]
class GetOverview extends FlussoTool
{
    public function __construct(
        private AccountService $accounts,
        private TransactionService $transactions,
        private PaymentService $payments,
    ) {}

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $period = $user->getFinancialPeriod();
        $summary = $this->accounts->getAccountSummary($user);
        $totals = $this->transactions->getTransactionSummaryForPeriod($user, $period['start'], $period['end']);

        return $this->json([
            'today' => now()->toDateString(),
            'primary_currency' => $summary['primaryCurrency'],
            'accounts' => $summary['accounts']->map(fn ($account) => $this->account($account))->all(),
            'available_balance' => round($summary['availableBalance'], 2),
            'savings_balance' => round($summary['savingsBalance'], 2),
            'total_balance' => round($summary['totalBalance'], 2),
            'period' => [
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
                'income' => round($totals['income'], 2),
                'expenses' => round($totals['expenses'], 2),
                'net' => round($totals['net'], 2),
                'top_expense_categories' => $this->transactions->getCategorySpendingForPeriod($user, $period['start'], $period['end'], 5)
                    ->map(fn (array $row) => ['category' => $this->ref($row['category']), 'total' => round($row['total'], 2), 'count' => $row['count']])->all(),
            ],
            'overdue_payments' => $this->payments->getOverduePayments($user)->map(fn ($payment) => $this->payment($payment))->all(),
            'upcoming_payments_30_days' => $this->payments->getUpcomingPayments($user, now()->addDays(30))->map(fn ($payment) => $this->payment($payment))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
