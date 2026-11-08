<script setup lang="ts">
import { BalanceSummary } from '@/components/data-display';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Checkbox,
    EmptyState,
    Input,
    SearchableSelect,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { index as counterpartiesIndex } from '@/routes/counterparties';
import {
    destroy as destroySettlement,
    store as storeSettlement,
} from '@/routes/settlements';
import type { CounterpartyBalanceRow } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

interface Advance {
    id: number;
    date: string;
    amount: number;
    currency: string;
    description: string | null;
    settled: number;
    remaining: number;
}

interface Repayment {
    id: number;
    date: string;
    amount: number;
    currency: string;
    description: string | null;
    allocated: number;
    unallocated: number;
    /** The oldest unsettled advances first, as many as the income covers. */
    suggestion: { expense_id: number; amount: string }[];
}

interface Settlement {
    id: number;
    income_id: number;
    expense_id: number;
    amount: number;
}

interface OverSettled {
    transaction_id: number;
    side: 'expense' | 'income';
    currency: string;
    date: string;
    description: string | null;
    amount: number;
    settled: number;
    over: number;
    settlement_ids: number[];
}

interface Props {
    counterparty: { id: number; name: string };
    balances: CounterpartyBalanceRow[];
    advances: Advance[];
    repayments: Repayment[];
    settlements: Settlement[];
    over_settled: OverSettled[];
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

const openRepayments = computed(() =>
    props.repayments.filter((r) => r.unallocated > 0),
);

const repaymentOptions = computed(() =>
    openRepayments.value.map((r) => ({
        value: r.id.toString(),
        label: `${formatDate(r.date)} · ${formatCurrency(r.unallocated, r.currency)} left${r.description ? ` · ${r.description}` : ''}`,
    })),
);

const selectedIncomeId = ref('');
const picked = ref<Record<number, boolean>>({});
const amounts = ref<Record<number, string>>({});
const form = useForm({
    income_id: 0,
    allocations: [] as { expense_id: number; amount: string }[],
});

const selectedIncome = computed(
    () =>
        openRepayments.value.find(
            (r) => r.id.toString() === selectedIncomeId.value,
        ) ?? null,
);

const openAdvances = computed(() =>
    props.advances.filter(
        (a) => a.remaining > 0 && a.currency === selectedIncome.value?.currency,
    ),
);

const allocated = computed(() =>
    openAdvances.value.reduce(
        (sum, a) =>
            picked.value[a.id]
                ? sum + (parseFloat(amounts.value[a.id]) || 0)
                : sum,
        0,
    ),
);

const overAllocated = computed(
    () =>
        !!selectedIncome.value &&
        Math.round(allocated.value * 100) >
            Math.round(selectedIncome.value.unallocated * 100),
);

const canSettle = computed(
    () =>
        !!selectedIncome.value &&
        allocated.value > 0 &&
        !overAllocated.value &&
        !form.processing,
);

// The suggestion fills the amounts: oldest advances first, as much as the income covers.
function applySuggestion() {
    picked.value = {};
    amounts.value = {};

    for (const advance of openAdvances.value) {
        amounts.value[advance.id] = advance.remaining.toFixed(2);
    }

    for (const item of selectedIncome.value?.suggestion ?? []) {
        picked.value[item.expense_id] = true;
        amounts.value[item.expense_id] = item.amount;
    }
}

// Follows the data: after settling, the next income that still has money left is picked.
watch(
    openRepayments,
    (repayments) => {
        if (
            !repayments.some((r) => r.id.toString() === selectedIncomeId.value)
        ) {
            selectedIncomeId.value = repayments[0]?.id.toString() ?? '';
        }

        applySuggestion();
    },
    { immediate: true },
);

watch(selectedIncomeId, applySuggestion);

function settle() {
    if (!selectedIncome.value) {
        return;
    }

    form.income_id = selectedIncome.value.id;
    form.allocations = openAdvances.value
        .filter(
            (a) => picked.value[a.id] && parseFloat(amounts.value[a.id]) > 0,
        )
        .map((a) => ({ expense_id: a.id, amount: amounts.value[a.id] }));

    form.post(storeSettlement.url(), { preserveScroll: true });
}

function removeSettlement(settlement: Settlement) {
    router.delete(destroySettlement.url(settlement.id), {
        preserveScroll: true,
    });
}

const advanceById = computed(
    () => new Map(props.advances.map((a) => [a.id, a])),
);
const repaymentById = computed(
    () => new Map(props.repayments.map((r) => [r.id, r])),
);

const settlementById = computed(
    () => new Map(props.settlements.map((s) => [s.id, s])),
);

const formErrors = computed(() => Object.values(form.errors));
</script>

<template>
    <Head :title="counterparty.name" />

    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <Button
                :as="Link"
                :href="counterpartiesIndex().url"
                variant="ghost"
                size="icon"
            >
                <ArrowLeft class="size-4" />
            </Button>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ counterparty.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Advances, repayments and what is still open
                </p>
            </div>
        </div>

        <Card>
            <CardContent class="pt-6">
                <BalanceSummary
                    :name="counterparty.name"
                    :balances="balances"
                />
            </CardContent>
        </Card>

        <Card
            v-if="over_settled.length > 0"
            class="border-destructive"
            role="alert"
        >
            <CardHeader>
                <CardTitle class="text-destructive">Over-settled</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div
                    v-for="item in over_settled"
                    :key="`${item.side}-${item.transaction_id}`"
                    class="space-y-2"
                >
                    <p class="text-sm">
                        <span class="font-semibold tabular-nums">
                            Over-settled by
                            {{ formatCurrency(item.over, item.currency) }}:
                        </span>
                        the
                        {{ item.side === 'expense' ? 'advance' : 'repayment' }}
                        “{{ item.description || formatDate(item.date) }}” of
                        {{ formatCurrency(item.amount, item.currency) }} has
                        {{ formatCurrency(item.settled, item.currency) }}
                        {{ item.side === 'expense' ? 'settled' : 'allocated' }}.
                        Remove one of these settlements:
                    </p>
                    <div
                        v-for="id in item.settlement_ids"
                        :key="id"
                        class="flex items-center justify-between gap-3 rounded-xl p-3 hover:bg-muted/50"
                    >
                        <p class="min-w-0 text-sm tabular-nums">
                            {{
                                formatCurrency(
                                    settlementById.get(id)?.amount ?? 0,
                                    item.currency,
                                )
                            }}
                            from
                            {{
                                formatDate(
                                    repaymentById.get(
                                        settlementById.get(id)?.income_id ?? 0,
                                    )?.date ?? '',
                                )
                            }}
                            towards
                            {{
                                advanceById.get(
                                    settlementById.get(id)?.expense_id ?? 0,
                                )?.description || 'an advance'
                            }}
                        </p>
                        <Button
                            v-if="settlementById.has(id)"
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            @click="removeSettlement(settlementById.get(id)!)"
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Settle</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <p
                    v-if="openRepayments.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No money from {{ counterparty.name }} waiting to be matched.
                    Book an income from them (choose them in the transaction
                    form) and it shows up here.
                </p>

                <template v-else>
                    <div class="space-y-2">
                        <label
                            class="text-sm font-medium text-foreground"
                            for="settle_income"
                        >
                            Money received
                        </label>
                        <SearchableSelect
                            id="settle_income"
                            v-model="selectedIncomeId"
                            :options="repaymentOptions"
                            placeholder="Pick an income"
                            search-placeholder="Search..."
                        />
                    </div>

                    <p
                        v-if="openAdvances.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No open advances in
                        {{ selectedIncome?.currency }} to settle.
                    </p>

                    <div v-else class="space-y-2">
                        <div
                            v-for="advance in openAdvances"
                            :key="advance.id"
                            class="flex flex-col gap-2 rounded-xl border border-border p-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <Checkbox
                                    :id="`settle_${advance.id}`"
                                    v-model="picked[advance.id]"
                                />
                                <label
                                    :for="`settle_${advance.id}`"
                                    class="min-w-0 text-sm"
                                >
                                    <span class="block truncate font-medium">
                                        {{ advance.description || 'Advance' }}
                                    </span>
                                    <span
                                        class="block text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ formatDate(advance.date) }} ·
                                        {{
                                            formatCurrency(
                                                advance.remaining,
                                                advance.currency,
                                            )
                                        }}
                                        open of
                                        {{
                                            formatCurrency(
                                                advance.amount,
                                                advance.currency,
                                            )
                                        }}
                                    </span>
                                </label>
                            </div>
                            <Input
                                v-model="amounts[advance.id]"
                                type="number"
                                step="0.01"
                                min="0.01"
                                :max="advance.remaining"
                                :disabled="!picked[advance.id]"
                                :aria-label="`Amount to settle on ${advance.description || 'advance'}`"
                                class="w-full tabular-nums sm:w-32"
                            />
                        </div>

                        <p
                            class="text-sm tabular-nums"
                            :class="
                                overAllocated
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            Settling
                            {{
                                formatCurrency(
                                    allocated,
                                    selectedIncome?.currency,
                                )
                            }}
                            of
                            {{
                                formatCurrency(
                                    selectedIncome?.unallocated ?? 0,
                                    selectedIncome?.currency,
                                )
                            }}
                            received.
                        </p>
                        <p
                            v-for="message in formErrors"
                            :key="message"
                            class="text-sm text-destructive"
                        >
                            {{ message }}
                        </p>

                        <Button :disabled="!canSettle" @click="settle">
                            Settle
                        </Button>
                    </div>
                </template>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Advances</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div
                        v-for="advance in advances"
                        :key="advance.id"
                        class="flex items-center justify-between gap-3 rounded-xl p-3 hover:bg-muted/50"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ advance.description || 'Advance' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDate(advance.date) }}
                            </p>
                        </div>
                        <div class="text-right tabular-nums">
                            <p class="text-sm font-semibold">
                                {{
                                    formatCurrency(
                                        advance.amount,
                                        advance.currency,
                                    )
                                }}
                            </p>
                            <Badge
                                :variant="
                                    advance.remaining === 0
                                        ? 'success'
                                        : 'warning'
                                "
                            >
                                {{
                                    advance.remaining === 0
                                        ? 'Settled'
                                        : `${formatCurrency(advance.remaining, advance.currency)} open`
                                }}
                            </Badge>
                        </div>
                    </div>
                    <EmptyState
                        v-if="advances.length === 0"
                        title="No advances"
                        description="Mark an expense as paid for them in the transaction form"
                        class="py-8"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Repayments</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div
                        v-for="repayment in repayments"
                        :key="repayment.id"
                        class="flex items-center justify-between gap-3 rounded-xl p-3 hover:bg-muted/50"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ repayment.description || 'Payment' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDate(repayment.date) }}
                            </p>
                        </div>
                        <div class="text-right tabular-nums">
                            <p class="text-sm font-semibold">
                                {{
                                    formatCurrency(
                                        repayment.amount,
                                        repayment.currency,
                                    )
                                }}
                            </p>
                            <Badge
                                :variant="
                                    repayment.unallocated === 0
                                        ? 'success'
                                        : 'warning'
                                "
                            >
                                {{
                                    repayment.unallocated === 0
                                        ? 'Matched'
                                        : `${formatCurrency(repayment.unallocated, repayment.currency)} unmatched`
                                }}
                            </Badge>
                        </div>
                    </div>
                    <EmptyState
                        v-if="repayments.length === 0"
                        title="No repayments"
                        description="Incomes from them appear here"
                        class="py-8"
                    />
                </CardContent>
            </Card>
        </div>

        <Card v-if="settlements.length > 0">
            <CardHeader>
                <CardTitle>Settlements</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2">
                <div
                    v-for="settlement in settlements"
                    :key="settlement.id"
                    class="flex items-center justify-between gap-3 rounded-xl p-3 hover:bg-muted/50"
                >
                    <p class="min-w-0 text-sm">
                        <span class="font-semibold tabular-nums">
                            {{
                                formatCurrency(
                                    settlement.amount,
                                    advanceById.get(settlement.expense_id)
                                        ?.currency,
                                )
                            }}
                        </span>
                        from
                        {{
                            formatDate(
                                repaymentById.get(settlement.income_id)?.date ??
                                    '',
                            )
                        }}
                        towards
                        {{
                            advanceById.get(settlement.expense_id)
                                ?.description || 'an advance'
                        }}
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="removeSettlement(settlement)"
                    >
                        Remove
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
