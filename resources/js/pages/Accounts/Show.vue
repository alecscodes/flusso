<script setup lang="ts">
import { AmountDisplay, TransactionRow } from '@/components/data-display';
import TransactionDialogs from '@/components/modals/TransactionDialogs.vue';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
} from '@/components/ui';
import { useCurrency, useDate, useTransactionActions } from '@/composables';
import {
    index as accountsIndex,
    destroy as destroyAccount,
} from '@/routes/accounts';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Account, Category } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    CreditCard,
    Plus,
    Trash2,
    TrendingDown,
    TrendingUp,
} from '@lucide/vue';
import { computed } from 'vue';

interface Props {
    account: Account;
    accounts: Account[];
    categories: Category[];
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

const { create, edit, view, remove } = useTransactionActions();

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
            `Archive "${props.account.name}"? Its history stays, but it disappears from your accounts and its planned payments stop.`,
        )
    ) {
        router.delete(destroyAccount.url(props.account));
    }
}
</script>

<template>
    <Head :title="account.name" />

    <div class="space-y-8">
        <TransactionDialogs
            :accounts="accounts"
            :categories="categories"
            :account-id="account.id"
        />

        <div class="flex items-center gap-4">
            <Link :href="accountsIndex().url" class="shrink-0">
                <Button variant="ghost" size="icon">
                    <ArrowLeft class="h-5 w-5" />
                </Button>
            </Link>
            <div class="min-w-0">
                <h1
                    class="truncate text-3xl font-bold tracking-tight text-foreground"
                >
                    {{ account.name }}
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Account details and recent transactions
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card class="min-w-0 lg:col-span-1">
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

                        <h2 class="mt-4 text-xl font-semibold text-foreground">
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
                                    class="mt-1 text-2xl font-bold break-words text-foreground tabular-nums sm:text-3xl"
                                >
                                    {{
                                        formatCurrency(
                                            balance,
                                            account.currency,
                                        )
                                    }}
                                </p>
                            </div>

                            <div
                                class="grid grid-cols-2 gap-3 lg:grid-cols-1 2xl:grid-cols-2"
                            >
                                <div class="rounded-xl bg-muted/50 p-3 sm:p-4">
                                    <p class="text-xs text-muted-foreground">
                                        Initial
                                    </p>
                                    <p
                                        class="mt-1 text-sm font-semibold text-foreground tabular-nums sm:text-base"
                                    >
                                        {{
                                            formatCurrency(
                                                initialBalance,
                                                account.currency,
                                            )
                                        }}
                                    </p>
                                </div>
                                <div class="rounded-xl bg-muted/50 p-3 sm:p-4">
                                    <p class="text-xs text-muted-foreground">
                                        Change
                                    </p>
                                    <div
                                        class="mt-1 flex items-center justify-center gap-1"
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
                                                Math.abs(balanceChange.value)
                                            "
                                            :currency="account.currency"
                                            :type="
                                                balanceChange.isPositive
                                                    ? 'income'
                                                    : 'expense'
                                            "
                                            size="sm"
                                            :show-sign="false"
                                            class="justify-center font-semibold"
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
                            Archive Account
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="min-w-0 lg:col-span-2">
                <CardHeader
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <CardTitle>Recent Transactions</CardTitle>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="create('transfer')"
                        >
                            <ArrowLeftRight class="mr-2 h-4 w-4" />
                            Transfer
                        </Button>
                        <Button variant="ghost" size="sm" @click="create()">
                            <Plus class="mr-2 h-4 w-4" />
                            Add Transaction
                        </Button>
                        <Link :href="transactionsIndex().url">
                            <Button variant="ghost" size="sm">
                                View All
                            </Button>
                        </Link>
                    </div>
                </CardHeader>
                <CardContent class="px-2 sm:px-6">
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
                            :context-account-id="account.id"
                            @view="view"
                            @edit="edit"
                            @delete="remove"
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
</template>
