<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    ModalFooter,
    SearchableSelect,
    Switch,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { intervalLabel } from '@/lib/utils';
import type { Account, Category, RecurringPayment } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { computed, useId } from 'vue';

interface Props {
    recurringPayment?: RecurringPayment | null;
    accounts: Account[];
    categories: Category[];
    action: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    success: [];
    cancel: [];
}>();

type Repeat = 'once' | 'repeat';
type Ends = 'never' | 'date' | 'count';

const { toInputFormat, formatDate } = useDate();
const { formatCurrency } = useCurrency();
const formId = useId();

const rule = props.recurringPayment;
const isEditing = computed(() => !!rule);

const form = useForm({
    name: rule?.name ?? '',
    account_id: rule?.account_id?.toString() ?? '',
    category_id: rule?.category_id?.toString() ?? '',
    amount: rule?.amount ?? '',
    repeat: (rule?.installments === 1 ? 'once' : 'repeat') as Repeat,
    interval_value: rule?.interval_value?.toString() ?? '1',
    interval_type: rule?.interval_type ?? 'months',
    start_date: toInputFormat(rule?.start_date ?? new Date()),
    ends: (rule?.installments && rule.installments > 1
        ? 'count'
        : rule?.end_date
          ? 'date'
          : 'never') as Ends,
    end_date: rule?.end_date ? toInputFormat(rule.end_date) : '',
    installments:
        rule?.installments && rule.installments > 1
            ? rule.installments.toString()
            : '',
    is_active: rule?.is_active ?? true,
});

const accountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})`,
    })),
);

const categoryOptions = computed(() =>
    props.categories.map((c) => ({
        value: c.id.toString(),
        label: c.type === 'income' ? `${c.name} (income)` : c.name,
    })),
);

const intervalOptions = [
    { value: 'days', label: 'Days' },
    { value: 'weeks', label: 'Weeks' },
    { value: 'months', label: 'Months' },
    { value: 'years', label: 'Years' },
];

const endOptions: Array<{ value: Ends; label: string }> = [
    { value: 'never', label: 'Never' },
    { value: 'date', label: 'On date' },
    { value: 'count', label: 'After N payments' },
];

const currency = computed(
    () =>
        props.accounts.find((a) => a.id.toString() === form.account_id)
            ?.currency ?? 'EUR',
);

const isOnce = computed(() => form.repeat === 'once');

/**
 * Occurrence n: always counted from the start date, month ends clamp
 * (31 Jan + 1 month = 28 Feb), exactly like the server does.
 */
function dateFor(n: number): Date {
    const [year, month, day] = form.start_date.split('-').map(Number);
    const steps = n * (parseInt(form.interval_value) || 1);

    if (form.interval_type === 'days' || form.interval_type === 'weeks') {
        const days = form.interval_type === 'weeks' ? steps * 7 : steps;

        return new Date(year, month - 1, day + days);
    }

    const total =
        month - 1 + (form.interval_type === 'years' ? steps * 12 : steps);
    const targetYear = year + Math.floor(total / 12);
    const targetMonth = ((total % 12) + 12) % 12;
    const lastDay = new Date(targetYear, targetMonth + 1, 0).getDate();

    return new Date(targetYear, targetMonth, Math.min(day, lastDay));
}

const preview = computed(() => {
    if (!form.start_date) return null;

    const amount = parseFloat(form.amount) || 0;
    const money = formatCurrency(amount, currency.value);

    if (isOnce.value) {
        return `1 × ${money} · on ${formatDate(dateFor(0))}`;
    }

    if (form.ends === 'never') {
        return `${money} ${intervalLabel(parseInt(form.interval_value) || 1, form.interval_type).toLowerCase()}, until you stop it`;
    }

    let count = 0;

    if (form.ends === 'count') {
        count = parseInt(form.installments) || 0;
    } else if (form.end_date) {
        while (count < 600 && toInputFormat(dateFor(count)) <= form.end_date) {
            count++;
        }
    }

    return count > 0
        ? `${count} × ${money} · last on ${formatDate(dateFor(count - 1))}`
        : null;
});

function submit() {
    form.transform((data) => {
        const once = data.repeat === 'once';

        return {
            name: data.name,
            account_id: data.account_id,
            category_id: data.category_id,
            amount: data.amount,
            interval_value: once ? 1 : data.interval_value,
            interval_type: once ? 'months' : data.interval_type,
            start_date: data.start_date,
            end_date: !once && data.ends === 'date' ? data.end_date : null,
            installments: once
                ? 1
                : data.ends === 'count'
                  ? data.installments
                  : null,
            is_active: data.is_active,
        };
    })[isEditing.value ? 'put' : 'post'](props.action, {
        preserveScroll: true,
        onSuccess: () => emit('success'),
    });
}
</script>

<template>
    <form :id="formId" class="space-y-6" @submit.prevent="submit">
        <div class="space-y-4">
            <div class="space-y-2">
                <Label for="name" required>Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    placeholder="e.g., Netflix, Rent, Insurance"
                    required
                />
                <p v-if="form.errors.name" class="text-sm text-destructive">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="account_id" required>Account</Label>
                    <SearchableSelect
                        id="account_id"
                        v-model="form.account_id"
                        placeholder="Select account"
                        search-placeholder="Search account..."
                        :options="accountOptions"
                        required
                    />
                    <p
                        v-if="form.errors.account_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.account_id }}
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="category_id" required>Category</Label>
                    <SearchableSelect
                        id="category_id"
                        v-model="form.category_id"
                        placeholder="Select category"
                        search-placeholder="Search category..."
                        :options="categoryOptions"
                        required
                    />
                    <p
                        v-if="form.errors.category_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.category_id }}
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <Label for="amount" required>Amount</Label>
                <div class="relative">
                    <Input
                        id="amount"
                        v-model="form.amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        placeholder="0.00"
                        class="pr-16"
                        required
                    />
                    <span
                        class="absolute top-1/2 right-4 -translate-y-1/2 text-sm text-muted-foreground"
                    >
                        {{ currency }}
                    </span>
                </div>
                <p v-if="form.errors.amount" class="text-sm text-destructive">
                    {{ form.errors.amount }}
                </p>
            </div>

            <div class="space-y-2">
                <Label required>Repeat</Label>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="option in [
                            { value: 'once', label: 'Once' },
                            { value: 'repeat', label: 'Repeats' },
                        ] as const"
                        :key="option.value"
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-4 py-2.5 text-sm font-medium transition-all',
                            form.repeat === option.value
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border hover:border-primary/40',
                        ]"
                        @click="form.repeat = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div v-if="!isOnce" class="space-y-2">
                <Label required>Every</Label>
                <div class="grid grid-cols-[1fr_2fr] gap-2">
                    <Input
                        v-model="form.interval_value"
                        type="number"
                        min="1"
                        placeholder="1"
                        aria-label="Interval"
                        required
                    />
                    <SearchableSelect
                        v-model="form.interval_type"
                        placeholder="Select interval"
                        search-placeholder="Search..."
                        aria-label="Interval unit"
                        :options="intervalOptions"
                        required
                    />
                </div>
                <p
                    v-if="form.errors.interval_value"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.interval_value }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="start_date" required>
                    {{ isOnce ? 'Date' : 'Starting' }}
                </Label>
                <Input
                    id="start_date"
                    v-model="form.start_date"
                    type="date"
                    required
                />
                <p
                    v-if="form.errors.start_date"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.start_date }}
                </p>
            </div>

            <div v-if="!isOnce" class="space-y-2">
                <Label required>Ends</Label>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="option in endOptions"
                        :key="option.value"
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-2 py-2.5 text-xs font-medium transition-all sm:text-sm',
                            form.ends === option.value
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border hover:border-primary/40',
                        ]"
                        @click="form.ends = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <Input
                    v-if="form.ends === 'date'"
                    v-model="form.end_date"
                    type="date"
                    aria-label="End date"
                    required
                />
                <Input
                    v-else-if="form.ends === 'count'"
                    v-model="form.installments"
                    type="number"
                    min="2"
                    placeholder="Number of payments"
                    aria-label="Number of payments"
                    required
                />
                <p v-if="form.errors.end_date" class="text-sm text-destructive">
                    {{ form.errors.end_date }}
                </p>
                <p
                    v-if="form.errors.installments"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.installments }}
                </p>
            </div>

            <p
                v-if="preview"
                class="rounded-xl bg-muted/50 px-4 py-3 text-sm font-medium text-foreground"
            >
                {{ preview }}
            </p>

            <div
                class="flex items-center justify-between rounded-xl border border-border bg-muted/30 p-4"
            >
                <div>
                    <p class="font-medium text-foreground">Active</p>
                    <p class="text-sm text-muted-foreground">
                        Turn off to pause. Unpaid future payments are removed
                        until you turn it on again.
                    </p>
                </div>
                <Switch v-model="form.is_active" />
            </div>

            <p v-if="isEditing" class="text-sm text-muted-foreground">
                Changes apply to unpaid payments from today on; payments you
                edited individually keep their amount.
            </p>
        </div>

        <ModalFooter>
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit" :form="formId" :disabled="form.processing">
                {{ isEditing ? 'Save changes' : 'Add planned payment' }}
            </Button>
        </ModalFooter>
    </form>
</template>
