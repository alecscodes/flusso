<script setup lang="ts">
import { TransactionRow } from '@/components/data-display';
import { TransactionForm, TransferForm } from '@/components/forms';
import {
    Button,
    Card,
    CardContent,
    EmptyState,
    Input,
    Modal,
    SearchableSelect,
} from '@/components/ui';
import { AppLayout } from '@/layouts';
import { debounce } from '@/lib/utils';
import type { Account, Category, PaginatedData, Transaction } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, Filter, Plus, Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const DEBOUNCE_MS = 350;

interface Props {
    transactions: PaginatedData<Transaction>;
    accounts: Account[];
    categories: Category[];
}

const props = defineProps<Props>();

const page = usePage();

function getFiltersFromUrl(): {
    search: string;
    account_id: string;
    category_id: string;
    type: string;
} {
    const url = new URL(page.url, window.location.origin);
    const q = url.searchParams;
    return {
        search: q.get('search') ?? '',
        account_id: q.get('account_id') ?? '',
        category_id: q.get('category_id') ?? '',
        type: q.get('type') ?? '',
    };
}

const showCreateModal = ref(false);
const showTransferModal = ref(false);
const showEditModal = ref(false);
const editingTransaction = ref<Transaction | null>(null);
const showFilters = ref(false);

const filters = ref(getFiltersFromUrl());

watch(
    () => page.url,
    () => {
        filters.value = getFiltersFromUrl();
    },
    { immediate: false },
);

function openEditModal(transaction: Transaction) {
    editingTransaction.value = transaction;
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingTransaction.value = null;
}

function handleCreateSuccess() {
    showCreateModal.value = false;
}

function handleTransferSuccess() {
    showTransferModal.value = false;
}

function handleEditSuccess() {
    closeEditModal();
}

function deleteTransaction(transaction: Transaction) {
    if (confirm('Are you sure you want to delete this transaction?')) {
        router.delete(`/transactions/${transaction.id}`);
    }
}

function applyFilters() {
    router.get('/transactions', filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
}

const debouncedApplyFilters = debounce(applyFilters, DEBOUNCE_MS);

function clearFilters() {
    filters.value = {
        search: '',
        account_id: '',
        category_id: '',
        type: '',
    };
    applyFilters();
}

const hasActiveFilters = computed(() => {
    return Object.values(filters.value).some((v) => v !== '');
});

const accountFilterOptions = computed(() => [
    { value: '', label: 'All accounts' },
    ...props.accounts.map((a) => ({ value: a.id.toString(), label: a.name })),
]);

const categoryFilterOptions = computed(() => [
    { value: '', label: 'All categories' },
    ...props.categories.map((c) => ({
        value: c.id.toString(),
        label: c.name,
    })),
]);

const typeFilterOptions = [
    { value: '', label: 'All types' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
    { value: 'transfer', label: 'Transfer' },
];
</script>

<template>
    <Head title="Transactions" />

    <AppLayout>
        <div class="space-y-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Transactions
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Track all your income, expenses, and transfers
                    </p>
                </div>
                <div class="flex gap-3">
                    <Button variant="outline" @click="showTransferModal = true">
                        <ArrowLeftRight class="h-4 w-4" />
                        Transfer
                    </Button>
                    <Button @click="showCreateModal = true">
                        <Plus class="h-4 w-4" />
                        Add Transaction
                    </Button>
                </div>
            </div>

            <Card>
                <CardContent class="pt-6">
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center"
                    >
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
                        class="mt-4 grid gap-4 rounded-xl border border-border bg-muted/30 p-4 sm:grid-cols-3"
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
                                        filters.account_id = v as string;
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
                                        filters.category_id = v as string;
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
                                        filters.type = v as string;
                                        applyFilters();
                                    }
                                "
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
                            @edit="openEditModal"
                            @delete="deleteTransaction"
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
                            <Button
                                v-if="!hasActiveFilters"
                                @click="showCreateModal = true"
                            >
                                <Plus class="h-4 w-4" />
                                Add Transaction
                            </Button>
                            <Button
                                v-else
                                variant="outline"
                                @click="clearFilters"
                            >
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
                            <Link
                                v-if="transactions.prev_page_url"
                                :href="transactions.prev_page_url"
                                preserve-scroll
                            >
                                <Button variant="outline" size="sm">
                                    Previous
                                </Button>
                            </Link>
                            <Link
                                v-if="transactions.next_page_url"
                                :href="transactions.next_page_url"
                                preserve-scroll
                            >
                                <Button variant="outline" size="sm">
                                    Next
                                </Button>
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Modal
            :open="showCreateModal"
            title="Add Transaction"
            description="Record a new income or expense"
            size="lg"
            @close="showCreateModal = false"
        >
            <TransactionForm
                :accounts="accounts"
                :categories="categories"
                action="/transactions"
                method="post"
                @success="handleCreateSuccess"
                @cancel="showCreateModal = false"
            />
        </Modal>

        <Modal
            :open="showTransferModal"
            title="Transfer Money"
            description="Move money between your accounts"
            size="lg"
            @close="showTransferModal = false"
        >
            <TransferForm
                :accounts="accounts"
                action="/transactions/transfer"
                @success="handleTransferSuccess"
                @cancel="showTransferModal = false"
            />
        </Modal>

        <Modal
            :open="showEditModal"
            title="Edit Transaction"
            description="Update transaction details"
            size="lg"
            @close="closeEditModal"
        >
            <TransactionForm
                v-if="editingTransaction"
                :transaction="editingTransaction"
                :accounts="accounts"
                :categories="categories"
                :action="`/transactions/${editingTransaction.id}`"
                method="patch"
                @success="handleEditSuccess"
                @cancel="closeEditModal"
            />
        </Modal>
    </AppLayout>
</template>
