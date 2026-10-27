<script setup lang="ts">
import { AmountDisplay, CategoryBadge } from '@/components/data-display';
import { Button, Modal } from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { download as downloadTransactionFile } from '@/routes/transactions/files';
import type { Transaction } from '@/types';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Calendar,
    CreditCard,
    Download,
    FileText,
    Tag,
} from '@lucide/vue';
import { computed, type Component } from 'vue';

interface Props {
    transaction: Transaction;
    open: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    close: [];
}>();

const { formatDate } = useDate();
const { formatCurrency } = useCurrency();

const types: Record<
    string,
    { label: string; icon: Component; colors: string }
> = {
    income: {
        label: 'Income',
        icon: ArrowDownLeft,
        colors: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    expense: {
        label: 'Expense',
        icon: ArrowUpRight,
        colors: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    },
    transfer: {
        label: 'Transfer',
        icon: ArrowLeftRight,
        colors: 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400',
    },
};

const type = computed(
    () =>
        types[props.transaction.type] ?? {
            label: 'Transaction',
            icon: ArrowUpRight,
            colors: 'border-border bg-muted text-muted-foreground',
        },
);

const isTransfer = computed(() => props.transaction.type === 'transfer');

const formatFileSize = (bytes: number): string => {
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 Bytes';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const r = bytes / Math.pow(1024, i);
    return Math.round(r * 100) / 100 + ' ' + sizes[i];
};

const downloadFile = (file: { id: number }) => {
    const link = document.createElement('a');
    link.href = downloadTransactionFile.url({
        transaction: props.transaction.id,
        file: file.id,
    });
    link.target = '_blank';
    link.click();
};
</script>

<template>
    <Modal
        :open="open"
        :title="type.label"
        description="Transaction details"
        size="lg"
        @close="emit('close')"
    >
        <div class="mb-6 text-center">
            <div class="mb-3">
                <AmountDisplay
                    :amount="transaction.amount"
                    :currency="transaction.currency"
                    :type="transaction.type"
                    size="xl"
                />
            </div>
            <p
                class="text-lg"
                :class="
                    transaction.description
                        ? 'font-medium text-foreground'
                        : 'text-muted-foreground'
                "
            >
                {{ transaction.description || 'No description' }}
            </p>
            <span
                :class="[
                    'mt-3 inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium',
                    type.colors,
                ]"
            >
                <component :is="type.icon" class="h-4 w-4" />
                {{ type.label }}
            </span>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <template v-if="isTransfer">
                <div
                    v-if="transaction.account"
                    class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
                >
                    <span
                        class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                    >
                        <ArrowUpRight class="h-4 w-4 text-amber-500" />
                        From
                    </span>
                    <span class="text-sm text-foreground">
                        {{ transaction.account.name }}
                    </span>
                </div>
                <div
                    v-if="transaction.to_account"
                    class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
                >
                    <span
                        class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                    >
                        <ArrowDownLeft class="h-4 w-4 text-emerald-500" />
                        To
                    </span>
                    <span class="text-sm text-foreground">
                        {{ transaction.to_account.name }}
                    </span>
                </div>
                <div
                    v-if="
                        transaction.to_amount &&
                        transaction.to_account &&
                        transaction.to_account.currency !== transaction.currency
                    "
                    class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
                >
                    <span
                        class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                    >
                        <ArrowLeftRight class="h-4 w-4" />
                        Received
                    </span>
                    <span class="text-sm text-foreground">
                        {{
                            formatCurrency(
                                transaction.to_amount,
                                transaction.to_account.currency,
                            )
                        }}
                    </span>
                </div>
            </template>
            <div
                v-else-if="transaction.account"
                class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
            >
                <span
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <CreditCard class="h-4 w-4" />
                    Account
                </span>
                <span class="text-sm text-foreground">
                    {{ transaction.account.name }}
                </span>
            </div>

            <div
                class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
            >
                <span
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <Calendar class="h-4 w-4" />
                    Date
                </span>
                <span class="text-sm text-foreground">
                    {{ formatDate(transaction.date) }}
                </span>
            </div>

            <div
                v-if="!isTransfer && transaction.category"
                class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
            >
                <span
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <Tag class="h-4 w-4" />
                    Category
                </span>
                <CategoryBadge :category="transaction.category" />
            </div>
        </div>

        <div
            v-if="transaction.files && transaction.files.length > 0"
            class="mt-6"
        >
            <div class="mb-3 flex items-center gap-2">
                <FileText class="h-5 w-5 text-muted-foreground" />
                <h4 class="text-lg font-semibold text-foreground">
                    Attachments
                </h4>
            </div>

            <div class="space-y-2">
                <div
                    v-for="file in transaction.files"
                    :key="file.id"
                    class="flex items-center justify-between gap-3 rounded-lg bg-muted/50 p-3"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <FileText
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-foreground">
                                {{ file.original_filename }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ formatFileSize(file.size) }} •
                                {{ file.mime_type }}
                            </p>
                        </div>
                    </div>

                    <Button
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        @click="downloadFile(file)"
                    >
                        <Download class="h-4 w-4" />
                        <span class="ml-1 hidden sm:inline">Download</span>
                    </Button>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Close</Button>
        </template>
    </Modal>
</template>
