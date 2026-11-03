<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    Modal,
    ModalFooter,
    SearchableSelect,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { scan, store } from '@/routes/receipts';
import type { Account, Category } from '@/types';
import { useForm, useHttp } from '@inertiajs/vue3';
import { AlertTriangle, ScanLine } from '@lucide/vue';
import { computed, reactive, ref, useId, watch } from 'vue';

/**
 * Scan a receipt: the model only PROPOSES; the owner reviews (and can change
 * every number, category and grouping) before anything is saved.
 */
type ScanMode = 'total' | 'split';
type AmountMode = 'exact' | 'round_up';

interface Proposal {
    mode: ScanMode;
    amounts: AmountMode;
    merchant: string | null;
    date: string | null;
    total: number | null;
    lines: Array<{
        description: string;
        amount: number;
        category_id: number | null;
    }>;
    transactions: Array<{ category_id: number | null }>;
    token: string;
}

/** Amounts are kept in cents so sums never drift. */
interface Line {
    description: string;
    cents: number;
    category_id: string;
    adjustment?: boolean;
}

interface Props {
    open: boolean;
    accounts: Account[];
    categories: Category[];
}

const props = defineProps<Props>();
const emit = defineEmits<{
    close: [];
}>();

const MODE_KEY = 'flusso.receipts.mode';
const AMOUNTS_KEY = 'flusso.receipts.amounts';
// A difference up to this is rounding noise and not worth a warning.
const TOLERANCE_CENTS = 5;

const { toInputFormat } = useDate();
const { formatCurrency } = useCurrency();
const formId = useId();

function remembered<T extends string>(key: string, allowed: T[], fallback: T) {
    try {
        const value = localStorage.getItem(key);

        return allowed.find((option) => option === value) ?? fallback;
    } catch {
        return fallback;
    }
}

function remember(key: string, value: string) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the choice simply is not remembered.
    }
}

const mode = ref<ScanMode>(remembered(MODE_KEY, ['total', 'split'], 'total'));
const amounts = ref<AmountMode>(
    remembered(AMOUNTS_KEY, ['exact', 'round_up'], 'exact'),
);

watch(mode, (value) => remember(MODE_KEY, value));
watch(amounts, (value) => remember(AMOUNTS_KEY, value));

// --- Step 1: pick and read the image ----------------------------------------

const step = ref<'upload' | 'review'>('upload');
const scanner = useHttp({
    image: null as File | null,
    mode: mode.value as string,
    amounts: amounts.value as string,
});
const scanError = ref('');
const scanning = ref(false);

function pickImage(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    scanner.image = file;
    scanError.value = '';
}

async function runScan() {
    if (!scanner.image) {
        scanError.value = 'Choose a photo of the receipt first.';

        return;
    }

    scanner.mode = mode.value;
    scanner.amounts = amounts.value;
    scanError.value = '';
    scanning.value = true;

    try {
        const proposal = (await scanner.submit(scan())) as Proposal | undefined;

        if (!proposal) {
            scanError.value =
                scanner.errors.image ??
                scanner.errors.mode ??
                'The receipt could not be read.';

            return;
        }

        startReview(proposal);
    } catch {
        scanError.value =
            'The receipt could not be read. You can still add the transaction by hand.';
    } finally {
        scanning.value = false;
    }
}

// --- Step 2: review the proposal -------------------------------------------

const proposal = ref<Proposal | null>(null);
const lines = ref<Line[]>([]);
const description = ref('');
// Amounts the owner typed over the computed ones, by category ('' = none).
const edited = reactive<Record<string, string>>({});
const adjustTo = ref('');

const form = useForm({
    account_id: '',
    date: '',
    token: '',
    transactions: [] as Array<{
        category_id: string;
        amount: string;
        description: string;
    }>,
});

const accountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})`,
    })),
);

const expenseCategories = computed(() =>
    props.categories.filter((c) => c.type === 'expense'),
);

const categoryOptions = computed(() => [
    { value: '', label: 'No category' },
    ...expenseCategories.value.map((c) => ({
        value: c.id.toString(),
        label: c.name,
    })),
]);

const currency = computed(
    () =>
        props.accounts.find((a) => a.id.toString() === form.account_id)
            ?.currency ?? 'EUR',
);

const isSplit = computed(() => proposal.value?.mode === 'split');

// A total scan is a single line; its category is picked on the transaction itself.
const totalCategory = computed({
    get: () => lines.value[0]?.category_id ?? '',
    set: (value: string | number) => {
        if (lines.value[0]) {
            lines.value[0].category_id = String(value);
        }
    },
});

function startReview(result: Proposal) {
    const known = new Set(expenseCategories.value.map((c) => c.id));
    const toCategory = (id: number | null) =>
        id !== null && known.has(id) ? id.toString() : '';

    proposal.value = result;
    description.value = result.merchant ?? '';
    adjustTo.value = '';
    lines.value =
        result.mode === 'split'
            ? result.lines.map((line) => ({
                  description: line.description,
                  cents: Math.round(line.amount * 100),
                  category_id: toCategory(line.category_id),
              }))
            : [
                  {
                      description: result.merchant ?? '',
                      cents: Math.round((result.total ?? 0) * 100),
                      category_id: toCategory(
                          result.transactions[0]?.category_id ?? null,
                      ),
                  },
              ];

    form.reset();
    form.clearErrors();
    form.account_id =
        form.account_id || (props.accounts[0]?.id.toString() ?? '');
    form.date = result.date ?? toInputFormat(new Date());
    form.token = result.token;
    clearEdits();
    step.value = 'review';
}

function clearEdits() {
    Object.keys(edited).forEach((key) => delete edited[key]);
}

// Moving a line to another category makes any typed group amount stale.
watch(lines, clearEdits, { deep: true });

/** The same rule as the server: to the cent, or up to the next whole unit. */
function applyMode(cents: number): number {
    return amounts.value === 'round_up' ? Math.ceil(cents / 100) * 100 : cents;
}

const groups = computed(() => {
    const sums = new Map<string, number>();

    for (const line of lines.value) {
        sums.set(
            line.category_id,
            (sums.get(line.category_id) ?? 0) + line.cents,
        );
    }

    return [...sums].map(([category_id, cents]) => ({
        category_id,
        cents,
        name:
            expenseCategories.value.find((c) => c.id.toString() === category_id)
                ?.name ?? 'No category',
        amount: edited[category_id] ?? (applyMode(cents) / 100).toFixed(2),
    }));
});

const linesCents = computed(() =>
    lines.value.reduce((sum, line) => sum + line.cents, 0),
);

// The printed total against the lines: a gap is shown, never silently absorbed.
const differenceCents = computed(() =>
    isSplit.value && proposal.value?.total
        ? Math.round(proposal.value.total * 100) - linesCents.value
        : 0,
);

const hasDifference = computed(
    () => Math.abs(differenceCents.value) > TOLERANCE_CENTS,
);

const groupOptions = computed(() =>
    groups.value.map((g) => ({ value: g.category_id, label: g.name })),
);

function addAdjustment() {
    const target = adjustTo.value || groups.value[0]?.category_id;

    if (target === undefined) {
        return;
    }

    lines.value.push({
        description: 'Adjustment',
        cents: differenceCents.value,
        category_id: target,
        adjustment: true,
    });
}

function removeAdjustment(index: number) {
    lines.value.splice(index, 1);
}

const invalidAmount = computed(() =>
    groups.value.some((g) => !(parseFloat(g.amount) > 0)),
);

const errorMessages = computed(() => Object.values(form.errors));

function submit() {
    form.transform((data) => ({
        ...data,
        transactions: groups.value.map((g) => ({
            category_id: g.category_id,
            amount: g.amount,
            description: description.value,
        })),
    })).post(store.url(), {
        onSuccess: () => emit('close'),
    });
}

function back() {
    step.value = 'upload';
}

// Every opening starts from the photo again.
watch(
    () => props.open,
    (open) => {
        if (open) {
            step.value = 'upload';
            scanner.image = null;
            scanError.value = '';
        }
    },
);
</script>

<template>
    <Modal
        :open="open"
        title="Scan receipt"
        :description="
            step === 'upload'
                ? 'Read a receipt with AI, then check it before saving'
                : 'Check everything: nothing is saved until you confirm'
        "
        size="lg"
        @close="emit('close')"
    >
        <form
            v-if="step === 'upload'"
            :id="formId"
            class="space-y-5"
            @submit.prevent="runScan"
        >
            <div class="space-y-2">
                <Label for="receipt-image" required>Photo of the receipt</Label>
                <Input
                    id="receipt-image"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    @change="pickImage"
                />
                <p class="text-xs text-muted-foreground">
                    JPEG, PNG or WebP, up to 10 MB.
                </p>
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-foreground">
                    What to create
                </legend>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label
                        v-for="option in [
                            {
                                value: 'total',
                                title: 'One transaction',
                                hint: 'The total, with a suggested category',
                            },
                            {
                                value: 'split',
                                title: 'Split by category',
                                hint: 'One transaction per category of the items',
                            },
                        ]"
                        :key="option.value"
                        class="flex cursor-pointer flex-col rounded-xl border-2 p-3 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                    >
                        <input
                            v-model="mode"
                            type="radio"
                            name="receipt-mode"
                            class="sr-only"
                            :value="option.value"
                        />
                        <span class="font-semibold text-foreground">
                            {{ option.title }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ option.hint }}
                        </span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-foreground">
                    Amounts
                </legend>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label
                        v-for="option in [
                            {
                                value: 'exact',
                                title: 'Exact',
                                hint: 'To the cent, e.g. 13.70',
                            },
                            {
                                value: 'round_up',
                                title: 'Round up',
                                hint: 'To the next whole unit, e.g. 14',
                            },
                        ]"
                        :key="option.value"
                        class="flex cursor-pointer flex-col rounded-xl border-2 p-3 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                    >
                        <input
                            v-model="amounts"
                            type="radio"
                            name="receipt-amounts"
                            class="sr-only"
                            :value="option.value"
                        />
                        <span class="font-semibold text-foreground">
                            {{ option.title }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ option.hint }}
                        </span>
                    </label>
                </div>
            </fieldset>

            <p v-if="scanError" class="text-sm text-destructive" role="alert">
                {{ scanError }}
            </p>

            <ModalFooter>
                <Button type="button" variant="outline" @click="emit('close')">
                    Cancel
                </Button>
                <Button
                    type="submit"
                    :form="formId"
                    :disabled="scanning"
                    :loading="scanning"
                >
                    <ScanLine class="h-4 w-4" />
                    {{ scanning ? 'Reading...' : 'Read receipt' }}
                </Button>
            </ModalFooter>
        </form>

        <form v-else :id="formId" class="space-y-5" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="receipt-account" required>Account</Label>
                    <SearchableSelect
                        id="receipt-account"
                        v-model="form.account_id"
                        name="account_id"
                        placeholder="Select account"
                        search-placeholder="Search account..."
                        :options="accountOptions"
                        :error="form.errors.account_id"
                        required
                    />
                </div>
                <div class="space-y-2">
                    <Label for="receipt-date" required>Date</Label>
                    <Input
                        id="receipt-date"
                        v-model="form.date"
                        type="date"
                        :error="form.errors.date"
                        required
                    />
                </div>
            </div>

            <div class="space-y-2">
                <Label for="receipt-description">Description</Label>
                <Input
                    id="receipt-description"
                    v-model="description"
                    maxlength="1000"
                    placeholder="Shop or note"
                />
            </div>

            <div
                v-if="hasDifference"
                class="space-y-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4"
                role="alert"
            >
                <p
                    class="flex items-start gap-2 text-sm font-medium text-foreground"
                >
                    <AlertTriangle
                        class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"
                    />
                    <span>
                        The items add up to
                        {{ formatCurrency(linesCents / 100, currency) }} but the
                        receipt total is
                        {{ formatCurrency(proposal?.total ?? 0, currency) }}
                        (difference
                        {{ formatCurrency(differenceCents / 100, currency) }}).
                        A discount or a missed item may explain it.
                    </span>
                </p>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="flex-1 space-y-2">
                        <Label for="receipt-adjust"
                            >Add the difference to</Label
                        >
                        <SearchableSelect
                            id="receipt-adjust"
                            v-model="adjustTo"
                            :options="groupOptions"
                            placeholder="Choose a group"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        @click="addAdjustment"
                    >
                        Add as adjustment
                    </Button>
                </div>
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-semibold text-foreground">
                    {{
                        groups.length === 1
                            ? 'Transaction to create'
                            : 'Transactions to create'
                    }}
                </h3>
                <div
                    v-for="group in groups"
                    :key="group.category_id"
                    class="grid grid-cols-1 gap-2 rounded-xl bg-muted/50 p-3 sm:grid-cols-[1fr_9rem] sm:items-center"
                >
                    <div>
                        <SearchableSelect
                            v-if="!isSplit"
                            v-model="totalCategory"
                            aria-label="Category"
                            :options="categoryOptions"
                            search-placeholder="Search category..."
                        />
                        <span v-else class="text-sm font-medium">
                            {{ group.name }}
                        </span>
                    </div>
                    <Input
                        :model-value="group.amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        inputmode="decimal"
                        :aria-label="`Amount for ${group.name}`"
                        @update:model-value="
                            edited[group.category_id] = String($event)
                        "
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    Amounts are in {{ currency }}, the currency of the account.
                    You can change any number before saving.
                </p>
            </div>

            <div v-if="isSplit" class="space-y-2">
                <h3 class="text-sm font-semibold text-foreground">
                    Items (move an item to another category to regroup)
                </h3>
                <ul class="space-y-2">
                    <li
                        v-for="(line, index) in lines"
                        :key="index"
                        class="grid grid-cols-1 gap-2 rounded-xl border border-border p-3 sm:grid-cols-[1fr_6rem_12rem] sm:items-center"
                    >
                        <span class="min-w-0 truncate text-sm">
                            {{ line.description || 'Item' }}
                        </span>
                        <span class="text-sm tabular-nums sm:text-right">
                            {{ formatCurrency(line.cents / 100, currency) }}
                        </span>
                        <div class="flex items-center gap-2">
                            <SearchableSelect
                                v-model="line.category_id"
                                class="flex-1"
                                :aria-label="`Category of ${line.description || 'item'}`"
                                :options="categoryOptions"
                                search-placeholder="Search category..."
                            />
                            <Button
                                v-if="line.adjustment"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="removeAdjustment(index)"
                            >
                                Remove
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>

            <ul
                v-if="errorMessages.length > 0"
                class="space-y-1 text-sm text-destructive"
                role="alert"
            >
                <li v-for="message in errorMessages" :key="message">
                    {{ message }}
                </li>
            </ul>

            <ModalFooter>
                <Button type="button" variant="outline" @click="back">
                    Back
                </Button>
                <Button
                    type="submit"
                    :form="formId"
                    :disabled="form.processing || invalidAmount"
                >
                    Create
                    {{ groups.length === 1 ? 'transaction' : 'transactions' }}
                </Button>
            </ModalFooter>
        </form>
    </Modal>
</template>
