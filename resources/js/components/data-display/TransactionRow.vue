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

const transferRoute = computed(
    () =>
        `${props.transaction.account?.name ?? 'Unknown'} → ${props.transaction.to_account?.name ?? 'Unknown'}`,
);

const title = computed(
    () =>
        props.transaction.description ||
        (isTransfer.value
            ? transferRoute.value
            : (props.transaction.category?.name ?? 'No description')),
);

// A transfer titled by its description still shows where the money went.
const detail = computed(() => {
    if (isTransfer.value) {
        return props.transaction.description ? transferRoute.value : null;
    }

    return props.showAccount ? props.transaction.account?.name : null;
});

const shortDate = computed(() =>
    formatDate(props.transaction.date, {
        year:
            props.transaction.date.slice(0, 4) ===
            String(new Date().getFullYear())
                ? undefined
                : 'numeric',
    }),
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

const looks = {
    income: {
        icon: ArrowDownLeft,
        classes: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    expense: {
        icon: ArrowUpRight,
        classes: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    },
    transfer: {
        icon: ArrowLeftRight,
        classes: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    },
};

// Seen from an account's page, a transfer is money going out or coming in.
const look = computed(() => {
    if (isTransferSource.value) {
        return {
            icon: ArrowUp,
            classes: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        };
    }

    if (isTransferDestination.value) {
        return { icon: ArrowDown, classes: looks.income.classes };
    }

    return looks[props.transaction.type];
});

const rowClasses = computed(() =>
    cn(
        'group flex cursor-pointer items-center gap-3 rounded-xl p-2 transition-colors outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-primary/30 sm:gap-4 sm:p-3',
        props.class,
    ),
);
</script>

<template>
    <div
        :class="rowClasses"
        role="button"
        tabindex="0"
        @click="emit('view', transaction)"
        @keydown.enter.self="emit('view', transaction)"
        @keydown.space.self.prevent="emit('view', transaction)"
    >
        <div
            :class="[
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                look.classes,
            ]"
        >
            <component :is="look.icon" class="h-5 w-5" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-baseline justify-between gap-3">
                <p class="min-w-0 truncate font-medium text-foreground">
                    {{ title }}
                </p>
                <div class="shrink-0 text-right">
                    <AmountDisplay
                        :amount="amount"
                        :currency="currency"
                        :type="amountType"
                        size="md"
                        class="font-semibold whitespace-nowrap"
                    />
                    <p
                        v-if="receivedNote"
                        class="text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                    >
                        {{ receivedNote }}
                    </p>
                </div>
            </div>
            <div
                class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
            >
                <span class="whitespace-nowrap">{{ shortDate }}</span>
                <template v-if="detail">
                    <span class="text-border">·</span>
                    <span class="min-w-0 truncate">{{ detail }}</span>
                </template>
                <CategoryBadge
                    v-if="
                        transaction.category &&
                        title !== transaction.category.name
                    "
                    :category="transaction.category"
                    size="sm"
                    class="max-w-full min-w-0 overflow-hidden whitespace-nowrap"
                />
            </div>
        </div>

        <Dropdown align="right" placement="below">
            <template #trigger="{ open }">
                <button
                    type="button"
                    :aria-label="`Actions for ${title}`"
                    aria-haspopup="menu"
                    :aria-expanded="open"
                    class="rounded-lg p-1.5 text-muted-foreground transition-all hover:bg-muted hover:text-foreground focus-visible:opacity-100 [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:group-focus-within:opacity-100 [@media(hover:hover)]:group-hover:opacity-100"
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
