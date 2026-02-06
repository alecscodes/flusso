<script setup lang="ts">
import { PaymentCard } from '@/components/data-display';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { AppLayout } from '@/layouts';
import type { Payment } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Calendar,
    CheckCircle,
    CircleDollarSign,
    Receipt,
    Wallet,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface PaymentTotals {
    total_balance: number;
    overdue_amount: number;
    upcoming_amount: number;
    total_due_this_period: number;
    balance_after_planned: number;
    primary_currency: string;
}

interface Props {
    payments: Payment[];
    totals: PaymentTotals;
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { isOverdue, formatMonthYear } = useDate();

const overduePayments = computed(() => {
    return props.payments.filter((p) => !p.is_paid && isOverdue(p.due_date));
});

const upcomingPayments = computed(() => {
    return props.payments.filter((p) => !p.is_paid && !isOverdue(p.due_date));
});

const paidPayments = computed(() => {
    return props.payments.filter((p) => p.is_paid);
});

const groupedUpcoming = computed(() => {
    const groups: Record<string, Payment[]> = {};
    upcomingPayments.value.forEach((payment) => {
        const key = formatMonthYear(payment.due_date);
        if (!groups[key]) groups[key] = [];
        groups[key].push(payment);
    });
    return groups;
});

const totalByMonth = computed(() => {
    const totals: Record<string, number> = {};
    for (const [month, payments] of Object.entries(groupedUpcoming.value)) {
        totals[month] = payments.reduce(
            (sum, p) => sum + parseFloat(String(p.amount)),
            0,
        );
    }
    return totals;
});

function markPaid(payment: Payment) {
    router.patch(
        `/payments/${payment.id}/mark-paid`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function markUnpaid(payment: Payment) {
    router.patch(
        `/payments/${payment.id}/mark-unpaid`,
        {},
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head title="Payments" />

    <AppLayout>
        <div class="space-y-8">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Payments
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Track and manage all your upcoming and past payments
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10"
                            >
                                <Wallet class="h-6 w-6 text-primary" />
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Total balance
                                </p>
                                <p class="text-2xl font-bold text-foreground">
                                    {{
                                        formatCurrency(
                                            totals.total_balance,
                                            totals.primary_currency,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-rose-200 dark:border-rose-900/50">
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-500/10"
                            >
                                <AlertCircle
                                    class="h-6 w-6 text-rose-600 dark:text-rose-400"
                                />
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Overdue ({{ overduePayments.length }})
                                </p>
                                <p class="text-2xl font-bold text-foreground">
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
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500/10"
                            >
                                <Calendar
                                    class="h-6 w-6 text-amber-600 dark:text-amber-400"
                                />
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Upcoming ({{ upcomingPayments.length }})
                                </p>
                                <p class="text-2xl font-bold text-foreground">
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
                    <CardContent class="pt-6">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10"
                            >
                                <CircleDollarSign
                                    class="h-6 w-6 text-emerald-600 dark:text-emerald-400"
                                />
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Balance after planned
                                </p>
                                <p class="text-2xl font-bold text-foreground">
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

            <Card class="border-rose-200/50 dark:border-rose-900/30">
                <CardContent class="pt-6">
                    <div class="flex items-center gap-4">
                        <Receipt
                            class="h-8 w-8 text-rose-500/80 dark:text-rose-400/80"
                        />
                        <div>
                            <p class="text-sm text-muted-foreground">
                                To pay this period
                            </p>
                            <p class="text-xl font-semibold text-foreground">
                                {{
                                    formatCurrency(
                                        totals.total_due_this_period,
                                        totals.primary_currency,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="overduePayments.length > 0">
                <CardHeader>
                    <CardTitle
                        class="flex items-center gap-2 text-rose-600 dark:text-rose-400"
                    >
                        <AlertCircle class="h-5 w-5" />
                        Overdue Payments
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <PaymentCard
                            v-for="payment in overduePayments"
                            :key="payment.id"
                            :payment="payment"
                            @mark-paid="markPaid"
                        />
                    </div>
                </CardContent>
            </Card>

            <div
                v-if="Object.keys(groupedUpcoming).length > 0"
                class="space-y-6"
            >
                <Card v-for="(payments, month) in groupedUpcoming" :key="month">
                    <CardHeader>
                        <CardTitle
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span class="flex items-center gap-2">
                                <Calendar class="h-5 w-5 text-primary" />
                                {{ month }}
                            </span>
                            <span
                                class="rounded-lg bg-muted px-3 py-1 text-base font-semibold text-foreground"
                            >
                                {{
                                    formatCurrency(
                                        totalByMonth[month],
                                        totals.primary_currency,
                                    )
                                }}
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <PaymentCard
                                v-for="payment in payments"
                                :key="payment.id"
                                :payment="payment"
                                @mark-paid="markPaid"
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <EmptyState
                v-if="payments.length === 0"
                title="No payments"
                description="Payments from your planned payments and subscriptions will appear here"
                :icon="Receipt"
            />

            <Card v-if="paidPayments.length > 0">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <CheckCircle
                            class="h-5 w-5 text-emerald-600 dark:text-emerald-400"
                        />
                        Recently Paid
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <PaymentCard
                            v-for="payment in paidPayments.slice(0, 6)"
                            :key="payment.id"
                            :payment="payment"
                            @mark-unpaid="markUnpaid"
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
