<script setup lang="ts">
import { Dropdown, DropdownItem } from '@/components/ui';
import { useCurrency } from '@/composables';
import { cn } from '@/lib/utils';
import type { Account } from '@/types';
import {
    CreditCard,
    MoreHorizontal,
    Pencil,
    Trash2,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    account: Account;
    showActions?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    showActions: true,
});

const emit = defineEmits<{
    edit: [account: Account];
    delete: [account: Account];
    click: [account: Account];
}>();

const { formatCurrency } = useCurrency();

const balance = computed(() => parseFloat(props.account.balance));
const initialBalance = computed(() =>
    parseFloat(props.account.initial_balance),
);

const balanceChange = computed(() => {
    const change = balance.value - initialBalance.value;
    return {
        value: change,
        percentage:
            initialBalance.value !== 0
                ? (change / initialBalance.value) * 100
                : 0,
        isPositive: change >= 0,
    };
});

const cardClasses = computed(() =>
    cn(
        'group relative overflow-hidden rounded-2xl border border-border bg-card p-6',
        'transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-primary/5',
        'cursor-pointer',
        props.class,
    ),
);

const currencyColors: Record<string, string> = {
    EUR: 'from-blue-500 to-indigo-600',
    USD: 'from-emerald-500 to-teal-600',
    GBP: 'from-purple-500 to-pink-600',
    CHF: 'from-red-500 to-orange-600',
    PLN: 'from-rose-500 to-red-600',
};

const gradientClass = computed(() => {
    return currencyColors[props.account.currency] || 'from-primary to-accent';
});
</script>

<template>
    <div :class="cardClasses" @click="emit('click', account)">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div
                    :class="[
                        'flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-lg',
                        gradientClass,
                    ]"
                >
                    <CreditCard class="h-6 w-6" />
                </div>
                <div>
                    <h3 class="font-semibold text-foreground">
                        {{ account.name }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ account.currency }}
                    </p>
                </div>
            </div>
            <Dropdown v-if="showActions" align="right" placement="below">
                <template #trigger>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-muted-foreground opacity-100 transition-all hover:bg-muted hover:text-foreground md:opacity-0 md:group-hover:opacity-100"
                    >
                        <MoreHorizontal class="h-5 w-5" />
                    </button>
                </template>
                <template #default="{ close }">
                    <DropdownItem
                        @click.stop="
                            close();
                            emit('edit', account);
                        "
                    >
                        <Pencil class="h-4 w-4" />
                        Edit
                    </DropdownItem>
                    <DropdownItem
                        destructive
                        @click.stop="
                            close();
                            emit('delete', account);
                        "
                    >
                        <Trash2 class="h-4 w-4" />
                        Delete
                    </DropdownItem>
                </template>
            </Dropdown>
        </div>

        <div class="mt-6">
            <p class="text-sm text-muted-foreground">Current Balance</p>
            <p class="mt-1 text-3xl font-bold tracking-tight text-foreground">
                {{ formatCurrency(balance, account.currency) }}
            </p>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <span
                :class="[
                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                    balanceChange.isPositive
                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                        : 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                ]"
            >
                <TrendingUp v-if="balanceChange.isPositive" class="h-3 w-3" />
                <TrendingDown v-else class="h-3 w-3" />
                {{
                    formatCurrency(
                        Math.abs(balanceChange.value),
                        account.currency,
                    )
                }}
            </span>
            <span class="text-xs text-muted-foreground">since start</span>
        </div>

        <div
            class="absolute -right-12 -bottom-12 h-32 w-32 rounded-full bg-gradient-to-br opacity-10"
            :class="gradientClass"
        />
    </div>
</template>
