<script setup lang="ts">
import { AmountDisplay, CategoryBadge } from '@/components/data-display';
import { Button, Card, CardContent } from '@/components/ui';
import { useDate } from '@/composables';
import { AppLayout } from '@/layouts';
import type { Transaction } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Calendar,
    CreditCard,
    Tag,
    Trash2,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    transaction: Transaction;
}

const props = defineProps<Props>();

const { formatDate } = useDate();

const typeIcon = computed(() => {
    switch (props.transaction.type) {
        case 'income':
            return ArrowDownLeft;
        case 'expense':
            return ArrowUpRight;
        case 'transfer':
            return ArrowLeftRight;
        default:
            return ArrowUpRight;
    }
});

const typeLabel = computed(() => {
    switch (props.transaction.type) {
        case 'income':
            return 'Income';
        case 'expense':
            return 'Expense';
        case 'transfer':
            return 'Transfer';
        default:
            return 'Transaction';
    }
});

const typeColors = computed(() => {
    switch (props.transaction.type) {
        case 'income':
            return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
        case 'expense':
            return 'bg-rose-500/10 text-rose-600 dark:text-rose-400';
        case 'transfer':
            return 'bg-blue-500/10 text-blue-600 dark:text-blue-400';
        default:
            return 'bg-muted text-muted-foreground';
    }
});

function deleteTransaction() {
    if (
        confirm(
            'Are you sure you want to delete this transaction? The account balance will be updated as if this transaction never existed.',
        )
    ) {
        router.delete(`/transactions/${props.transaction.id}`);
    }
}
</script>

<template>
    <Head title="Transaction Details" />

    <AppLayout>
        <div class="mx-auto max-w-2xl space-y-6">
            <div class="flex items-center gap-4">
                <Link href="/transactions">
                    <Button variant="ghost" size="icon">
                        <ArrowLeft class="h-5 w-5" />
                    </Button>
                </Link>
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Transaction Details
                    </h1>
                </div>
            </div>

            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-col items-center text-center">
                        <div
                            :class="[
                                'flex h-16 w-16 items-center justify-center rounded-2xl',
                                typeColors,
                            ]"
                        >
                            <component :is="typeIcon" class="h-8 w-8" />
                        </div>

                        <div class="mt-4">
                            <AmountDisplay
                                :amount="transaction.amount"
                                :currency="transaction.currency"
                                :type="transaction.type"
                                size="xl"
                            />
                        </div>

                        <p
                            v-if="transaction.description"
                            class="mt-2 text-lg text-foreground"
                        >
                            {{ transaction.description }}
                        </p>
                        <p v-else class="mt-2 text-lg text-muted-foreground">
                            No description
                        </p>

                        <span
                            :class="[
                                'mt-3 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium',
                                typeColors,
                            ]"
                        >
                            <component :is="typeIcon" class="h-4 w-4" />
                            {{ typeLabel }}
                        </span>
                    </div>

                    <div class="mt-8 space-y-4">
                        <div
                            class="flex items-center justify-between rounded-xl bg-muted/50 p-4"
                        >
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-background p-2">
                                    <Calendar
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                </div>
                                <span class="text-sm text-muted-foreground"
                                    >Date</span
                                >
                            </div>
                            <span class="font-medium text-foreground">
                                {{ formatDate(transaction.date) }}
                            </span>
                        </div>

                        <div
                            v-if="transaction.account"
                            class="flex items-center justify-between rounded-xl bg-muted/50 p-4"
                        >
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-background p-2">
                                    <CreditCard
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                </div>
                                <span class="text-sm text-muted-foreground"
                                    >Account</span
                                >
                            </div>
                            <span class="font-medium text-foreground">
                                {{ transaction.account.name }}
                            </span>
                        </div>

                        <div
                            v-if="transaction.category"
                            class="flex items-center justify-between rounded-xl bg-muted/50 p-4"
                        >
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-background p-2">
                                    <Tag
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                </div>
                                <span class="text-sm text-muted-foreground"
                                    >Category</span
                                >
                            </div>
                            <CategoryBadge :category="transaction.category" />
                        </div>
                    </div>

                    <div class="mt-6 flex flex-wrap justify-center gap-3">
                        <Link href="/transactions">
                            <Button variant="outline">
                                Back to Transactions
                            </Button>
                        </Link>
                        <Button
                            variant="outline"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            @click="deleteTransaction"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            Delete Transaction
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
