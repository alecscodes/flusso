<script setup lang="ts">
import {
    AccountCard,
    AmountDisplay,
    PaymentCard,
    StatCard,
} from '@/components/data-display';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { AppLayout } from '@/layouts';
import type {
    Account,
    CategorySpending,
    Payment,
    PaymentSummary,
    Period,
    Summary,
} from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Calendar,
    CircleDollarSign,
    Plus,
    Receipt,
    TrendingUp,
    Wallet,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    accounts: Account[];
    period: Period;
    summary: Summary;
    paymentSummary: PaymentSummary;
    upcomingPayments: Payment[];
    overduePayments: Payment[];
    categorySpending: CategorySpending[];
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

const totalBalance = computed(() => {
    return props.accounts.reduce((sum, account) => {
        return sum + parseFloat(account.balance);
    }, 0);
});

const primaryCurrency = computed(() => {
    return props.accounts[0]?.currency || 'EUR';
});

const topCategories = computed(() => {
    return props.categorySpending.slice(0, 5);
});

const maxCategorySpending = computed(() => {
    if (topCategories.value.length === 0) return 1;
    return Math.max(...topCategories.value.map((c) => c.total));
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="space-y-8">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Dashboard
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        {{ formatDate(period.start) }} -
                        {{ formatDate(period.end) }}
                    </p>
                </div>
                <div class="flex gap-3">
                    <Link href="/transactions">
                        <Button>
                            <Plus class="h-4 w-4" />
                            Add Transaction
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Total Balance"
                    :value="formatCurrency(totalBalance, primaryCurrency)"
                    subtitle="What you have now"
                    :icon="Wallet"
                />
                <StatCard
                    title="Income (period)"
                    :value="formatCurrency(summary.income, primaryCurrency)"
                    :icon="ArrowDownLeft"
                    trend="up"
                    class="border-emerald-200 dark:border-emerald-900/50"
                />
                <StatCard
                    title="Expenses (period)"
                    :value="formatCurrency(summary.expenses, primaryCurrency)"
                    :icon="ArrowUpRight"
                    trend="down"
                    class="border-rose-200 dark:border-rose-900/50"
                />
                <StatCard
                    title="Net (period)"
                    :value="formatCurrency(summary.net, primaryCurrency)"
                    :icon="TrendingUp"
                    :trend="summary.net >= 0 ? 'up' : 'down'"
                />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <StatCard
                    title="Planned expenses"
                    :value="
                        formatCurrency(
                            paymentSummary.total_due,
                            primaryCurrency,
                        )
                    "
                    subtitle="Still to pay this period"
                    :icon="Receipt"
                    class="border-rose-200 dark:border-rose-900/50"
                />
                <StatCard
                    title="Balance after planned"
                    :value="
                        formatCurrency(
                            paymentSummary.balance_after_planned,
                            primaryCurrency,
                        )
                    "
                    subtitle="After paying all planned expenses"
                    :icon="CircleDollarSign"
                    :trend="
                        paymentSummary.balance_after_planned >= 0
                            ? 'up'
                            : 'down'
                    "
                />
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between"
                        >
                            <CardTitle>Accounts</CardTitle>
                            <Link href="/accounts">
                                <Button variant="ghost" size="sm">
                                    View All
                                </Button>
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="accounts.length > 0"
                                class="grid gap-4 sm:grid-cols-2"
                            >
                                <AccountCard
                                    v-for="account in accounts.slice(0, 4)"
                                    :key="account.id"
                                    :account="account"
                                    :show-actions="false"
                                    @click="
                                        router.visit(`/accounts/${account.id}`)
                                    "
                                />
                            </div>
                            <EmptyState
                                v-else
                                title="No accounts yet"
                                description="Create your first account to start tracking your finances"
                                :icon="Wallet"
                            >
                                <template #action>
                                    <Link href="/accounts">
                                        <Button>
                                            <Plus class="h-4 w-4" />
                                            Add Account
                                        </Button>
                                    </Link>
                                </template>
                            </EmptyState>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between"
                        >
                            <CardTitle>Spending by Category</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                v-if="topCategories.length > 0"
                                class="space-y-4"
                            >
                                <div
                                    v-for="item in topCategories"
                                    :key="item.category.id"
                                    class="space-y-2"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="h-3 w-3 rounded-full"
                                                :style="{
                                                    backgroundColor:
                                                        item.category.color ||
                                                        '#7c3aed',
                                                }"
                                            />
                                            <span
                                                class="text-sm font-medium text-foreground"
                                            >
                                                {{ item.category.name }}
                                            </span>
                                        </div>
                                        <AmountDisplay
                                            :amount="item.total"
                                            :currency="primaryCurrency"
                                            type="expense"
                                            size="sm"
                                            :show-sign="false"
                                        />
                                    </div>
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :style="{
                                                width: `${(item.total / maxCategorySpending) * 100}%`,
                                                backgroundColor:
                                                    item.category.color ||
                                                    '#7c3aed',
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                            <EmptyState
                                v-else
                                title="No spending data"
                                description="Add some transactions to see your spending breakdown"
                                class="py-8"
                            />
                        </CardContent>
                    </Card>
                </div>

                <div class="space-y-6">
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between"
                        >
                            <CardTitle class="flex items-center gap-2">
                                <Calendar class="h-5 w-5 text-primary" />
                                Upcoming Payments
                            </CardTitle>
                            <Link href="/payments">
                                <Button variant="ghost" size="sm">
                                    View All
                                </Button>
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <div v-if="overduePayments.length > 0" class="mb-4">
                                <p
                                    class="mb-2 text-sm font-medium text-destructive"
                                >
                                    Overdue
                                </p>
                                <div class="space-y-3">
                                    <PaymentCard
                                        v-for="payment in overduePayments.slice(
                                            0,
                                            2,
                                        )"
                                        :key="payment.id"
                                        :payment="payment"
                                    />
                                </div>
                            </div>

                            <div v-if="upcomingPayments.length > 0">
                                <p
                                    v-if="overduePayments.length > 0"
                                    class="mb-2 text-sm font-medium text-muted-foreground"
                                >
                                    Coming Up
                                </p>
                                <div class="space-y-3">
                                    <PaymentCard
                                        v-for="payment in upcomingPayments.slice(
                                            0,
                                            3,
                                        )"
                                        :key="payment.id"
                                        :payment="payment"
                                    />
                                </div>
                            </div>

                            <EmptyState
                                v-if="
                                    upcomingPayments.length === 0 &&
                                    overduePayments.length === 0
                                "
                                title="No upcoming payments"
                                description="You're all caught up!"
                                :icon="Calendar"
                                class="py-8"
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
