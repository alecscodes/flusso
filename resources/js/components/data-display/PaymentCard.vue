<script setup lang="ts">
import AmountDisplay from '@/components/data-display/AmountDisplay.vue';
import CategoryBadge from '@/components/data-display/CategoryBadge.vue';
import { Badge, Button } from '@/components/ui';
import {
    paymentName,
    runsOnce,
    useDate,
    usePaymentActions,
} from '@/composables';
import { cn } from '@/lib/utils';
import type { Payment } from '@/types';
import { Calendar, Check, Clock, RefreshCw, SkipForward } from '@lucide/vue';
import { computed } from 'vue';

interface Props {
    payment: Payment;
    class?: string;
}

const props = defineProps<Props>();

const actions = usePaymentActions();
const { formatDate, formatRelative, isOverdue, isDueToday, isDueSoon } =
    useDate();

const isPaid = computed(() => props.payment.transaction_id !== null);
const isSkipped = computed(() => props.payment.skipped_at !== null);

const status = computed(() => {
    if (isPaid.value) return 'paid';
    if (isSkipped.value) return 'skipped';
    if (isOverdue(props.payment.due_date)) return 'overdue';
    if (isDueToday(props.payment.due_date)) return 'today';
    if (isDueSoon(props.payment.due_date, 3)) return 'soon';
    return 'upcoming';
});

const statusConfig = {
    paid: {
        badge: 'success' as const,
        label: 'Paid',
        icon: Check,
    },
    skipped: {
        badge: 'secondary' as const,
        label: 'Skipped',
        icon: SkipForward,
    },
    overdue: {
        badge: 'destructive' as const,
        label: 'Overdue',
        icon: Clock,
    },
    today: {
        badge: 'warning' as const,
        label: 'Due Today',
        icon: Calendar,
    },
    soon: {
        badge: 'warning' as const,
        label: 'Due Soon',
        icon: Calendar,
    },
    upcoming: {
        badge: 'secondary' as const,
        label: 'Upcoming',
        icon: Calendar,
    },
};

/** What was actually paid, when the owner paid another amount. */
const shownAmount = computed(
    () => props.payment.transaction?.amount ?? props.payment.amount,
);

const name = computed(() => paymentName(props.payment));

const cardClasses = computed(() =>
    cn(
        'group relative min-w-0 overflow-hidden rounded-2xl border bg-card p-4 transition-all duration-300 sm:p-5',
        isSkipped.value && 'opacity-60',
        isPaid.value
            ? 'border-emerald-200 dark:border-emerald-900/50'
            : status.value === 'overdue'
              ? 'border-rose-200 dark:border-rose-900/50'
              : 'border-border hover:-translate-y-1 hover:shadow-lg hover:shadow-primary/5',
        props.class,
    ),
);
</script>

<template>
    <div :class="cardClasses">
        <div
            class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3">
                <div
                    :class="[
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                        isPaid
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                            : status === 'overdue'
                              ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                              : 'bg-primary/10 text-primary',
                    ]"
                >
                    <component
                        :is="statusConfig[status].icon"
                        class="h-5 w-5"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <h3
                        class="truncate font-semibold text-foreground"
                        :title="name"
                    >
                        {{ name }}
                    </h3>
                    <div
                        class="mt-0.5 flex flex-wrap items-center gap-x-1 gap-y-0 text-sm text-muted-foreground"
                    >
                        <span>{{ formatRelative(payment.due_date) }}</span>
                        <span class="shrink-0 text-border">•</span>
                        <span>{{ formatDate(payment.due_date) }}</span>
                    </div>
                </div>
            </div>
            <Badge
                :variant="statusConfig[status].badge"
                class="shrink-0 self-start"
            >
                {{ statusConfig[status].label }}
            </Badge>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 gap-y-1">
            <AmountDisplay
                :amount="shownAmount"
                :currency="payment.transaction?.currency ?? payment.currency"
                :type="payment.type"
                size="lg"
                class="shrink-0"
            />
            <CategoryBadge
                v-if="payment.category"
                :category="payment.category"
                size="sm"
                class="shrink-0"
            />
            <div
                v-if="payment.recurring_payment"
                class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
            >
                <RefreshCw class="h-3 w-3" />
                Planned
            </div>
        </div>

        <div
            v-if="payment.account"
            class="mt-3 truncate text-sm text-muted-foreground"
        >
            From: {{ payment.account.name }}
        </div>

        <div class="mt-4 flex min-w-0 flex-wrap gap-2">
            <template v-if="isPaid">
                <Button
                    variant="outline"
                    size="sm"
                    class="flex-1"
                    @click="actions.markUnpaid(payment)"
                >
                    Undo Payment
                </Button>
            </template>
            <template v-else-if="isSkipped">
                <Button
                    variant="outline"
                    size="sm"
                    class="flex-1"
                    @click="actions.unskip(payment)"
                >
                    Restore
                </Button>
            </template>
            <template v-else>
                <Button
                    variant="success"
                    size="sm"
                    class="flex-1"
                    @click="actions.pay(payment)"
                >
                    <Check class="h-4 w-4" />
                    Mark as Paid
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="actions.edit(payment)"
                >
                    Edit
                </Button>
                <Button
                    v-if="runsOnce(payment)"
                    variant="ghost"
                    size="sm"
                    class="text-destructive hover:text-destructive"
                    @click="actions.remove(payment)"
                >
                    Delete
                </Button>
                <Button
                    v-else
                    variant="ghost"
                    size="sm"
                    @click="actions.skip(payment)"
                >
                    Skip
                </Button>
            </template>
        </div>
    </div>
</template>
