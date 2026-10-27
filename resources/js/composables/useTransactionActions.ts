import { destroy } from '@/routes/transactions';
import type { Transaction } from '@/types';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Shared on purpose: every transaction list talks to the one <TransactionDialogs> on the page.
const creating = ref<Transaction['type'] | null>(null);
const editing = ref<Transaction | null>(null);
const viewing = ref<Transaction | null>(null);

export function useTransactionActions() {
    return {
        creating,
        editing,
        viewing,
        create: (type: Transaction['type'] = 'expense') =>
            (creating.value = type),
        edit: (transaction: Transaction) => (editing.value = transaction),
        view: (transaction: Transaction) => (viewing.value = transaction),
        remove: (transaction: Transaction) => {
            if (confirm('Are you sure you want to delete this transaction?')) {
                router.delete(destroy.url(transaction));
            }
        },
    };
}
