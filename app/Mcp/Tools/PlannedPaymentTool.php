<?php

namespace App\Mcp\Tools;

use App\Http\Requests\RecurringPaymentRequest;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Services\RecurringPaymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;

/**
 * Create/update/pause/resume of a planned payment share how tool arguments become
 * the form input of {@see RecurringPaymentRequest}: "once" is a single installment,
 * and an end date and an installment count exclude each other.
 */
abstract class PlannedPaymentTool extends FlussoTool
{
    public function __construct(protected RecurringPaymentService $plannedPayments) {}

    /**
     * @return array<string, mixed>
     */
    protected function currentInput(RecurringPayment $rule): array
    {
        return [
            'name' => $rule->name,
            'account_id' => $rule->account_id,
            'category_id' => $rule->category_id,
            'amount' => $rule->amount,
            'interval_type' => $rule->interval_type->value,
            'interval_value' => $rule->interval_value,
            'start_date' => $rule->start_date->toDateString(),
            'end_date' => $rule->end_date?->toDateString(),
            'installments' => $rule->installments,
            'is_active' => $rule->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function mergeInput(array $current, array $args): array
    {
        $input = [...$current, ...Arr::only($args, ['name', 'account_id', 'category_id', 'amount', 'start_date', 'end_date', 'installments'])];

        if (isset($args['repeat'])) {
            if ($args['repeat'] === 'once') {
                $input = [...$input, 'interval_type' => 'months', 'interval_value' => 1, 'installments' => 1, 'end_date' => null];
            } else {
                $input['interval_type'] = $args['repeat'];

                // Turning a one-time payment into a repeating one: it no longer ends after one.
                if (($current['installments'] ?? null) === 1 && ! isset($args['installments'], $args['end_date'])) {
                    $input['installments'] = null;
                }
            }
        }

        if (isset($args['every'])) {
            $input['interval_value'] = $args['every'];
        }

        if (isset($args['installments']) && ! isset($args['end_date'])) {
            $input['end_date'] = null;
        } elseif (isset($args['end_date']) && ! isset($args['installments'])) {
            $input['installments'] = null;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validatedInput(User $user, array $input): array
    {
        return $this->validated($input, RecurringPaymentRequest::rulesFor($user));
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(RecurringPayment $rule): array
    {
        return $this->plannedPayment($rule->load(['account', 'category', 'payments']));
    }

    /**
     * @return array<string, Type>
     */
    protected function fields(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('What it is, e.g. "Rent".'),
            'account_id' => $schema->integer()->description('The account it is paid from (or received on).'),
            'category_id' => $schema->integer()->description('Its category; an income category makes it an expected income.'),
            'amount' => $schema->number()->min(0.01)->description('Per payment, in the account\'s currency.'),
            'repeat' => $schema->string()->enum(['once', 'days', 'weeks', 'months', 'years'])->description('"once" or the unit of the repetition.'),
            'every' => $schema->integer()->min(1)->description('Repeat every N units (default 1), e.g. every 3 months.'),
            'start_date' => $schema->string()->description('YYYY-MM-DD of the first payment.'),
            'end_date' => $schema->string()->nullable()->description('YYYY-MM-DD of the last possible payment. Exclusive with installments.'),
            'installments' => $schema->integer()->min(1)->nullable()->description('Total number of payments. Exclusive with end_date. Neither = repeats until stopped.'),
        ];
    }
}
