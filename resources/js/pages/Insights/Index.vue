<script setup lang="ts">
import { AmountDisplay, StatCard } from '@/components/data-display';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { index as insightsIndex } from '@/routes/insights';
import type { Insights, InsightsNarrative } from '@/types';
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowDownLeft,
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    Sparkles,
    TrendingUp,
} from '@lucide/vue';

interface Props {
    insights: Insights;
    narrative?: InsightsNarrative;
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

function periodUrl(date: string): string {
    return insightsIndex({ query: { date } }).url;
}

function percent(value: number | null): string {
    if (value === null) {
        return 'new';
    }

    return `${value > 0 ? '+' : ''}${value}%`;
}

function changeClass(amount: number): string {
    if (amount === 0) {
        return 'text-muted-foreground';
    }

    return amount > 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-emerald-600 dark:text-emerald-400';
}

const money = (amount: number, currency = props.insights.currency) =>
    formatCurrency(amount, currency);
</script>

<template>
    <Head title="Insights" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Insights
                </h1>
                <p class="mt-1 text-muted-foreground">
                    {{ formatDate(insights.period.start) }} -
                    {{ formatDate(insights.period.end) }}
                    <span v-if="insights.is_estimate">
                        · totals are an estimate at today's rates
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Link :href="periodUrl(insights.navigation.previous)">
                    <Button variant="outline" size="sm">
                        <ChevronLeft class="h-4 w-4" />
                        Previous
                    </Button>
                </Link>
                <Link
                    v-if="insights.navigation.next"
                    :href="periodUrl(insights.navigation.next)"
                >
                    <Button variant="outline" size="sm">
                        Next
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                </Link>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Sparkles class="h-5 w-5 text-primary" />
                    In short
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Deferred data="narrative">
                    <template #fallback>
                        <div class="animate-pulse space-y-2" aria-busy="true">
                            <div class="h-4 w-full rounded bg-muted" />
                            <div class="h-4 w-5/6 rounded bg-muted" />
                            <div class="h-4 w-2/3 rounded bg-muted" />
                        </div>
                    </template>
                    <p class="text-sm text-foreground">
                        {{ narrative?.text ?? insights.sentence }}
                    </p>
                    <p
                        v-if="narrative?.source === 'ai'"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Written by AI from the figures below.
                    </p>
                </Deferred>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard
                title="Income"
                :value="money(insights.summary.income)"
                :icon="ArrowDownLeft"
                trend="up"
            />
            <StatCard
                title="Expenses"
                :value="money(insights.summary.expenses)"
                :subtitle="`${insights.summary.transaction_count} transactions · previous ${money(insights.previous_summary.expenses)}`"
                :icon="ArrowUpRight"
                trend="down"
            />
            <StatCard
                title="Net"
                :value="money(insights.summary.net)"
                :icon="TrendingUp"
                :trend="insights.summary.net >= 0 ? 'up' : 'down'"
            />
        </div>

        <Card v-if="insights.liquidity" class="border-amber-200">
            <CardContent class="flex items-start gap-2 pt-6">
                <AlertCircle class="mt-0.5 h-5 w-5 text-amber-500" />
                <p class="text-sm font-medium text-foreground">
                    {{ insights.liquidity }}
                </p>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Top categories</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul
                        v-if="insights.top_categories.length > 0"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="category in insights.top_categories"
                            :key="category.category_id"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <div
                                    class="h-3 w-3 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            category.color || '#7c3aed',
                                    }"
                                />
                                <span class="truncate text-sm font-medium">
                                    {{ category.name }}
                                </span>
                            </div>
                            <div class="text-right text-sm">
                                <AmountDisplay
                                    :amount="category.total"
                                    :currency="insights.currency"
                                    type="expense"
                                    size="sm"
                                    :show-sign="false"
                                />
                                <p
                                    class="text-xs"
                                    :class="changeClass(category.change_amount)"
                                >
                                    {{ percent(category.change_percent) }}
                                    ({{ money(category.change_amount) }}) vs
                                    last period
                                </p>
                            </div>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        title="No spending"
                        description="No categorised expenses in this period"
                        class="py-8"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Higher than usual</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul
                        v-if="insights.unusual_categories.length > 0"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="category in insights.unusual_categories"
                            :key="category.category_id"
                            class="flex items-center justify-between gap-3 py-2 text-sm"
                        >
                            <span class="truncate font-medium">
                                {{ category.name }}
                            </span>
                            <span class="text-right text-muted-foreground">
                                {{ money(category.total) }} · usually
                                {{ money(category.average) }} ({{
                                    category.ratio
                                }}x)
                            </span>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        title="Nothing unusual"
                        description="No category is far above its recent average"
                        class="py-8"
                    />
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Biggest expenses</CardTitle>
            </CardHeader>
            <CardContent>
                <ul
                    v-if="insights.biggest_expenses.length > 0"
                    class="divide-y divide-border"
                >
                    <li
                        v-for="expense in insights.biggest_expenses"
                        :key="expense.id"
                        class="flex items-center justify-between gap-3 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{
                                    expense.description ||
                                    expense.category ||
                                    'Expense'
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDate(expense.date) }}
                                <span v-if="expense.category">
                                    · {{ expense.category }}
                                </span>
                            </p>
                        </div>
                        <AmountDisplay
                            :amount="expense.amount"
                            :currency="expense.currency"
                            type="expense"
                            size="sm"
                            :show-sign="false"
                        />
                    </li>
                </ul>
                <EmptyState
                    v-else
                    title="No expenses"
                    description="Nothing was spent in this period"
                    class="py-8"
                />
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>
                        Payments, next {{ insights.payments.days }} days
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="insights.payments.overdue.length > 0">
                        <p class="mb-1 text-sm font-medium text-destructive">
                            Overdue ·
                            {{ money(insights.payments.overdue_total) }}
                        </p>
                        <ul class="space-y-1 text-sm">
                            <li
                                v-for="payment in insights.payments.overdue"
                                :key="payment.id"
                                class="flex justify-between gap-2"
                            >
                                <span class="truncate">
                                    {{ payment.name }} ·
                                    {{ formatDate(payment.due_date) }}
                                </span>
                                <span>
                                    {{
                                        money(payment.amount, payment.currency)
                                    }}
                                </span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="insights.payments.upcoming.length > 0">
                        <p
                            class="mb-1 text-sm font-medium text-muted-foreground"
                        >
                            Coming up ·
                            {{ money(insights.payments.upcoming_total) }}
                        </p>
                        <ul class="space-y-1 text-sm">
                            <li
                                v-for="payment in insights.payments.upcoming"
                                :key="payment.id"
                                class="flex justify-between gap-2"
                            >
                                <span class="truncate">
                                    {{ payment.name }} ·
                                    {{ formatDate(payment.due_date) }}
                                </span>
                                <span>
                                    {{
                                        money(payment.amount, payment.currency)
                                    }}
                                </span>
                            </li>
                        </ul>
                    </div>
                    <EmptyState
                        v-if="
                            insights.payments.overdue.length === 0 &&
                            insights.payments.upcoming.length === 0
                        "
                        title="No payments due"
                        description="You're all caught up!"
                        class="py-8"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Open with people</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul
                        v-if="insights.people.length > 0"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="(person, index) in insights.people"
                            :key="`${person.name}-${person.currency}-${index}`"
                            class="flex justify-between gap-2 py-2 text-sm"
                        >
                            <span class="truncate font-medium">
                                {{ person.name }}
                            </span>
                            <span
                                :class="
                                    person.balance > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-rose-600 dark:text-rose-400'
                                "
                            >
                                {{
                                    person.balance > 0 ? 'owes you' : 'you owe'
                                }}
                                {{
                                    money(
                                        Math.abs(person.balance),
                                        person.currency,
                                    )
                                }}
                            </span>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        title="All settled"
                        description="Nobody owes you anything and you owe nobody"
                        class="py-8"
                    />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
