<script setup lang="ts">
import { AccountCard } from '@/components/data-display';
import { AccountForm } from '@/components/forms';
import { Button, EmptyState, Modal } from '@/components/ui';
import { useCurrency } from '@/composables';
import { AppLayout } from '@/layouts';
import type { Account } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Plus, Wallet } from 'lucide-vue-next';
import { ref } from 'vue';

interface Props {
    accounts: Account[];
    availableBalance: number;
    totalBalance: number;
    savingsBalance: number;
    primaryCurrency: string;
}

defineProps<Props>();

const { formatCurrency } = useCurrency();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingAccount = ref<Account | null>(null);

function openEditModal(account: Account) {
    editingAccount.value = account;
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingAccount.value = null;
}

function handleCreateSuccess() {
    showCreateModal.value = false;
}

function handleEditSuccess() {
    closeEditModal();
}

function deleteAccount(account: Account) {
    if (confirm(`Are you sure you want to delete "${account.name}"?`)) {
        router.delete(`/accounts/${account.id}`);
    }
}
</script>

<template>
    <Head title="Accounts" />

    <AppLayout>
        <div class="space-y-8">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Accounts
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Manage your bank accounts, wallets, and credit cards
                    </p>
                </div>
                <Button @click="showCreateModal = true">
                    <Plus class="h-4 w-4" />
                    Add Account
                </Button>
            </div>

            <div v-if="accounts.length > 0" class="space-y-4">
                <div
                    class="rounded-2xl border border-border bg-gradient-to-r from-primary/5 to-accent/5 p-6"
                >
                    <p class="text-sm font-medium text-muted-foreground">
                        Available Balance
                    </p>
                    <p
                        class="mt-1 text-4xl font-bold tracking-tight text-foreground"
                    >
                        {{ formatCurrency(availableBalance, primaryCurrency) }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Across
                        {{ accounts.filter((a) => !a.is_savings).length }}
                        regular account{{
                            accounts.filter((a) => !a.is_savings).length !== 1
                                ? 's'
                                : ''
                        }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        class="rounded-xl border border-border bg-muted/30 p-4"
                    >
                        <p class="text-sm font-medium text-muted-foreground">
                            Total Balance (including savings)
                        </p>
                        <p
                            class="mt-1 text-2xl font-bold tracking-tight text-foreground"
                        >
                            {{ formatCurrency(totalBalance, primaryCurrency) }}
                        </p>
                    </div>

                    <div
                        v-if="savingsBalance > 0"
                        class="rounded-xl border border-border bg-green-50 p-4 dark:bg-green-950/20"
                    >
                        <p
                            class="text-sm font-medium text-green-700 dark:text-green-300"
                        >
                            Savings Balance
                        </p>
                        <p
                            class="mt-1 text-2xl font-bold tracking-tight text-green-700 dark:text-green-300"
                        >
                            {{
                                formatCurrency(savingsBalance, primaryCurrency)
                            }}
                        </p>
                        <p
                            class="mt-1 text-xs text-green-600 dark:text-green-400"
                        >
                            Across
                            {{ accounts.filter((a) => a.is_savings).length }}
                            savings account{{
                                accounts.filter((a) => a.is_savings).length !==
                                1
                                    ? 's'
                                    : ''
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="accounts.length > 0"
                class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"
            >
                <AccountCard
                    v-for="account in accounts"
                    :key="account.id"
                    :account="account"
                    @edit="openEditModal"
                    @delete="deleteAccount"
                    @click="router.visit(`/accounts/${account.id}`)"
                />
            </div>

            <EmptyState
                v-else
                title="No accounts yet"
                description="Create your first account to start tracking your finances"
                :icon="Wallet"
            >
                <template #action>
                    <Button @click="showCreateModal = true">
                        <Plus class="h-4 w-4" />
                        Add Your First Account
                    </Button>
                </template>
            </EmptyState>
        </div>

        <Modal
            :open="showCreateModal"
            title="Create Account"
            description="Add a new bank account, wallet, or credit card"
            @close="showCreateModal = false"
        >
            <AccountForm
                action="/accounts"
                method="post"
                @success="handleCreateSuccess"
                @cancel="showCreateModal = false"
            />
        </Modal>

        <Modal
            :open="showEditModal"
            title="Edit Account"
            description="Update your account details"
            @close="closeEditModal"
        >
            <AccountForm
                v-if="editingAccount"
                :account="editingAccount"
                :action="`/accounts/${editingAccount.id}`"
                method="patch"
                @success="handleEditSuccess"
                @cancel="closeEditModal"
            />
        </Modal>
    </AppLayout>
</template>
