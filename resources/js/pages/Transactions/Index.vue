<script setup lang="ts">
import { AmountDisplay, TransactionRow } from '@/components/data-display';
import TransactionDialogs from '@/components/modals/TransactionDialogs.vue';
import {
    Button,
    Card,
    CardContent,
    EmptyState,
    Input,
    SearchableSelect,
} from '@/components/ui';
import { useTransactionActions } from '@/composables';
import { debounce } from '@/lib/utils';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Account, Category, PaginatedData, Transaction } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, Filter, Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

interface Props {
    transactions: PaginatedData<Transaction>;
    accounts: Account[];
    categories: Category[];
    categorySpending: Array<{
        category: Category;
        total: number;
        count: number;
    }>;
    hasDateFilters: boolean;
    primaryCurrency: string;
}

const props = defineProps<Props>();
const page = usePage();

// Constants
const DEBOUNCE_MS = 350;

// State
const showFilters = ref(false);
const { create, edit, view, remove } = useTransactionActions();

// Filters
const filters = ref(getFiltersFromUrl());

// Computed
const hasActiveFilters = computed(() =>
    Object.values(filters.value).some((v) => v !== ''),
);

const totalCategorySpending = computed(() =>
    props.categorySpending.reduce((sum, item) => sum + item.total, 0),
);

const accountFilterOptions = computed(() => [
    { value: '', label: 'All accounts' },
    ...props.accounts.map((a) => ({ value: a.id.toString(), label: a.name })),
]);

const categoryFilterOptions = computed(() => [
    { value: '', label: 'All categories' },
    ...props.categories.map((c) => ({ value: c.id.toString(), label: c.name })),
]);

const typeFilterOptions = [
    { value: '', label: 'All types' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
    { value: 'transfer', label: 'Transfer' },
];

// Functions
function getFiltersFromUrl() {
    const url = new URL(page.url, window.location.origin);
    const q = url.searchParams;
    return {
        search: q.get('search') ?? '',
        account_id: q.get('account_id') ?? '',
        category_id: q.get('category_id') ?? '',
        type: q.get('type') ?? '',
        date_start: q.get('date_start') ?? '',
        date_end: q.get('date_end') ?? '',
    };
}

function applyFilters() {
    router.get(transactionsIndex.url(), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    filters.value = {
        search: '',
        account_id: '',
        category_id: '',
        type: '',
        date_start: '',
        date_end: '',
    };
    applyFilters();
}

// Watchers
watch(
    () => page.url,
    () => {
        filters.value = getFiltersFromUrl();
    },
    { immediate: false },
);

// Debounced filter application
const debouncedApplyFilters = debounce(applyFilters, DEBOUNCE_MS);
</script>

<template>
    <Head title="Transactions" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Transactions
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Track all your income, expenses, and transfers
                </p>
            </div>
            <div class="flex justify-between gap-3">
                <Button variant="outline" @click="create('transfer')">
                    <ArrowLeftRight class="h-4 w-4" />
                    Transfer
                </Button>
                <Button @click="create()">
                    <Plus class="h-4 w-4" />
                    Add Transaction
                </Button>
            </div>
        </div>

        <Card>
            <CardContent class="pt-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:gap-4">
                    <div class="relative flex-1">
                        <Search
                            class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="filters.search"
                            placeholder="Search transactions..."
                            class="pl-10"
                            @update:model-value="debouncedApplyFilters"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            @click="showFilters = !showFilters"
                        >
                            <Filter class="h-4 w-4" />
                            Filters
                            <span
                                v-if="hasActiveFilters"
                                class="ml-1 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground"
                            >
                                !
                            </span>
                        </Button>
                        <Button
                            v-if="hasActiveFilters"
                            variant="ghost"
                            size="icon"
                            @click="clearFilters"
                        >
                            <X class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <div
                    v-if="showFilters"
                    class="mt-4 grid gap-4 rounded-xl border border-border bg-muted/30 p-4 sm:grid-cols-2 2xl:grid-cols-4"
                >
                    <div class="space-y-2">
                        <label class="text-sm font-medium text-foreground"
                            >Account</label
                        >
                        <SearchableSelect
                            :model-value="filters.account_id"
                            placeholder="All accounts"
                            search-placeholder="Search account..."
                            :options="accountFilterOptions"
                            @update:model-value="
                                (v) => {
                                    filters.account_id = String(v);
                                    applyFilters();
                                }
                            "
                        />
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-medium text-foreground"
                            >Category</label
                        >
                        <SearchableSelect
                            :model-value="filters.category_id"
                            placeholder="All categories"
                            search-placeholder="Search category..."
                            :options="categoryFilterOptions"
                            @update:model-value="
                                (v) => {
                                    filters.category_id = String(v);
                                    applyFilters();
                                }
                            "
                        />
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-medium text-foreground"
                            >Type</label
                        >
                        <SearchableSelect
                            :model-value="filters.type"
                            placeholder="All types"
                            search-placeholder="Search..."
                            :options="typeFilterOptions"
                            @update:model-value="
                                (v) => {
                                    filters.type = String(v);
                                    applyFilters();
                                }
                            "
                        />
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-medium text-foreground"
                            >Date Range</label
                        >
                        <div class="flex gap-2">
                            <Input
                                type="date"
                                :model-value="filters.date_start"
                                placeholder="From"
                                @update:model-value="
                                    (v) => {
                                        filters.date_start = String(v);
                                        applyFilters();
                                    }
                                "
                            />
                            <Input
                                type="date"
                                :model-value="filters.date_end"
                                placeholder="To"
                                @update:model-value="
                                    (v) => {
                                        filters.date_end = String(v);
                                        applyFilters();
                                    }
                                "
                            />
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Category Spending Summary -->
        <Card v-if="hasDateFilters && categorySpending.length > 0">
            <CardContent class="pt-6">
                <div class="mb-4">
                    <h3 class="text-lg font-semibold text-foreground">
                        Spending by Category
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        For the selected date period
                    </p>
                </div>
                <div class="space-y-4">
                    <div
                        v-for="item in categorySpending"
                        :key="item.category.id"
                        class="space-y-2"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-3 w-3 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            item.category.color || '#7c3aed',
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
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :style="{
                                    width: `${(item.total / totalCategorySpending) * 100}%`,
                                    backgroundColor:
                                        item.category.color || '#7c3aed',
                                }"
                            />
                        </div>
                    </div>
                </div>
                <div class="mt-4 border-t border-border pt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-foreground">
                            Total Expenses
                        </span>
                        <AmountDisplay
                            :amount="totalCategorySpending"
                            :currency="primaryCurrency"
                            type="expense"
                            size="sm"
                            :show-sign="false"
                        />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="pt-6">
                <div v-if="transactions.data.length > 0" class="space-y-1">
                    <TransactionRow
                        v-for="transaction in transactions.data"
                        :key="transaction.id"
                        :transaction="transaction"
                        @view="view"
                        @edit="edit"
                        @delete="remove"
                    />
                </div>
                <EmptyState
                    v-else
                    title="No transactions found"
                    :description="
                        hasActiveFilters
                            ? 'Try adjusting your filters'
                            : 'Add your first transaction to get started'
                    "
                    :icon="ArrowLeftRight"
                >
                    <template #action>
                        <Button v-if="!hasActiveFilters" @click="create()">
                            <Plus class="h-4 w-4" />
                            Add Transaction
                        </Button>
                        <Button v-else variant="outline" @click="clearFilters">
                            Clear Filters
                        </Button>
                    </template>
                </EmptyState>

                <div
                    v-if="transactions.last_page > 1"
                    class="mt-6 flex items-center justify-between border-t border-border pt-4"
                >
                    <p class="text-sm text-muted-foreground">
                        Showing {{ transactions.from }} to
                        {{ transactions.to }} of
                        {{ transactions.total }} transactions
                    </p>
                    <div class="flex gap-2">
                        <Button
                            v-if="transactions.prev_page_url"
                            :as="Link"
                            :href="transactions.prev_page_url"
                            preserve-scroll
                            variant="outline"
                            size="sm"
                        >
                            Previous
                        </Button>
                        <Button
                            v-if="transactions.next_page_url"
                            :as="Link"
                            :href="transactions.next_page_url"
                            preserve-scroll
                            variant="outline"
                            size="sm"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>

    <TransactionDialogs :accounts="accounts" :categories="categories" />
</template>
