<?php

namespace App\Mcp\Tools;

use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared plumbing of the Flusso tools: the signed-in user, validation, errors
 * and the compact JSON shapes returned to Claude. Tools never contain business
 * logic; they call the same services and FormRequest rules as the web app.
 */
abstract class FlussoTool extends Tool
{
    protected function user(Request $request): User
    {
        return $request->user() instanceof User ? $request->user() : throw new AuthenticationException;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validated(array $input, array $rules): array
    {
        return Validator::validate($input, $rules);
    }

    /**
     * The tool arguments that were actually passed (an explicit null counts).
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function passed(Request $request, array $keys): array
    {
        return Arr::only($request->all(), $keys);
    }

    protected function notFound(string $what): Response
    {
        return Response::error("{$what} not found.");
    }

    /**
     * The record whose id was passed, taken from the user's own records, or the not-found error.
     *
     * @template TModel of Model
     *
     * @param  Relation<TModel, *, *>  $own
     * @return TModel|Response
     */
    protected function owned(Request $request, Relation $own, string $what): Model|Response
    {
        return $own->find($request->integer('id')) ?? $this->notFound($what);
    }

    protected function json(mixed $data): Response
    {
        return Response::json($data);
    }

    /**
     * Current balances of the given accounts, so Claude can confirm the effect of a write.
     *
     * @param  array<int, int|null>  $accountIds
     * @return list<array<string, mixed>>
     */
    protected function balances(User $user, array $accountIds): array
    {
        return $user->accounts()->withTrashed()->whereKey(array_filter($accountIds))->orderBy('name')->get()
            ->map(fn (Account $account) => $this->account($account))->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function account(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'currency' => $account->currency,
            'balance' => (float) $account->balance,
            'is_savings' => $account->is_savings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function category(Category $category): array
    {
        return array_filter([
            'id' => $category->id,
            'name' => $category->name,
            'type' => $category->type->value,
            'icon' => $category->icon,
            'color' => $category->color,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function transaction(Transaction $transaction): array
    {
        return array_filter([
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'date' => $transaction->date->toDateString(),
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'account' => $this->ref($transaction->account),
            'category' => $this->ref($transaction->category),
            'to_account' => $this->ref($transaction->toAccount),
            'to_amount' => $transaction->to_amount === null ? null : (float) $transaction->to_amount,
            'to_currency' => $transaction->toAccount?->currency,
            'description' => $transaction->description,
        ], fn ($value) => $value !== null);
    }

    /**
     * One occurrence of a planned payment: pending (overdue when past due), paid or skipped.
     *
     * @return array<string, mixed>
     */
    protected function payment(Payment $payment): array
    {
        $status = match (true) {
            $payment->isPaid() => 'paid',
            $payment->skipped_at !== null => 'skipped',
            $payment->due_date->toDateString() < now()->toDateString() => 'overdue',
            default => 'pending',
        };

        return array_filter([
            'id' => $payment->id,
            'planned_payment_id' => $payment->recurring_payment_id,
            'name' => $payment->description,
            'occurrence' => $payment->occurrence,
            'due_date' => $payment->due_date->toDateString(),
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'type' => $payment->type->value,
            'status' => $status,
            'account' => $this->ref($payment->account),
            'category' => $this->ref($payment->category),
            'transaction_id' => $payment->transaction_id,
            'edited_by_hand' => $payment->is_overridden ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * A planned payment (rule). Needs `account`, `category` and `payments` loaded.
     *
     * @return array<string, mixed>
     */
    protected function plannedPayment(RecurringPayment $rule): array
    {
        return array_filter([
            'id' => $rule->id,
            'name' => $rule->name,
            'status' => $rule->status,
            'amount' => (float) $rule->amount,
            'currency' => $rule->currency,
            'account' => $this->ref($rule->account),
            'category' => $this->ref($rule->category),
            'repeat' => $rule->installments === 1 ? 'once' : "every {$rule->interval_value} {$rule->interval_type->value}",
            'start_date' => $rule->start_date->toDateString(),
            'end_date' => $rule->end_date?->toDateString(),
            'installments' => $rule->installments,
            'next_due_date' => $rule->payments->filter->isPending()->min(fn (Payment $payment) => $payment->due_date->toDateString()),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function ref(Account|Category|null $model): ?array
    {
        return $model === null ? null : ['id' => $model->getKey(), 'name' => $model->name];
    }
}
