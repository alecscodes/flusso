<script setup lang="ts">
import { TransactionForm } from '@/components/forms';
import TransactionModal from '@/components/modals/TransactionModal.vue';
import { Modal } from '@/components/ui';
import { useTransactionActions } from '@/composables';
import {
    store as transactionStore,
    update as transactionUpdate,
} from '@/routes/transactions';
import type { Account, Category } from '@/types';
import { onBeforeUnmount } from 'vue';

/**
 * The three dialogs behind every transaction list: add (expense, income or
 * transfer), edit and view. Render it once per page.
 */
defineProps<{
    accounts: Account[];
    categories: Category[];
    /** Preselects the account when adding from an account's page. */
    accountId?: number;
}>();

const { creating, editing, viewing } = useTransactionActions();

// The state is shared, so a page change (saving redirects) must not leave a dialog open on the next page.
onBeforeUnmount(() => {
    creating.value = editing.value = viewing.value = null;
});
</script>

<template>
    <div>
        <Modal
            :open="creating !== null"
            title="Add Transaction"
            description="Record an expense, an income or a transfer"
            size="lg"
            @close="creating = null"
        >
            <TransactionForm
                v-if="creating"
                :initial-type="creating"
                :account-id="accountId"
                :accounts="accounts"
                :categories="categories"
                :action="transactionStore.url()"
                @success="creating = null"
                @cancel="creating = null"
            />
        </Modal>

        <Modal
            :open="!!editing"
            title="Edit Transaction"
            description="Update transaction details"
            size="lg"
            @close="editing = null"
        >
            <TransactionForm
                v-if="editing"
                :transaction="editing"
                :accounts="accounts"
                :categories="categories"
                :action="transactionUpdate.url(editing)"
                @success="editing = null"
                @cancel="editing = null"
            />
        </Modal>

        <TransactionModal
            v-if="viewing"
            :transaction="viewing"
            :open="true"
            @close="viewing = null"
        />
    </div>
</template>
