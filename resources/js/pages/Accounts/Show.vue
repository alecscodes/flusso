<script setup lang="ts">
import { AmountDisplay, TransactionRow } from '@/components/data-display';
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
import type { Account } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    CreditCard,
    Trash2,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    account: Account;
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

const balance = computed(() => parseFloat(props.account.balance));
const initialBalance = computed(() =>
    parseFloat(props.account.initial_balance),
);

const balanceChange = computed(() => {
    const change = balance.value - initialBalance.value;
    return {
        value: change,
        isPositive: change >= 0,
    };
});

const currencyColors: Record<string, string> = {
    EUR: 'from-blue-500 to-indigo-600',
    USD: 'from-emerald-500 to-teal-600',
    GBP: 'from-purple-500 to-pink-600',
    CHF: 'from-red-500 to-orange-600',
    PLN: 'from-rose-500 to-red-600',
};

const gradientClass = computed(() => {
    return currencyColors[props.account.currency] || 'from-primary to-accent';
});

function deleteAccount() {
    if (
        confirm(
            `Are you sure you want to delete "${props.account.name}"? All transactions and related data for this account will be removed.`,
        )
    ) {
        router.delete(`/accounts/${props.account.id}`);
    }
}
</script>

<template>
    <Head :title="account.name" />

    <AppLayout>
        <div class="space-y-8">
            <div class="flex items-center gap-4">
                <Link href="/accounts">
                    <Button variant="ghost" size="icon">
                        <ArrowLeft class="h-5 w-5" />
                    </Button>
                </Link>
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        {{ account.name }}
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Account details and recent transactions
                    </p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card class="lg:col-span-1">
                    <CardContent class="pt-6">
                        <div class="flex flex-col items-center text-center">
                            <div
                                :class="[
                                    'flex h-20 w-20 items-center justify-center rounded-2xl bg-gradient-to-br text-white shadow-xl',
                                    gradientClass,
                                ]"
                            >
                                <CreditCard class="h-10 w-10" />
                            </div>

                            <h2
                                class="mt-4 text-xl font-semibold text-foreground"
                            >
                                {{ account.name }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ account.currency }}
                            </p>

                            <div class="mt-6 w-full space-y-4">
                                <div class="rounded-xl bg-muted/50 p-4">
                                    <p class="text-sm text-muted-foreground">
                                        Current Balance
                                    </p>
                                    <p
                                        class="mt-1 text-3xl font-bold text-foreground"
                                    >
                                        {{
                                            formatCurrency(
                                                balance,
                                                account.currency,
                                            )
                                        }}
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="rounded-xl bg-muted/50 p-4">
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Initial
                                        </p>
                                        <p
                                            class="mt-1 font-semibold text-foreground"
                                        >
                                            {{
                                                formatCurrency(
                                                    initialBalance,
                                                    account.currency,
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div class="rounded-xl bg-muted/50 p-4">
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Change
                                        </p>
                                        <div
                                            class="mt-1 flex items-center gap-1"
                                        >
                                            <TrendingUp
                                                v-if="balanceChange.isPositive"
                                                class="h-4 w-4 text-emerald-600"
                                            />
                                            <TrendingDown
                                                v-else
                                                class="h-4 w-4 text-rose-600"
                                            />
                                            <AmountDisplay
                                                :amount="
                                                    Math.abs(
                                                        balanceChange.value,
                                                    )
                                                "
                                                :currency="account.currency"
                                                :type="
                                                    balanceChange.isPositive
                                                        ? 'income'
                                                        : 'expense'
                                                "
                                                size="sm"
                                                :show-sign="false"
                                                class="font-semibold"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p class="mt-4 text-xs text-muted-foreground">
                                Created {{ formatDate(account.created_at) }}
                            </p>

                            <Button
                                variant="outline"
                                class="mt-6 w-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                                @click="deleteAccount"
                            >
                                <Trash2 class="mr-2 h-4 w-4" />
                                Delete Account
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card class="lg:col-span-2">
                    <CardHeader
                        class="flex flex-row items-center justify-between"
                    >
                        <CardTitle>Recent Transactions</CardTitle>
                        <Link href="/transactions">
                            <Button variant="ghost" size="sm">
                                View All
                            </Button>
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="
                                account.transactions &&
                                account.transactions.length > 0
                            "
                            class="space-y-1"
                        >
                            <TransactionRow
                                v-for="transaction in account.transactions"
                                :key="transaction.id"
                                :transaction="transaction"
                                :show-account="false"
                            />
                        </div>
                        <EmptyState
                            v-else
                            title="No transactions yet"
                            description="Transactions for this account will appear here"
                            :icon="ArrowLeftRight"
                            class="py-8"
                        />
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
