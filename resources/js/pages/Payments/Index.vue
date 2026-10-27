<script setup lang="ts">
import { PaymentCard } from '@/components/data-display';
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
            <Card>
                <CardContent class="p-4 pt-4 sm:p-6 sm:pt-6">
                    <div class="flex items-center gap-4">
                        <div
                            class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 sm:flex"
                        >
                            <Wallet class="h-6 w-6 text-primary" />
                        </div>
                        <div class="min-w-0">
                            <p
                                class="truncate text-xs text-muted-foreground sm:text-sm"
                            >
                                Available balance
                            </p>
                            <p
                                class="truncate text-lg font-bold text-foreground tabular-nums sm:text-2xl"
                            >
                                {{
                                    formatCurrency(
                                        totals.available_balance,
                                        totals.primary_currency,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-rose-200 dark:border-rose-900/50">
                <CardContent class="p-4 pt-4 sm:p-6 sm:pt-6">
                    <div class="flex items-center gap-4">
                        <div
                            class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-rose-500/10 sm:flex"
                        >
                            <AlertCircle
                                class="h-6 w-6 text-rose-600 dark:text-rose-400"
                            />
                        </div>
                        <div class="min-w-0">
                            <p
                                class="truncate text-xs text-muted-foreground sm:text-sm"
                            >
                                Overdue ({{ totals.overdue_count }})
                            </p>
                            <p
                                class="truncate text-lg font-bold text-foreground tabular-nums sm:text-2xl"
                            >
                                {{
                                    formatCurrency(
                                        totals.overdue_amount,
                                        totals.primary_currency,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-amber-200 dark:border-amber-900/50">
                <CardContent class="p-4 pt-4 sm:p-6 sm:pt-6">
                    <div class="flex items-center gap-4">
                        <div
                            class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 sm:flex"
                        >
                            <Calendar
                                class="h-6 w-6 text-amber-600 dark:text-amber-400"
                            />
                        </div>
                        <div class="min-w-0">
                            <p
                                class="truncate text-xs text-muted-foreground sm:text-sm"
                            >
                                Upcoming ({{ totals.upcoming_count }})
                            </p>
                            <p
                                class="truncate text-lg font-bold text-foreground tabular-nums sm:text-2xl"
                            >
                                {{
                                    formatCurrency(
                                        totals.upcoming_amount,
                                        totals.primary_currency,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-emerald-200 dark:border-emerald-900/50">
                <CardContent class="p-4 pt-4 sm:p-6 sm:pt-6">
                    <div class="flex items-center gap-4">
                        <div
                            class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 sm:flex"
                        >
                            <CircleDollarSign
                                class="h-6 w-6 text-emerald-600 dark:text-emerald-400"
                            />
                        </div>
                        <div class="min-w-0">
                            <p
                                class="truncate text-xs text-muted-foreground sm:text-sm"
                            >
                                Balance after planned
                            </p>
                            <p
                                class="truncate text-lg font-bold text-foreground tabular-nums sm:text-2xl"
                            >
                                {{
                                    formatCurrency(
                                        totals.balance_after_planned,
                                        totals.primary_currency,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
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
    </div>

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

    <PaymentDialogs :accounts="accounts" />
</template>
