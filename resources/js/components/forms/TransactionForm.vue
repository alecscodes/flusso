<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    ModalFooter,
    SearchableSelect,
    Textarea,
} from '@/components/ui';
import { useDate } from '@/composables';
import { rates } from '@/routes/currency';
import type { Account, Category, Transaction } from '@/types';
import { useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';

type TransactionType = Transaction['type'];

interface Props {
    transaction?: Transaction | null;
    accounts: Account[];
    categories: Category[];
    action: string;
    initialType?: TransactionType;
    /** Pre-selects the (source) account when creating. */
    accountId?: number;
}

const props = withDefaults(defineProps<Props>(), {
    initialType: 'expense',
});

const emit = defineEmits<{
    success: [];
    cancel: [];
}>();

const { toInputFormat } = useDate();
const http = useHttp();
const formId = useId();

const isEditing = computed(() => !!props.transaction);

const types: Array<{
    value: TransactionType;
    label: string;
    active: string;
    hover: string;
}> = [
    {
        value: 'expense',
        label: 'Expense',
        active: 'border-rose-500 bg-rose-500/10 text-rose-600',
        hover: 'hover:border-rose-300 hover:bg-rose-500/5',
    },
    {
        value: 'income',
        label: 'Income',
        active: 'border-emerald-500 bg-emerald-500/10 text-emerald-600',
        hover: 'hover:border-emerald-300 hover:bg-emerald-500/5',
    },
    {
        value: 'transfer',
        label: 'Transfer',
        active: 'border-blue-500 bg-blue-500/10 text-blue-600',
        hover: 'hover:border-blue-300 hover:bg-blue-500/5',
    },
];

const form = useForm({
    type: props.transaction?.type ?? props.initialType,
    account_id:
        props.transaction?.account_id?.toString() ??
        props.accountId?.toString() ??
        '',
    to_account_id: props.transaction?.to_account_id?.toString() ?? '',
    category_id: props.transaction?.category_id?.toString() ?? '',
    amount: props.transaction?.amount ?? '',
    to_amount: props.transaction?.to_amount ?? '',
    description: props.transaction?.description ?? '',
    date: props.transaction?.date
        ? toInputFormat(props.transaction.date)
        : toInputFormat(new Date()),
    files: [] as File[],
});

const isTransfer = computed(() => form.type === 'transfer');

const selectedAccount = computed(() =>
    props.accounts.find((a) => a.id.toString() === form.account_id),
);

const destinationAccount = computed(() =>
    props.accounts.find((a) => a.id.toString() === form.to_account_id),
);

const filteredCategories = computed(() =>
    props.categories.filter((c) => c.type === form.type),
);

const accountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})`,
    })),
);

const destinationOptions = computed(() =>
    accountOptions.value.filter((o) => o.value !== form.account_id),
);

const categoryOptions = computed(() => [
    { value: '', label: 'No category' },
    ...filteredCategories.value.map((c) => ({
        value: c.id.toString(),
        label: c.name,
    })),
]);

// --- Amount received (cross-currency transfers) ---------------------------

const needsReceivedAmount = computed(
    () =>
        isTransfer.value &&
        !!selectedAccount.value &&
        !!destinationAccount.value &&
        selectedAccount.value.currency !== destinationAccount.value.currency,
);

const rate = ref<number | null>(null);
const rateUnavailable = ref(false);
// Once the owner types the received amount (or edits a saved transfer), it is never overwritten.
const receivedAmountEdited = ref(isEditing.value);

watch(
    () => [
        needsReceivedAmount.value,
        selectedAccount.value?.currency,
        destinationAccount.value?.currency,
    ],
    async () => {
        rate.value = null;
        rateUnavailable.value = false;

        if (
            !needsReceivedAmount.value ||
            !selectedAccount.value ||
            !destinationAccount.value
        ) {
            return;
        }

        try {
            const response = (await http.submit(
                rates({
                    query: {
                        from: selectedAccount.value.currency,
                        to: destinationAccount.value.currency,
                    },
                }),
            )) as { rate: number };

            rate.value = response.rate;
        } catch {
            rateUnavailable.value = true;
        }
    },
    { immediate: true },
);

watch([() => form.amount, rate], () => {
    if (
        needsReceivedAmount.value &&
        rate.value &&
        form.amount &&
        !receivedAmountEdited.value
    ) {
        form.to_amount = (parseFloat(form.amount) * rate.value).toFixed(2);
    }
});

// --- Field resets -----------------------------------------------------------

watch(
    () => form.type,
    () => {
        form.category_id = '';
        form.to_account_id = '';
    },
);

watch(
    () => form.account_id,
    (accountId) => {
        if (accountId === form.to_account_id) {
            form.to_account_id = '';
        }
    },
);

function handleFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        const validFiles = Array.from(target.files).filter((file) => {
            return file.size <= 10 * 1024 * 1024; // 10MB limit
        });

        form.files = validFiles;
    }
}

function removeFile(index: number) {
    form.files.splice(index, 1);
}

function submit() {
    form.transform((data) => ({
        ...data,
        category_id: isTransfer.value ? '' : data.category_id,
        to_account_id: isTransfer.value ? data.to_account_id : '',
        // Same-currency transfers arrive 1:1, so the server fills it in.
        to_amount: needsReceivedAmount.value ? data.to_amount : '',
    }))[isEditing.value ? 'put' : 'post'](props.action, {
        onSuccess: () => emit('success'),
    });
}
</script>

<template>
    <form :id="formId" class="space-y-6" @submit.prevent="submit">
        <div class="space-y-4">
            <div class="space-y-2">
                <Label required>Transaction Type</Label>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="type in types"
                        :key="type.value"
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-4 py-3 text-sm font-medium transition-all',
                            form.type === type.value
                                ? type.active
                                : `border-border ${type.hover}`,
                        ]"
                        @click="form.type = type.value"
                    >
                        {{ type.label }}
                    </button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="account_id" required>
                        {{ isTransfer ? 'From account' : 'Account' }}
                    </Label>
                    <SearchableSelect
                        id="account_id"
                        v-model="form.account_id"
                        name="account_id"
                        placeholder="Select account"
                        search-placeholder="Search account..."
                        :options="accountOptions"
                        required
                    />
                    <p
                        v-if="form.errors.account_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.account_id }}
                    </p>
                </div>

                <div v-if="isTransfer" class="space-y-2">
                    <Label for="to_account_id" required>To account</Label>
                    <SearchableSelect
                        id="to_account_id"
                        v-model="form.to_account_id"
                        name="to_account_id"
                        placeholder="Select destination"
                        search-placeholder="Search account..."
                        :options="destinationOptions"
                        required
                    />
                    <p
                        v-if="form.errors.to_account_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.to_account_id }}
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="amount" required>Amount</Label>
                    <div class="relative">
                        <Input
                            id="amount"
                            v-model="form.amount"
                            name="amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            class="pr-16"
                            required
                        />
                        <span
                            class="absolute top-1/2 right-4 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            {{ selectedAccount?.currency || 'EUR' }}
                        </span>
                    </div>
                    <p
                        v-if="form.errors.amount"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.amount }}
                    </p>
                </div>

                <div v-if="needsReceivedAmount" class="space-y-2">
                    <Label for="to_amount" required>Amount received</Label>
                    <div class="relative">
                        <Input
                            id="to_amount"
                            v-model="form.to_amount"
                            name="to_amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            class="pr-16"
                            required
                            @input="receivedAmountEdited = true"
                        />
                        <span
                            class="absolute top-1/2 right-4 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            {{ destinationAccount?.currency }}
                        </span>
                    </div>
                    <p
                        v-if="rate && !receivedAmountEdited"
                        class="text-xs text-muted-foreground"
                    >
                        1 {{ selectedAccount?.currency }} ≈ {{ rate }}
                        {{ destinationAccount?.currency }} (today's rate), edit
                        it to what actually arrived.
                    </p>
                    <p
                        v-else-if="rateUnavailable"
                        class="text-xs text-muted-foreground"
                    >
                        Today's rate is unavailable, enter what arrived.
                    </p>
                    <p
                        v-if="form.errors.to_amount"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.to_amount }}
                    </p>
                </div>
            </div>

            <div v-if="!isTransfer" class="space-y-2">
                <Label for="category_id">Category</Label>
                <SearchableSelect
                    id="category_id"
                    v-model="form.category_id"
                    name="category_id"
                    placeholder="Select category (optional)"
                    search-placeholder="Search category..."
                    :options="categoryOptions"
                />
                <p
                    v-if="form.errors.category_id"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.category_id }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="date">Date</Label>
                <Input id="date" v-model="form.date" name="date" type="date" />
            </div>

            <div class="space-y-2">
                <Label for="files">Attachments</Label>
                <Input
                    id="files"
                    name="files"
                    type="file"
                    multiple
                    @change="handleFileChange"
                    accept=".pdf,.doc,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                />
                <div v-if="form.files.length > 0" class="mt-2 space-y-1">
                    <div
                        v-for="(file, index) in form.files"
                        :key="index"
                        class="flex items-center justify-between rounded bg-muted/50 p-2"
                    >
                        <span class="text-sm text-muted-foreground">{{
                            file.name
                        }}</span>
                        <button
                            type="button"
                            @click="removeFile(index)"
                            class="text-sm text-rose-500 hover:text-rose-700"
                        >
                            Remove
                        </button>
                    </div>
                </div>
                <p v-if="form.errors.files" class="text-sm text-destructive">
                    {{ form.errors.files }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="description">Description</Label>
                <Textarea
                    id="description"
                    v-model="form.description"
                    name="description"
                    :placeholder="
                        isTransfer
                            ? 'Transfer notes (optional)'
                            : 'What was this transaction for?'
                    "
                    rows="2"
                />
            </div>
        </div>

        <ModalFooter>
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit" :form="formId" :disabled="form.processing">
                {{ isEditing ? 'Update' : 'Add' }}
                {{ isTransfer ? 'Transfer' : 'Transaction' }}
            </Button>
        </ModalFooter>
    </form>
</template>
