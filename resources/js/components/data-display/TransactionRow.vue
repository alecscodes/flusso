<script setup lang="ts">
import AmountDisplay from '@/components/data-display/AmountDisplay.vue';
import CategoryBadge from '@/components/data-display/CategoryBadge.vue';
import { Dropdown, DropdownItem } from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { cn } from '@/lib/utils';
import type { Transaction } from '@/types';
import {
    ArrowDown,
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUp,
    ArrowUpRight,
    MoreHorizontal,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';

interface Props {
    transaction: Transaction;
    showAccount?: boolean;
    /** The account whose page this row is on: transfers are then signed from its perspective. */
    contextAccountId?: number;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    showAccount: true,
});

const emit = defineEmits<{
    view: [transaction: Transaction];
    edit: [transaction: Transaction];
    delete: [transaction: Transaction];
}>();

const { formatDate } = useDate();
const { formatCurrency } = useCurrency();

const isTransfer = computed(() => props.transaction.type === 'transfer');
const isTransferSource = computed(
    () =>
        isTransfer.value &&
        props.contextAccountId === props.transaction.account_id,
);
const isTransferDestination = computed(
    () =>
        isTransfer.value &&
        props.contextAccountId === props.transaction.to_account_id,
);

const title = computed(() =>
    isTransfer.value
        ? `${props.transaction.account?.name ?? 'Unknown'} → ${props.transaction.to_account?.name ?? 'Unknown'}`
        : props.transaction.description || 'No description',
);

// An incoming transfer is worth what arrived, in the destination's currency.
const amount = computed(() =>
    isTransferDestination.value
        ? (props.transaction.to_amount ?? props.transaction.amount)
        : props.transaction.amount,
);
const currency = computed(() =>
    isTransferDestination.value
        ? (props.transaction.to_account?.currency ?? props.transaction.currency)
        : props.transaction.currency,
);
const amountType = computed(() => {
    if (isTransferSource.value) return 'expense';
    if (isTransferDestination.value) return 'income';
    return props.transaction.type;
});

const receivedNote = computed(() =>
    isTransfer.value &&
    !props.contextAccountId &&
    props.transaction.to_amount &&
    props.transaction.to_account &&
    props.transaction.to_account.currency !== props.transaction.currency
        ? `→ ${formatCurrency(props.transaction.to_amount, props.transaction.to_account.currency)}`
        : null,
);

const typeIcon = computed(() => {
    switch (props.transaction.type) {
        case 'income':
            return ArrowDownLeft;
        case 'expense':
            return ArrowUpRight;
        case 'transfer':
            if (isTransferSource.value) {
                return ArrowUp; // Money going out (source)
            }
            if (isTransferDestination.value) {
                return ArrowDown; // Money coming in (destination)
            }
            return ArrowLeftRight; // Default transfer icon
        default:
            return ArrowUpRight;
    }
});

const typeIconClasses = computed(() => {
    switch (props.transaction.type) {
        case 'income':
            return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
        case 'expense':
            return 'bg-rose-500/10 text-rose-600 dark:text-rose-400';
        case 'transfer':
            if (isTransferSource.value) {
                return 'bg-amber-500/10 text-amber-600 dark:text-amber-400'; // Source (money out)
            }
            if (isTransferDestination.value) {
                return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'; // Destination (money in)
            }
            return 'bg-blue-500/10 text-blue-600 dark:text-blue-400'; // Default transfer
        default:
            return 'bg-muted text-muted-foreground';
    }
});

const rowClasses = computed(() =>
    cn(
        'group flex items-center gap-4 rounded-xl p-3 transition-colors hover:bg-muted/50',
        props.class,
    ),
);
</script>

<template>
    <div :class="rowClasses" @click="emit('view', transaction)">
        <div
            :class="[
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                typeIconClasses,
            ]"
        >
            <component :is="typeIcon" class="h-5 w-5" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <p class="truncate font-medium text-foreground">
                    {{ title }}
                </p>
                <CategoryBadge
                    v-if="transaction.category"
                    :category="transaction.category"
                    size="sm"
                />
            </div>
            <div
                class="mt-0.5 flex items-center gap-2 text-sm text-muted-foreground"
            >
                <span>{{ formatDate(transaction.date) }}</span>
                <span
                    v-if="showAccount && transaction.account && !isTransfer"
                    class="flex items-center gap-1"
                >
                    <span class="text-border">•</span>
                    {{ transaction.account.name }}
                </span>
                <span
                    v-if="isTransfer && transaction.description"
                    class="flex items-center gap-1 truncate"
                >
                    <span class="text-border">•</span>
                    {{ transaction.description }}
                </span>
            </div>
        </div>

        <div class="text-right">
            <AmountDisplay
                :amount="amount"
                :currency="currency"
                :type="amountType"
                size="md"
                class="font-semibold"
            />
            <p v-if="receivedNote" class="text-xs text-muted-foreground">
                {{ receivedNote }}
            </p>
        </div>

        <Dropdown align="right" placement="below">
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
                        emit('edit', transaction);
                    "
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </DropdownItem>
                <DropdownItem
                    destructive
                    @click.stop="
                        close();
                        emit('delete', transaction);
                    "
                >
                    <Trash2 class="h-4 w-4" />
                    Delete
                </DropdownItem>
            </template>
        </Dropdown>
    </div>
</template>
