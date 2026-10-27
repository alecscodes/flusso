<script setup lang="ts">
import {
    AmountDisplay,
    CategoryBadge,
    PaymentCard,
} from '@/components/data-display';
import { RecurringPaymentForm } from '@/components/forms';
import PaymentDialogs from '@/components/modals/PaymentDialogs.vue';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
    Modal,
} from '@/components/ui';
import { useDate } from '@/composables';
import { intervalLabel } from '@/lib/utils';
import {
    destroy as destroyRecurringPayment,
    index as recurringPaymentsIndex,
    update as recurringPaymentUpdate,
} from '@/routes/planned-payments';
import type { Account, Category, Payment, RecurringPayment } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Calendar,
    CreditCard,
    Receipt,
    RefreshCw,
} from '@lucide/vue';
import { computed, ref } from 'vue';

interface Props {
    recurringPayment: RecurringPayment;
    statistics: {
        total_payments: number;
        paid_payments: number;
        unpaid_payments: number;
        total_paid_amount: number;
        total_pending_amount: number;
        next_due_date: string | null;
    };
    accounts: Account[];
    categories: Category[];
}

const props = defineProps<Props>();

const { formatDate } = useDate();

const showEditModal = ref(false);

// The cards need the rule (its name, whether it runs once) on every payment.
const payments = computed<Payment[]>(() =>
    (props.recurringPayment.payments ?? []).map((payment) => ({
        ...payment,
        recurring_payment: props.recurringPayment,
    })),
);

const pendingPayments = computed(() =>
    payments.value.filter(
        (p) => p.transaction_id === null && p.skipped_at === null,
    ),
);

const historyPayments = computed(() =>
    payments.value
        .filter((p) => p.transaction_id !== null || p.skipped_at !== null)
        .reverse(),
);

const statusLabel = computed(
    () =>
        ({ active: 'Active', paused: 'Paused', finished: 'Finished' })[
            props.recurringPayment.status ?? 'active'
        ],
);

function deleteRule() {
    if (
        confirm(
            `Delete "${props.recurringPayment.name}"? Unpaid payments are removed, what you already paid stays in your transactions.`,
        )
    ) {
        router.delete(destroyRecurringPayment.url(props.recurringPayment));
    }
}
</script>

<template>
    <Head :title="recurringPayment.name" />

    <div class="space-y-8">
        <PaymentDialogs :accounts="accounts" />

        <div class="flex items-center gap-4">
            <Link :href="recurringPaymentsIndex().url">
                <Button variant="ghost" size="icon">
                    <ArrowLeft class="h-5 w-5" />
                </Button>
            </Link>
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    {{ recurringPayment.name }}
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Planned payment details and schedule
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-1">
                <CardContent class="pt-6">
                    <div class="flex flex-col items-center text-center">
                        <div
                            :class="[
                                'flex h-20 w-20 items-center justify-center rounded-2xl',
                                recurringPayment.status === 'active'
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                        >
                            <RefreshCw class="h-10 w-10" />
                        </div>

                        <h2 class="mt-4 text-xl font-semibold text-foreground">
                            {{ recurringPayment.name }}
                        </h2>

                        <Badge
                            :variant="
                                recurringPayment.status === 'active'
                                    ? 'success'
                                    : 'secondary'
                            "
                            class="mt-2"
                        >
                            {{ statusLabel }}
                        </Badge>

                        <div class="mt-4">
                            <AmountDisplay
                                :amount="recurringPayment.amount"
                                :currency="recurringPayment.currency"
                                :type="
                                    recurringPayment.category?.type === 'income'
                                        ? 'income'
                                        : 'expense'
                                "
                                size="xl"
                                :show-sign="false"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    recurringPayment.installments === 1
                                        ? 'Once'
                                        : intervalLabel(
                                              recurringPayment.interval_value,
                                              recurringPayment.interval_type,
                                          )
                                }}
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
                                    <span class="text-sm text-muted-foreground"
                                        >Starts</span
                                    >
                                </div>
                                <span
                                    class="text-sm font-medium text-foreground"
                                >
                                    {{
                                        formatDate(recurringPayment.start_date)
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
                                    <span class="text-sm text-muted-foreground"
                                        >Ends</span
                                    >
                                </div>
                                <span
                                    class="text-sm font-medium text-foreground"
                                >
                                    {{ formatDate(recurringPayment.end_date) }}
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
                                    <span class="text-sm text-muted-foreground"
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
                                v-if="
                                    recurringPayment.installments &&
                                    recurringPayment.installments > 1
                                "
                                class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Payments</span
                                >
                                <span
                                    class="text-sm font-medium text-foreground"
                                >
                                    {{ statistics.paid_payments }} of
                                    {{ recurringPayment.installments }} paid
                                </span>
                            </div>

                            <div
                                v-if="statistics.next_due_date"
                                class="flex items-center justify-between rounded-xl bg-muted/50 p-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Next payment</span
                                >
                                <span
                                    class="text-sm font-medium text-foreground"
                                >
                                    {{ formatDate(statistics.next_due_date) }}
                                </span>
                            </div>

                            <div class="flex gap-2 pt-2">
                                <Button
                                    variant="outline"
                                    class="flex-1"
                                    @click="showEditModal = true"
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    class="text-destructive hover:text-destructive"
                                    @click="deleteRule"
                                >
                                    Delete
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="space-y-6 lg:col-span-2">
                <Card v-if="pendingPayments.length > 0">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Receipt class="h-5 w-5 text-primary" />
                            To pay
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <PaymentCard
                                v-for="payment in pendingPayments"
                                :key="payment.id"
                                :payment="payment"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="historyPayments.length > 0"
                            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                        >
                            <PaymentCard
                                v-for="payment in historyPayments"
                                :key="payment.id"
                                :payment="payment"
                            />
                        </div>
                        <EmptyState
                            v-else
                            title="Nothing paid yet"
                            description="Paid and skipped payments will appear here"
                            :icon="Receipt"
                            class="py-8"
                        />
                    </CardContent>
                </Card>
            </div>
        </div>

        <Modal
            :open="showEditModal"
            title="Edit planned payment"
            size="lg"
            @close="showEditModal = false"
        >
            <RecurringPaymentForm
                :recurring-payment="recurringPayment"
                :accounts="accounts"
                :categories="categories"
                :action="recurringPaymentUpdate.url(recurringPayment)"
                @success="showEditModal = false"
                @cancel="showEditModal = false"
            />
        </Modal>
    </div>
</template>
