<?php

namespace App\Http\Requests;

use App\Enums\IntervalType;
use App\Models\RecurringPayment;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one set of rules. A planned payment ends never, on a
 * date, or after a number of installments (once = 1); currency is derived.
 */
class RecurringPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('recurring_payment');

        return $rule
            ? $this->user()->can('update', $rule)
            : $this->user()->can('create', RecurringPayment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor($this->user(), $this->route('recurring_payment'));
    }

    /**
     * Also used by the MCP tools. An edited rule may keep its category even if it was archived since.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(User $user, ?RecurringPayment $rule = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', $user->ownedAccountRule()],
            'category_id' => ['required', 'integer', $user->ownedCategoryRule($rule?->category_id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'interval_type' => ['required', Rule::enum(IntervalType::class)],
            'interval_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:2100-12-31'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:2100-12-31', 'prohibits:installments'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:600', self::endsBefore2101()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.prohibits' => 'Choose either an end date or a number of payments, not both.',
        ];
    }

    /**
     * The last installment must still fall in this century (interval x installments can run away).
     */
    private static function endsBefore2101(): ValidationRule
    {
        return new class implements DataAwareRule, ValidationRule
        {
            /** @var array<string, mixed> */
            private array $data = [];

            /**
             * @param  array<string, mixed>  $data
             */
            public function setData(array $data): static
            {
                $this->data = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $interval = IntervalType::tryFrom((string) ($this->data['interval_type'] ?? ''));
                $start = $this->data['start_date'] ?? null;

                if ($interval === null || ! is_string($start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! is_numeric($this->data['interval_value'] ?? null)) {
                    return;
                }

                $rule = (new RecurringPayment)->forceFill([
                    'interval_type' => $interval,
                    'interval_value' => (int) $this->data['interval_value'],
                    'start_date' => $start,
                ]);

                if ($rule->dateFor((int) $value - 1)->year > 2100) {
                    $fail('The last payment would fall after 2100.');
                }
            }
        };
    }
}
