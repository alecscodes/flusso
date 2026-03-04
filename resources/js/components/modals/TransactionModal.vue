<script setup lang="ts">
import { AmountDisplay, CategoryBadge } from '@/components/data-display';
import { Button } from '@/components/ui';
import { useDate } from '@/composables';
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
    X,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    transaction: Transaction;
    open: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    close: [];
}>();

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
            return 'text-green-600 bg-green-50 border-green-200 dark:text-green-400 dark:bg-green-950 dark:border-green-800';
        case 'expense':
            return 'text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-950 dark:border-rose-800';
        case 'transfer':
            return 'text-blue-600 bg-blue-50 border-blue-200 dark:text-blue-400 dark:bg-blue-950 dark:border-blue-800';
        default:
            return 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-gray-950 dark:border-gray-800';
    }
});

const formatFileSize = (bytes: number): string => {
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 Bytes';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const r = bytes / Math.pow(1024, i);
    return Math.round(r * 100) / 100 + ' ' + sizes[i];
};

const downloadFile = (file: any) => {
    const link = document.createElement('a');
    link.href = `/transactions/${props.transaction.id}/files/${file.id}/download`;
    link.target = '_blank';
    link.click();
};
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm"
                @click="emit('close')"
            >
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="open"
                        class="flex min-h-full items-center justify-center p-4"
                        @click.stop
                    >
                        <div
                            class="relative w-full max-w-lg transform overflow-hidden rounded-2xl bg-white shadow-xl transition-all sm:max-w-xl md:max-w-2xl dark:bg-gray-900"
                        >
                            <!-- Header -->
                            <div
                                class="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-900"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        :class="[
                                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border',
                                            typeColors,
                                        ]"
                                    >
                                        <component
                                            :is="typeIcon"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <div>
                                        <h3
                                            class="text-lg font-semibold text-gray-900 dark:text-white"
                                        >
                                            {{ typeLabel }}
                                        </h3>
                                        <p
                                            class="text-sm text-gray-500 dark:text-gray-400"
                                        >
                                            Transaction Details
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                                    @click="emit('close')"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>

                            <!-- Content -->
                            <div class="px-6 py-4 dark:bg-gray-900">
                                <!-- Amount and Description -->
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
                                        v-if="transaction.description"
                                        class="text-lg font-medium text-gray-900 dark:text-white"
                                    >
                                        {{ transaction.description }}
                                    </p>
                                    <p
                                        v-else
                                        class="text-lg text-gray-500 dark:text-gray-400"
                                    >
                                        No description
                                    </p>
                                    <span
                                        :class="[
                                            'mt-3 inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium',
                                            typeColors,
                                        ]"
                                    >
                                        <component
                                            :is="typeIcon"
                                            class="h-4 w-4"
                                        />
                                        {{ typeLabel }}
                                    </span>
                                </div>

                                <!-- Transaction Info Grid -->
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <!-- Transfer specific fields -->
                                    <template
                                        v-if="transaction.type === 'transfer'"
                                    >
                                        <!-- From | To row -->
                                        <div class="contents">
                                            <div
                                                v-if="transaction.from_account"
                                                class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                            >
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <div
                                                        class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                    >
                                                        <ArrowUpRight
                                                            class="h-4 w-4 text-amber-500"
                                                        />
                                                    </div>
                                                    <span
                                                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                        >From</span
                                                    >
                                                </div>
                                                <span
                                                    class="text-sm text-gray-900 dark:text-white"
                                                >
                                                    {{
                                                        transaction.from_account
                                                            .name
                                                    }}
                                                </span>
                                            </div>

                                            <div
                                                v-if="transaction.to_account"
                                                class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                            >
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <div
                                                        class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                    >
                                                        <ArrowDownLeft
                                                            class="h-4 w-4 text-emerald-500"
                                                        />
                                                    </div>
                                                    <span
                                                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                        >To</span
                                                    >
                                                </div>
                                                <span
                                                    class="text-sm text-gray-900 dark:text-white"
                                                >
                                                    {{
                                                        transaction.to_account
                                                            .name
                                                    }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Date | Category row -->
                                        <div class="contents">
                                            <div
                                                class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                            >
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <div
                                                        class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                    >
                                                        <Calendar
                                                            class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                                        />
                                                    </div>
                                                    <span
                                                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                        >Date</span
                                                    >
                                                </div>
                                                <span
                                                    class="text-sm text-gray-900 dark:text-white"
                                                >
                                                    {{
                                                        formatDate(
                                                            transaction.date,
                                                        )
                                                    }}
                                                </span>
                                            </div>

                                            <div
                                                class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                            >
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <div
                                                        class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                    >
                                                        <Tag
                                                            class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                                        />
                                                    </div>
                                                    <span
                                                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                        >Category</span
                                                    >
                                                </div>
                                                <span
                                                    class="text-sm text-gray-900 dark:text-white"
                                                >
                                                    Transfer
                                                </span>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Regular transaction fields -->
                                    <template v-else>
                                        <div
                                            v-if="transaction.account"
                                            class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                >
                                                    <CreditCard
                                                        class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                                    />
                                                </div>
                                                <span
                                                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >Account</span
                                                >
                                            </div>
                                            <span
                                                class="text-sm text-gray-900 dark:text-white"
                                            >
                                                {{ transaction.account.name }}
                                            </span>
                                        </div>

                                        <div
                                            class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                >
                                                    <Calendar
                                                        class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                                    />
                                                </div>
                                                <span
                                                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >Date</span
                                                >
                                            </div>
                                            <span
                                                class="text-sm text-gray-900 dark:text-white"
                                            >
                                                {{
                                                    formatDate(transaction.date)
                                                }}
                                            </span>
                                        </div>

                                        <div
                                            v-if="transaction.category"
                                            class="flex items-center justify-between rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="rounded-lg bg-white p-2 dark:bg-gray-700"
                                                >
                                                    <Tag
                                                        class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                                    />
                                                </div>
                                                <span
                                                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >Category</span
                                                >
                                            </div>
                                            <CategoryBadge
                                                :category="transaction.category"
                                            />
                                        </div>
                                    </template>
                                </div>

                                <!-- File Attachments -->
                                <div
                                    v-if="
                                        transaction.files &&
                                        transaction.files.length > 0
                                    "
                                    class="mt-6"
                                >
                                    <div class="mb-3 flex items-center gap-2">
                                        <FileText
                                            class="h-5 w-5 text-gray-500 dark:text-gray-400"
                                        />
                                        <h4
                                            class="text-lg font-semibold text-gray-900 dark:text-white"
                                        >
                                            Attachments
                                        </h4>
                                    </div>

                                    <div class="space-y-2">
                                        <div
                                            v-for="file in transaction.files"
                                            :key="file.id"
                                            class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-gray-800"
                                        >
                                            <div
                                                class="flex min-w-0 flex-1 items-center gap-3"
                                            >
                                                <FileText
                                                    class="h-4 w-4 shrink-0 text-gray-500 dark:text-gray-400"
                                                />
                                                <div class="min-w-0 flex-1">
                                                    <p
                                                        class="truncate font-medium text-gray-900 dark:text-white"
                                                    >
                                                        {{
                                                            file.original_filename
                                                        }}
                                                    </p>
                                                    <p
                                                        class="text-sm text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{
                                                            formatFileSize(
                                                                file.size,
                                                            )
                                                        }}
                                                        • {{ file.mime_type }}
                                                    </p>
                                                </div>
                                            </div>

                                            <Button
                                                variant="outline"
                                                size="sm"
                                                @click="downloadFile(file)"
                                                class="shrink-0"
                                            >
                                                <Download class="h-4 w-4" />
                                                <span
                                                    class="ml-1 hidden sm:inline"
                                                    >Download</span
                                                >
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div
                                class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-800"
                            >
                                <Button
                                    variant="outline"
                                    @click="emit('close')"
                                >
                                    Close
                                </Button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
