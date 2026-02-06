<script setup lang="ts">
import { useCurrency } from '@/composables';
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    amount: number | string;
    currency?: string;
    type?: 'income' | 'expense' | 'transfer' | 'neutral';
    size?: 'sm' | 'md' | 'lg' | 'xl';
    showSign?: boolean;
    compact?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    currency: 'EUR',
    type: 'neutral',
    size: 'md',
    showSign: true,
    compact: false,
});

const { formatCurrency, formatCompact } = useCurrency();

const numericAmount = computed(() => {
    return typeof props.amount === 'string'
        ? parseFloat(props.amount)
        : props.amount;
});

const formattedAmount = computed(() => {
    const value = props.compact
        ? formatCompact(numericAmount.value, props.currency)
        : formatCurrency(numericAmount.value, props.currency);

    if (
        props.showSign &&
        props.type !== 'neutral' &&
        props.type !== 'transfer'
    ) {
        return props.type === 'income' ? `+${value}` : `-${value}`;
    }
    return value;
});

const sizeClasses = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-xl font-semibold',
    xl: 'text-3xl font-bold',
};

const typeColors = {
    income: 'text-emerald-600 dark:text-emerald-400',
    expense: 'text-rose-600 dark:text-rose-400',
    transfer: 'text-blue-600 dark:text-blue-400',
    neutral: 'text-foreground',
};

const classes = computed(() =>
    cn(
        sizeClasses[props.size],
        typeColors[props.type],
        'tabular-nums',
        props.class,
    ),
);
</script>

<template>
    <span :class="classes">{{ formattedAmount }}</span>
</template>
