<script setup lang="ts">
import {
    AmountDisplay,
    CategoryBadge,
    PaymentCard,
} from '@/components/data-display';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
} from '@/components/ui';
import { useDate } from '@/composables';
import { AppLayout } from '@/layouts';
import type { RecurringPayment } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Calendar,
    CreditCard,
    Receipt,
    RefreshCw,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    recurringPayment: RecurringPayment;
}

const props = defineProps<Props>();

const { formatDate } = useDate();

function getIntervalLabel(): string {
    const value = props.recurringPayment.interval_value;
    const type = props.recurringPayment.interval_type;

    if (value === 1) {
        switch (type) {
            case 'days':
                return 'Daily';
            case 'weeks':
                return 'Weekly';
            case 'months':
                return 'Monthly';
            case 'years':
                return 'Yearly';
        }
    }
    return `Every ${value} ${type}`;
}

const paidPayments = computed(() => {
    return props.recurringPayment.payments?.filter((p) => p.is_paid) || [];
});

const unpaidPayments = computed(() => {
    return props.recurringPayment.payments?.filter((p) => !p.is_paid) || [];
});

function markPaid(payment: { id: number }) {
    router.patch(
        `/payments/${payment.id}/mark-paid`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function markUnpaid(payment: { id: number }) {
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
    <Head :title="recurringPayment.name" />

    <AppLayout>
        <div class="space-y-8">
            <div class="flex items-center gap-4">
                <Link href="/recurring-payments">
                    <Button variant="ghost" size="icon">
                        <ArrowLeft class="h-5 w-5" />
                    </Button>
                </Link>
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        {{ recurringPayment.name }}
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Planned payment details and schedule
                    </p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card class="lg:col-span-1">
                    <CardContent class="pt-6">
                        <div class="flex flex-col items-center text-center">
                            <div
                                :class="[
                                    'flex h-20 w-20 items-center justify-center rounded-2xl',
                                    recurringPayment.is_active
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-muted text-muted-foreground',
                                ]"
                            >
                                <RefreshCw class="h-10 w-10" />
                            </div>

                            <h2
                                class="mt-4 text-xl font-semibold text-foreground"
                            >
                                {{ recurringPayment.name }}
                            </h2>

                            <Badge
                                :variant="
                                    recurringPayment.is_active
                                        ? 'success'
                                        : 'secondary'
                                "
                                class="mt-2"
                            >
                                {{
                                    recurringPayment.is_active
                                        ? 'Active'
                                        : 'Inactive'
                                }}
                            </Badge>

                            <div class="mt-4">
                                <AmountDisplay
                                    :amount="recurringPayment.amount"
                                    :currency="recurringPayment.currency"
                                    type="expense"
                                    size="xl"
                                    :show-sign="false"
                                />
                                <p class="text-sm text-muted-foreground">
                                    {{ getIntervalLabel() }}
                                </p>
                            </div>

                            <div class="mt-6 w-full space-y-3">
                                <div
                                    class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <Calendar
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >Start Date</span
                                        >
                                    </div>
                                    <span
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{
                                            formatDate(
                                                recurringPayment.start_date,
                                            )
                                        }}
                                    </span>
                                </div>

                                <div
                                    v-if="recurringPayment.end_date"
                                    class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <Calendar
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >End Date</span
                                        >
                                    </div>
                                    <span
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{
                                            formatDate(
                                                recurringPayment.end_date,
                                            )
                                        }}
                                    </span>
                                </div>

                                <div
                                    v-if="recurringPayment.account"
                                    class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <CreditCard
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span
                                            class="text-sm text-muted-foreground"
                                            >Account</span
                                        >
                                    </div>
                                    <span
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{ recurringPayment.account.name }}
                                    </span>
                                </div>

                                <div
                                    v-if="recurringPayment.category"
                                    class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                                >
                                    <span class="text-sm text-muted-foreground"
                                        >Category</span
                                    >
                                    <CategoryBadge
                                        :category="recurringPayment.category"
                                        size="sm"
                                    />
                                </div>

                                <div
                                    v-if="recurringPayment.installments"
                                    class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                                >
                                    <span class="text-sm text-muted-foreground"
                                        >Installments</span
                                    >
                                    <span
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{ recurringPayment.installments }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="space-y-6 lg:col-span-2">
                    <Card v-if="unpaidPayments.length > 0">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Receipt class="h-5 w-5 text-primary" />
                                Upcoming Payments
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <PaymentCard
                                    v-for="payment in unpaidPayments"
                                    :key="payment.id"
                                    :payment="payment"
                                    @mark-paid="markPaid"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Payment History</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="paidPayments.length > 0"
                                class="grid gap-4 sm:grid-cols-2"
                            >
                                <PaymentCard
                                    v-for="payment in paidPayments"
                                    :key="payment.id"
                                    :payment="payment"
                                    @mark-unpaid="markUnpaid"
                                />
                            </div>
                            <EmptyState
                                v-else
                                title="No payment history"
                                description="Paid payments will appear here"
                                :icon="Receipt"
                                class="py-8"
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
