import type { Transaction } from '@/types';
import { computed } from 'vue';

export function useTransferTransaction(transaction: () => Transaction) {
    const isTransferSource = computed(() => {
        const tx = transaction();
        return (
            tx.type === 'transfer' &&
            tx.from_account_id &&
            tx.account_id === tx.from_account_id
        );
    });

    const isTransferDestination = computed(() => {
        const tx = transaction();
        return (
            tx.type === 'transfer' &&
            tx.to_account_id &&
            tx.account_id === tx.to_account_id
        );
    });

    const isTransfer = computed(
        () => isTransferSource.value || isTransferDestination.value,
    );

    const transferDescription = computed(() => {
        const tx = transaction();

        if (!isTransfer.value) return null;

        return isTransferSource.value
            ? `Transfer to ${tx.to_account?.name || 'Unknown'}`
            : `Transfer from ${tx.from_account?.name || 'Unknown'}`;
    });

    const transferAccountInfo = computed(() => {
        const tx = transaction();

        if (isTransferSource.value) {
            return tx.to_account?.name;
        }

        if (isTransferDestination.value) {
            return tx.from_account?.name;
        }

        return null;
    });

    return {
        isTransferSource,
        isTransferDestination,
        isTransfer,
        transferDescription,
        transferAccountInfo,
    };
}
