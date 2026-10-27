<script setup lang="ts">
import { PaymentCard, StatCard } from '@/components/data-display';
import { RecurringPaymentForm } from '@/components/forms';
import PaymentDialogs from '@/components/modals/PaymentDialogs.vue';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
    Modal,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { store as recurringPaymentStore } from '@/routes/planned-payments';
import type { Account, Category, Payment } from '@/types';
import { Head } from '@inertiajs/vue3';
import {
    AlertCircle,
    Calendar,
    CircleDollarSign,
    Plus,
    Receipt,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

interface PaymentTotals {
    available_balance: number;
    overdue_amount: number;
    overdue_count: number;
    upcoming_amount: number;
    upcoming_count: number;
    period_amount: number;
    balance_after_planned: number;
    primary_currency: string;
}

interface Props {
    overduePayments: Payment[];
    periodPayments: Payment[];
    laterPayments: Payment[];
    /** "YYYY-MM" => what is still to pay that month, in the primary currency. */
    monthlyTotals: Record<string, number>;
    accounts: Account[];
    categories: Category[];
    totals: PaymentTotals;
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatMonthYear } = useDate();

const showCreateModal = ref(false);

const stats = computed(() => [
    {
        title: 'Available balance',
        amount: props.totals.available_balance,
        icon: Wallet,
    },
    {
        title: `Overdue (${props.totals.overdue_count})`,
        amount: props.totals.overdue_amount,
        icon: AlertCircle,
        iconClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
        class: 'border-rose-200 dark:border-rose-900/50',
    },
    {
        title: `Upcoming (${props.totals.upcoming_count})`,
        amount: props.totals.upcoming_amount,
        icon: Calendar,
        iconClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        class: 'border-amber-200 dark:border-amber-900/50',
    },
    {
        title: 'Balance after planned',
        amount: props.totals.balance_after_planned,
        icon: CircleDollarSign,
        iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        class: 'border-emerald-200 dark:border-emerald-900/50',
    },
]);

const laterByMonth = computed(() => {
    const months: Record<string, Payment[]> = {};

    props.laterPayments.forEach((payment) => {
        (months[payment.due_date.slice(0, 7)] ??= []).push(payment);
    });

    return Object.entries(months);
});

const isEmpty = computed(
    () =>
        props.overduePayments.length +
            props.periodPayments.length +
            props.laterPayments.length ===
        0,
);
</script>

<template>
    <Head title="Payments" />

    <div class="space-y-8">
        <PaymentDialogs :accounts="accounts" />

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Payments
                </h1>
                <p class="mt-1 text-muted-foreground">
                    What you have to pay, and when
                </p>
            </div>
            <Button @click="showCreateModal = true">
                <Plus class="h-4 w-4" />
                Add planned payment
            </Button>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard
                v-for="stat in stats"
                :key="stat.title"
                :title="stat.title"
                :value="formatCurrency(stat.amount, totals.primary_currency)"
                :icon="stat.icon"
                :icon-class="stat.iconClass"
                :class="stat.class"
            />
        </div>

        <Card v-if="overduePayments.length > 0">
            <CardHeader>
                <CardTitle
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span
                        class="flex items-center gap-2 text-rose-600 dark:text-rose-400"
                    >
                        <AlertCircle class="h-5 w-5" />
                        Overdue
                    </span>
                    <span
                        class="rounded-lg bg-muted px-3 py-1 text-base font-semibold text-foreground"
                    >
                        {{
                            formatCurrency(
                                totals.overdue_amount,
                                totals.primary_currency,
                            )
                        }}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <PaymentCard
                        v-for="payment in overduePayments"
                        :key="payment.id"
                        :payment="payment"
                    />
                </div>
            </CardContent>
        </Card>

        <Card v-if="periodPayments.length > 0">
            <CardHeader>
                <CardTitle
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="flex items-center gap-2">
                        <Calendar class="h-5 w-5 text-primary" />
                        This period
                    </span>
                    <span
                        class="rounded-lg bg-muted px-3 py-1 text-base font-semibold text-foreground"
                    >
                        {{
                            formatCurrency(
                                totals.period_amount,
                                totals.primary_currency,
                            )
                        }}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <PaymentCard
                        v-for="payment in periodPayments"
                        :key="payment.id"
                        :payment="payment"
                    />
                </div>
            </CardContent>
        </Card>

        <Card v-for="[month, payments] in laterByMonth" :key="month">
            <CardHeader>
                <CardTitle
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="flex items-center gap-2">
                        <Calendar class="h-5 w-5 text-muted-foreground" />
                        {{ formatMonthYear(`${month}-01`) }}
                    </span>
                    <span
                        class="rounded-lg bg-muted px-3 py-1 text-base font-semibold text-foreground"
                    >
                        {{
                            formatCurrency(
                                monthlyTotals[month] ?? 0,
                                totals.primary_currency,
                            )
                        }}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <PaymentCard
                        v-for="payment in payments"
                        :key="payment.id"
                        :payment="payment"
                    />
                </div>
            </CardContent>
        </Card>

        <EmptyState
            v-if="isEmpty"
            title="Nothing planned"
            description="Add a planned payment for a bill, a subscription or a one-off you need to remember"
            :icon="Receipt"
        >
            <template #action>
                <Button @click="showCreateModal = true">
                    <Plus class="h-4 w-4" />
                    Add planned payment
                </Button>
            </template>
        </EmptyState>

        <Modal
            :open="showCreateModal"
            title="Add planned payment"
            description="A bill, a subscription or a one-off you want to remember"
            size="lg"
            @close="showCreateModal = false"
        >
            <RecurringPaymentForm
                :accounts="accounts"
                :categories="categories"
                :action="recurringPaymentStore.url()"
                @success="showCreateModal = false"
                @cancel="showCreateModal = false"
            />
        </Modal>
    </div>
</template>
