<script setup lang="ts">
import AmountDisplay from '@/components/data-display/AmountDisplay.vue';
import CategoryBadge from '@/components/data-display/CategoryBadge.vue';
import { Dropdown, DropdownItem } from '@/components/ui';
import { useDate } from '@/composables';
import { useTransferTransaction } from '@/composables/useTransferTransaction';
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
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    transaction: Transaction;
    showAccount?: boolean;
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
const {
    isTransferSource,
    isTransferDestination,
    transferDescription,
    transferAccountInfo,
} = useTransferTransaction(() => props.transaction);

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
                    {{
                        transferDescription ||
                        transaction.description ||
                        'No description'
                    }}
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
                    v-if="
                        showAccount &&
                        transaction.account &&
                        !transferDescription
                    "
                    class="flex items-center gap-1"
                >
                    <span class="text-border">•</span>
                    {{ transaction.account.name }}
                </span>
                <span
                    v-if="showAccount && transferAccountInfo"
                    class="flex items-center gap-1"
                >
                    <span class="text-border">•</span>
                    {{ transferAccountInfo }}
                </span>
            </div>
        </div>

        <AmountDisplay
            :amount="transaction.amount"
            :currency="transaction.currency"
            :type="transaction.type"
            size="md"
            class="font-semibold"
        />

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
