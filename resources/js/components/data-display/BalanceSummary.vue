<script setup lang="ts">
import { useCurrency } from '@/composables';
import type { CounterpartyBalanceRow } from '@/types';

interface Props {
    name: string;
    balances: CounterpartyBalanceRow[];
}

defineProps<Props>();

const { formatCurrency } = useCurrency();
</script>

<template>
    <div class="space-y-3">
        <p v-if="balances.length === 0" class="text-sm text-muted-foreground">
            Nothing advanced or received yet.
        </p>

        <div v-for="row in balances" :key="row.currency" class="space-y-0.5">
            <p
                v-if="row.balance > 0"
                class="text-base font-semibold text-amber-600 dark:text-amber-400"
            >
                {{ name }} owes you
                <span class="tabular-nums">{{
                    formatCurrency(row.balance, row.currency)
                }}</span>
            </p>
            <p
                v-else-if="row.balance < 0"
                class="text-base font-semibold text-sky-600 dark:text-sky-400"
            >
                They overpaid: you owe {{ name }}
                <span class="tabular-nums">{{
                    formatCurrency(Math.abs(row.balance), row.currency)
                }}</span>
            </p>
            <p
                v-else
                class="text-base font-semibold text-emerald-600 dark:text-emerald-400"
            >
                All square in {{ row.currency }}
            </p>
            <p class="text-xs text-muted-foreground tabular-nums">
                Advanced {{ formatCurrency(row.advanced, row.currency) }},
                settled {{ formatCurrency(row.settled, row.currency) }}, still
                open {{ formatCurrency(row.outstanding, row.currency) }}. Paid
                back {{ formatCurrency(row.received, row.currency) }}, of which
                {{ formatCurrency(row.unallocated, row.currency) }} is not
                matched to an advance.
            </p>
        </div>
    </div>
</template>
