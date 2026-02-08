<script setup lang="ts">
import { AccountCard } from '@/components/data-display';
import { AccountForm } from '@/components/forms';
import { Button, EmptyState, Modal } from '@/components/ui';
import { useCurrency } from '@/composables';
import { AppLayout } from '@/layouts';
import type { Account } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Plus, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    accounts: Account[];
}

const props = defineProps<Props>();

const { formatCurrency } = useCurrency();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingAccount = ref<Account | null>(null);

const totalBalance = computed(() => {
    return props.accounts.reduce((sum, account) => {
        return sum + parseFloat(account.balance);
    }, 0);
});

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

            <div
                v-if="accounts.length > 0"
                class="rounded-2xl border border-border bg-gradient-to-r from-primary/5 to-accent/5 p-6"
            >
                <p class="text-sm font-medium text-muted-foreground">
                    Total Balance
                </p>
                <p
                    class="mt-1 text-4xl font-bold tracking-tight text-foreground"
                >
                    {{
                        formatCurrency(
                            totalBalance,
                            accounts[0]?.currency || 'EUR',
                        )
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Across {{ accounts.length }} account{{
                        accounts.length !== 1 ? 's' : ''
                    }}
                </p>
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
