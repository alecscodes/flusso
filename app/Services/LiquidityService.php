<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\LiquidityAlertState;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\LiquidityAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Will an account run dry? Deterministic: every figure is plain arithmetic on the ledger
 * balance and the payments still owed, never a model's guess. Savings accounts are left out
 * (they are not available money), and so are paid, skipped, paused and archived payments.
 *
 * @phpstan-type PaymentLine array{id: int, name: string, due_date: string, amount: float, currency: string, overdue: bool, is_estimate: bool, rate_missing: bool}
 * @phpstan-type AccountForecast array{account_id: int, account_name: string, currency: string, balance: float, balance_after: float, alert_threshold: float|null, below_threshold: bool, due_total: float, shortfall: float, needed_by: string|null, negative_on: string|null, threshold_crossed_on: string|null, is_at_risk: bool, is_estimate: bool, has_unconverted_payments: bool, days: int, payments: list<PaymentLine>}
 */
class LiquidityService
{
    public const DEFAULT_DAYS = 30;

    public const NOTIFICATION_DAYS = 7;

    public const KIND_THRESHOLD = 'threshold';

    public const KIND_SHORTFALL = 'shortfall';

    public const KIND_UNCONVERTED = 'unconverted';

    public function __construct(private CurrencyService $currencyService) {}

    /**
     * Every non-savings account with what it owes in the next days (overdue included),
     * in the account's own currency. Only expenses count; payments are applied in due order,
     * overdue ones as if due today.
     *
     * @return Collection<int, AccountForecast>
     */
    public function forecast(User $user, int $days = self::DEFAULT_DAYS): Collection
    {
        $today = now()->toDateString();

        $accounts = $user->accounts()->where('is_savings', false)->orderBy('name')->get();

        $payments = $user->payments()->visible()->pending()
            ->where('type', PaymentType::Expense->value)
            ->whereIn('account_id', $accounts->modelKeys())
            ->where('due_date', '<=', now()->addDays($days)->toDateString())
            ->with('recurringPayment')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->groupBy('account_id');

        return $accounts
            ->map(fn (Account $account) => $this->forecastAccount($account, $payments->get($account->id, new Collection), $today, $days))
            ->values();
    }

    /**
     * Only the accounts that are short or cross their threshold.
     *
     * @return Collection<int, AccountForecast>
     */
    public function atRisk(User $user, int $days = self::DEFAULT_DAYS): Collection
    {
        $atRisk = array_values(array_filter(
            $this->forecast($user, $days)->all(),
            fn (array $row): bool => $row['is_at_risk'],
        ));

        return new Collection($atRisk);
    }

    /**
     * One plain sentence, e.g. "Enzfox: 9.74 RON, you need 229 RON by 2 Dec, you are short 219.26 RON".
     *
     * @param  AccountForecast  $row
     */
    public function describe(array $row): string
    {
        $currency = $row['currency'];
        $money = fn (float $amount): string => $this->formatAmount($amount).' '.$currency;
        $head = "{$row['account_name']}: {$money($row['balance'])}";
        $neededBy = $row['needed_by'] === null ? '' : Carbon::parse($row['needed_by'])->format('j M');

        $sentence = match (true) {
            $row['due_total'] > 0 && $row['shortfall'] > 0 => "{$head}, you need {$money($row['due_total'])} by {$neededBy}, you are short {$money($row['shortfall'])}",
            $row['due_total'] > 0 && $row['alert_threshold'] !== null => "{$head}, after {$money($row['due_total'])} due by {$neededBy} it drops to {$money($row['balance_after'])}, at or below your {$money($row['alert_threshold'])} alert threshold",
            $row['balance'] < 0 => "{$head}, the account is overdrawn",
            $row['alert_threshold'] !== null => "{$head}, at or below your {$money($row['alert_threshold'])} alert threshold",
            default => $head,
        };

        if ($row['is_estimate']) {
            $sentence .= ' (estimate)';
        }

        $unconverted = $this->describeUnconverted($row);

        return $unconverted === null ? $sentence : "{$sentence}. {$unconverted}";
    }

    /**
     * "1 payment in EUR could not be converted: add a rate or check it", or null when every payment was converted.
     *
     * @param  AccountForecast  $row
     */
    private function describeUnconverted(array $row): ?string
    {
        $counts = [];

        foreach ($row['payments'] as $line) {
            if ($line['rate_missing']) {
                $counts[$line['currency']] = ($counts[$line['currency']] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $parts = [];

        foreach ($counts as $currency => $count) {
            $parts[] = $count.' '.Str::plural('payment', $count)." in {$currency}";
        }

        return implode(', ', $parts).' could not be converted: add a rate or check it';
    }

    /**
     * Raise the alerts for one user: at most one database notification per (account, kind)
     * until its state changes. A condition that clears is re-armed, so it can alert again.
     *
     * @return int The number of notifications sent.
     */
    public function notifyUser(User $user): int
    {
        $states = LiquidityAlertState::query()
            ->whereIn('account_id', $user->accounts()->withTrashed()->select('id')->toBase())
            ->get()
            ->keyBy(fn (LiquidityAlertState $state) => "{$state->account_id}:{$state->kind}");
        $active = [];
        $sent = 0;

        foreach ($this->forecast($user, self::NOTIFICATION_DAYS) as $row) {
            foreach ($this->activeKinds($row) as $kind => $signature) {
                $key = "{$row['account_id']}:{$kind}";
                $active[$key] = true;
                $state = $states->get($key);

                if ($state?->signature === $signature) {
                    continue;
                }

                DB::transaction(function () use ($user, $row, $kind, $signature) {
                    $user->notify(new LiquidityAlert($row, $kind, $this->describe($row)));
                    LiquidityAlertState::updateOrCreate(
                        ['account_id' => $row['account_id'], 'kind' => $kind],
                        ['signature' => $signature, 'notified_at' => now()],
                    );
                });
                $sent++;
            }
        }

        $cleared = $states->reject(fn (LiquidityAlertState $state, string $key) => isset($active[$key]));

        if ($cleared->isNotEmpty()) {
            LiquidityAlertState::query()->whereKey($cleared->modelKeys())->delete();
        }

        return $sent;
    }

    /**
     * The conditions that hold for an account, each with a signature that changes when its state does.
     *
     * @param  AccountForecast  $row
     * @return array<string, string>
     */
    private function activeKinds(array $row): array
    {
        $kinds = [];

        if ($row['threshold_crossed_on'] !== null) {
            $kinds[self::KIND_THRESHOLD] = $this->cents($row['alert_threshold'] ?? 0).':'.($row['below_threshold'] ? 'now' : $row['threshold_crossed_on']);
        }

        if ($row['shortfall'] > 0) {
            $kinds[self::KIND_SHORTFALL] = $this->cents($row['shortfall']).':'.($row['needed_by'] ?? 'now');
        }

        $unconvertedIds = array_column(array_filter($row['payments'], fn (array $line): bool => $line['rate_missing']), 'id');

        if ($unconvertedIds !== []) {
            $kinds[self::KIND_UNCONVERTED] = md5(implode(',', $unconvertedIds));
        }

        return $kinds;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return AccountForecast
     */
    private function forecastAccount(Account $account, Collection $payments, string $today, int $days): array
    {
        $balance = $this->cents($account->balance);
        $threshold = $account->alert_threshold === null ? null : $this->cents($account->alert_threshold);

        $running = $balance;
        $dueTotal = 0;
        $isEstimate = false;
        $hasUnconverted = false;
        $negativeOn = $balance < 0 ? $today : null;
        $thresholdOn = $threshold !== null && $balance <= $threshold ? $today : null;
        $neededBy = null;
        $lines = [];

        foreach ($payments as $payment) {
            [$amount, $estimated] = $this->inAccountCurrency($payment, $account);
            $dueDate = $payment->due_date->toDateString();
            $effectiveDate = max($dueDate, $today);

            if ($amount === null) {
                $hasUnconverted = true;
                $lines[] = [
                    'id' => $payment->id,
                    'name' => $payment->recurringPayment->name,
                    'due_date' => $dueDate,
                    'amount' => $this->money($this->cents($payment->amount)),
                    'currency' => (string) $payment->currency,
                    'overdue' => $dueDate < $today,
                    'is_estimate' => false,
                    'rate_missing' => true,
                ];

                continue;
            }

            $running -= $amount;
            $dueTotal += $amount;
            $isEstimate = $isEstimate || $estimated;
            $neededBy = $effectiveDate;

            if ($negativeOn === null && $running < 0) {
                $negativeOn = $effectiveDate;
            }

            if ($threshold !== null && $thresholdOn === null && $running <= $threshold) {
                $thresholdOn = $effectiveDate;
            }

            $lines[] = [
                'id' => $payment->id,
                'name' => $payment->recurringPayment->name,
                'due_date' => $dueDate,
                'amount' => $this->money($amount),
                'currency' => $account->currency,
                'overdue' => $dueDate < $today,
                'is_estimate' => $estimated,
                'rate_missing' => false,
            ];
        }

        $shortfall = max(0, $dueTotal - $balance);

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'currency' => $account->currency,
            'balance' => $this->money($balance),
            'balance_after' => $this->money($running),
            'alert_threshold' => $threshold === null ? null : $this->money($threshold),
            'below_threshold' => $threshold !== null && $balance <= $threshold,
            'due_total' => $this->money($dueTotal),
            'shortfall' => $this->money($shortfall),
            'needed_by' => $neededBy,
            'negative_on' => $negativeOn,
            'threshold_crossed_on' => $thresholdOn,
            'is_at_risk' => $shortfall > 0 || $thresholdOn !== null || $hasUnconverted,
            'is_estimate' => $isEstimate,
            'has_unconverted_payments' => $hasUnconverted,
            'days' => $days,
            'payments' => $lines,
        ];
    }

    /**
     * A payment is normally in its account's currency already; when it is not, the
     * app's conversion path is used and the result is an estimate. Without a rate the
     * amount is null: it is never counted 1:1, which would understate what is owed.
     *
     * @return array{0: int|null, 1: bool} Cents in the account currency (null when it cannot be converted), and whether it is an estimate.
     */
    private function inAccountCurrency(Payment $payment, Account $account): array
    {
        $amount = (float) $payment->amount;

        if (blank($payment->currency) || $payment->currency === $account->currency) {
            return [$this->cents($amount), false];
        }

        $converted = $this->currencyService->convert($amount, $payment->currency, $account->currency);

        return [$converted === null ? null : $this->cents($converted), $converted !== null];
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function cents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function formatAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');

        return str_ends_with($formatted, '.00') ? substr($formatted, 0, -3) : $formatted;
    }
}
