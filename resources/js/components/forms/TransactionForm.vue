<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    ModalFooter,
    SearchableSelect,
    Switch,
    Textarea,
} from '@/components/ui';
import { useDate } from '@/composables';
import { options as counterpartyOptions } from '@/routes/counterparties';
import { rates } from '@/routes/currency';
import type { Account, Category, Transaction } from '@/types';
import type { TransactionShortcut } from '@/types/suggestions';
import { useForm, useHttp } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';
import CategorySuggestion from './CategorySuggestion.vue';
import TransactionShortcuts from './TransactionShortcuts.vue';

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
    counterparty_id: props.transaction?.counterparty_id?.toString() ?? '',
    counterparty_name: props.transaction?.counterparty?.name ?? '',
    is_advance: props.transaction?.is_advance ?? false,
});

const isTransfer = computed(() => form.type === 'transfer');

const fieldErrorKeys = [
    'type',
    'account_id',
    'to_account_id',
    'category_id',
    'amount',
    'to_amount',
    'description',
    'date',
    'counterparty_id',
    'counterparty_name',
    'is_advance',
];

const fileErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
);

// Anything the server rejects that no field shows (e.g. a rule about the ledger).
const otherErrors = computed(() =>
    Object.entries(form.errors)
        .filter(
            ([key]) =>
                !fieldErrorKeys.includes(key) &&
                key !== 'files' &&
                !key.startsWith('files.'),
        )
        .map(([, message]) => message),
);

// An edited transaction keeps its accounts even if one was archived since.
const accounts = computed(() => [
    ...props.accounts,
    ...[props.transaction?.account, props.transaction?.to_account].filter(
        (a): a is Account =>
            !!a && !props.accounts.some((known) => known.id === a.id),
    ),
]);

const selectedAccount = computed(() =>
    accounts.value.find((a) => a.id.toString() === form.account_id),
);

const destinationAccount = computed(() =>
    accounts.value.find((a) => a.id.toString() === form.to_account_id),
);

const filteredCategories = computed(() =>
    props.categories.filter((c) => c.type === form.type),
);

const accountOptions = computed(() =>
    accounts.value.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})${a.deleted_at ? ' · archived' : ''}`,
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
// Only the newest rate request may set the rate; older ones are ignored, even when they fail.
let rateRequest = 0;
const rateUnavailable = ref(false);
// Once the owner types the received amount, it is never overwritten.
const receivedAmountEdited = ref(false);

// A saved transfer keeps the rate it was booked at while its accounts stay the same.
const savedRate = computed(() => {
    const saved = props.transaction;

    return saved?.to_amount &&
        parseFloat(saved.amount) > 0 &&
        form.account_id === saved.account_id.toString() &&
        form.to_account_id === saved.to_account_id?.toString()
        ? parseFloat(saved.to_amount) / parseFloat(saved.amount)
        : null;
});

const effectiveRate = computed(() => savedRate.value ?? rate.value);

watch(
    () => [
        needsReceivedAmount.value,
        selectedAccount.value?.currency,
        destinationAccount.value?.currency,
    ],
    async () => {
        const request = ++rateRequest;
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

            if (request === rateRequest) {
                rate.value = response.rate;
            }
        } catch {
            if (request === rateRequest) {
                rateUnavailable.value = true;
            }
        }
    },
    { immediate: true },
);

function convertedAmount(): string {
    return needsReceivedAmount.value && effectiveRate.value && form.amount
        ? (parseFloat(form.amount) * effectiveRate.value).toFixed(2)
        : '';
}

watch([() => form.amount, effectiveRate], () => {
    if (needsReceivedAmount.value && !receivedAmountEdited.value) {
        form.to_amount = convertedAmount() || form.to_amount;
    }
});

// Another pair of accounts makes an earlier typed or converted amount meaningless.
watch([() => form.account_id, () => form.to_account_id], () => {
    receivedAmountEdited.value = false;
    form.to_amount = convertedAmount();
});

// --- Person (advances) -------------------------------------------------------

const people = ref<Array<{ id: number; name: string }>>([]);
// Until the owner touches the field, an edited transaction keeps the person it has.
const personEdited = ref(false);
const personListId = `${formId}-people`;

onMounted(async () => {
    try {
        const response = (await http.submit(counterpartyOptions())) as {
            counterparties: Array<{ id: number; name: string }>;
        };
        people.value = response.counterparties;

        if (!form.counterparty_name && form.counterparty_id) {
            form.counterparty_name =
                people.value.find(
                    (p) => p.id.toString() === form.counterparty_id,
                )?.name ?? '';
        }
    } catch {
        // The field still works as free text.
    }
});

const matchedPerson = computed(() =>
    people.value.find(
        (p) =>
            p.name.toLowerCase() ===
            form.counterparty_name.trim().toLowerCase(),
    ),
);

// An income whose description names a known person: offered, never applied on its own.
const suggestedPerson = computed(() => {
    const text = form.description.toLowerCase();

    if (
        form.type !== 'income' ||
        form.counterparty_name.trim() !== '' ||
        text === ''
    ) {
        return undefined;
    }

    return [...people.value]
        .sort((a, b) => b.name.length - a.name.length)
        .find((p) => p.name !== '' && text.includes(p.name.toLowerCase()));
});

// --- Field resets -----------------------------------------------------------

watch(
    () => form.type,
    () => {
        form.category_id = '';
        form.to_account_id = '';

        if (form.type !== 'expense') {
            form.is_advance = false;
        }
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

// A shortcut only prefills: the owner still reviews and presses Add.
async function applyShortcut(shortcut: TransactionShortcut) {
    form.type = shortcut.type;
    // Changing the type clears the category: wait for that before filling it in.
    await nextTick();
    form.account_id = shortcut.account_id.toString();
    form.category_id = shortcut.category_id?.toString() ?? '';
    form.amount = shortcut.amount;
    form.description = shortcut.description ?? '';
}

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

function personFields(data: {
    counterparty_name: string;
    is_advance: boolean;
}) {
    if (isTransfer.value) {
        return {
            counterparty_id: '',
            counterparty_name: '',
            is_advance: false,
        };
    }

    // Untouched edit whose list of people never loaded: keep the person it has.
    if (
        !personEdited.value &&
        props.transaction?.counterparty_id &&
        people.value.length === 0
    ) {
        return {};
    }

    const name = data.counterparty_name.trim();
    const known = matchedPerson.value;

    return {
        counterparty_id: known ? known.id.toString() : '',
        counterparty_name: known || !name ? '' : name,
        is_advance: form.type === 'expense' && !!name && data.is_advance,
    };
}

function submit() {
    form.transform((data) => ({
        ...data,
        category_id: isTransfer.value ? '' : data.category_id,
        to_account_id: isTransfer.value ? data.to_account_id : '',
        // Same-currency transfers arrive 1:1, so the server fills it in.
        to_amount: needsReceivedAmount.value ? data.to_amount : '',
        ...personFields(data),
    }))[isEditing.value ? 'put' : 'post'](props.action, {
        onSuccess: () => emit('success'),
    });
}
</script>

<template>
    <form :id="formId" class="space-y-6" @submit.prevent="submit">
        <div class="space-y-4">
            <TransactionShortcuts
                v-if="!isEditing && !isTransfer"
                :accounts="accounts"
                :categories="categories"
                @pick="applyShortcut"
            />

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
                <p v-if="form.errors.type" class="text-sm text-destructive">
                    {{ form.errors.type }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                        v-if="effectiveRate && !receivedAmountEdited"
                        class="text-xs text-muted-foreground"
                    >
                        1 {{ selectedAccount?.currency }} ≈
                        {{ Number(effectiveRate.toPrecision(4)) }}
                        {{ destinationAccount?.currency }}
                        ({{
                            savedRate ? "this transfer's rate" : "today's rate"
                        }}), edit it to what actually arrived.
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
                <CategorySuggestion
                    :description="form.description"
                    :type="form.type"
                    :categories="categories"
                    :has-category="form.category_id !== ''"
                    @accept="(id) => (form.category_id = id.toString())"
                />
            </div>

            <div v-if="!isTransfer" class="space-y-2">
                <Label for="counterparty_name">
                    {{ form.type === 'expense' ? 'For person' : 'From person' }}
                </Label>
                <Input
                    id="counterparty_name"
                    v-model="form.counterparty_name"
                    name="counterparty_name"
                    :list="personListId"
                    maxlength="100"
                    placeholder="Optional, e.g. Mama"
                    autocomplete="off"
                    @input="personEdited = true"
                />
                <datalist :id="personListId">
                    <option v-for="p in people" :key="p.id" :value="p.name" />
                </datalist>
                <p
                    v-if="form.counterparty_name.trim() && !matchedPerson"
                    class="text-xs text-muted-foreground"
                >
                    A new person will be added.
                </p>
                <button
                    v-if="suggestedPerson"
                    type="button"
                    class="text-xs text-primary hover:underline"
                    @click="
                        form.counterparty_name = suggestedPerson.name;
                        personEdited = true;
                    "
                >
                    Looks like {{ suggestedPerson.name }}: use them?
                </button>
                <p
                    v-if="
                        form.errors.counterparty_id ||
                        form.errors.counterparty_name
                    "
                    class="text-sm text-destructive"
                >
                    {{
                        form.errors.counterparty_id ||
                        form.errors.counterparty_name
                    }}
                </p>
            </div>

            <div
                v-if="form.type === 'expense' && form.counterparty_name.trim()"
                class="space-y-1"
            >
                <div class="flex items-center justify-between gap-3">
                    <Label for="is_advance">I paid this for them</Label>
                    <Switch id="is_advance" v-model="form.is_advance" />
                </div>
                <p class="text-xs text-muted-foreground">
                    They owe you this amount until you settle it.
                </p>
                <p
                    v-if="form.errors.is_advance"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.is_advance }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="date">Date</Label>
                <Input id="date" v-model="form.date" name="date" type="date" />
                <p v-if="form.errors.date" class="text-sm text-destructive">
                    {{ form.errors.date }}
                </p>
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
                <p
                    v-for="message in fileErrors"
                    :key="message"
                    class="text-sm text-destructive"
                >
                    {{ message }}
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
                <p
                    v-if="form.errors.description"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.description }}
                </p>
            </div>

            <p
                v-for="message in otherErrors"
                :key="message"
                class="text-sm text-destructive"
            >
                {{ message }}
            </p>
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
