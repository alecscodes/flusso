<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Flusso')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Flusso is the owner's personal finance app: accounts, income/expense/transfer transactions, and planned payments (a rule that produces dated payments).

- The primary currency is EUR; accounts are in EUR or RON. An amount is always in the currency of the account it is booked on. Never convert or "correct" amounts: keep them exactly as the user says (they round up on purpose, e.g. 14 instead of 13.70).
- Dates are YYYY-MM-DD. Default to today (see get_overview, Europe/Rome time) when the user gives no date.
- Before writing, resolve accounts and categories by calling list_accounts / list_categories and match names case-insensitively; ask the user when more than one fits. Create a new category only when nothing fits.
- Moving money between two of the user's own accounts is a transfer (type "transfer" with account_id and to_account_id), not an expense plus an income. For a transfer between different currencies, pass to_amount (what arrived) when the user knows it; otherwise today's rate is used.
- A planned payment is a rule ("once" or every N days/weeks/months/years). Its payments are the occurrences: use list_payments to see what is overdue or coming up, mark_payment_paid to pay one (optionally a different amount, date or account) and skip_payment to skip one.
- Ask for confirmation before deleting or archiving anything.
- Answer in the language the user writes in.
TEXT)]
class FlussoServer extends Server
{
    /** All tools in one page: not every client follows the pagination cursor. */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        Tools\GetOverview::class,
        Tools\ListAccounts::class,
        Tools\CreateAccount::class,
        Tools\UpdateAccount::class,
        Tools\ArchiveAccount::class,
        Tools\ListCategories::class,
        Tools\CreateCategory::class,
        Tools\UpdateCategory::class,
        Tools\DeleteCategory::class,
        Tools\SearchTransactions::class,
        Tools\CreateTransaction::class,
        Tools\UpdateTransaction::class,
        Tools\DeleteTransaction::class,
        Tools\ListPlannedPayments::class,
        Tools\GetPlannedPayment::class,
        Tools\CreatePlannedPayment::class,
        Tools\UpdatePlannedPayment::class,
        Tools\PausePlannedPayment::class,
        Tools\ResumePlannedPayment::class,
        Tools\DeletePlannedPayment::class,
        Tools\ListPayments::class,
        Tools\UpdatePayment::class,
        Tools\SkipPayment::class,
        Tools\UnskipPayment::class,
        Tools\MarkPaymentPaid::class,
        Tools\MarkPaymentUnpaid::class,
    ];
}
