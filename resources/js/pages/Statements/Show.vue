<script setup lang="ts">
import {
    Badge,
    Button,
    Card,
    CardContent,
    Checkbox,
    EmptyState,
    Input,
    Label,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import {
    confirmAllSigns,
    confirmLineSign,
    flipAllSigns,
    destroy,
    download,
    flipLineSign,
    ignoreLine,
    importLines,
    index,
    reparse,
    restoreLine,
    show,
    update,
} from '@/routes/statements';
import type { PaginatedData } from '@/types';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, Download, FileText, RefreshCw, Trash2 } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

interface StatementDetail {
    id: number;
    original_filename: string;
    institution: string | null;
    account_id: number | null;
    account_label: string | null;
    account_name: string | null;
    currency: string | null;
    period_start: string | null;
    period_end: string | null;
    parser: string | null;
    lines_truncated: boolean;
    text_truncated: boolean;
    date_order_assumed: boolean;
    parse_status: 'done' | 'pending_ai' | 'ai_partial' | 'ai_failed';
    sign_uncertain_count: number;
    lines_count: number;
    parsed_at: string | null;
    has_text: boolean;
    counts: Record<string, number>;
    can_reparse: boolean;
    unmatched_totals: {
        currency: string | null;
        count: number;
        income: number;
        expense: number;
    }[];
}

interface Line {
    id: number;
    posted_on: string;
    amount: string;
    currency: string | null;
    description: string;
    parsed_by: string;
    sign_uncertain: boolean;
    match_status: string;
    matched_transaction: Candidate | null;
    suggested_category_id: number | null;
}

interface CategoryOption {
    id: number;
    name: string;
    type: string;
}

interface ImportDraft {
    selected: boolean;
    description: string;
    category_id: string;
}

interface Candidate {
    id: number;
    date: string;
    description: string | null;
    amount: string;
    type: string;
}

interface Props {
    statement: StatementDetail;
    lines: PaginatedData<Line>;
    candidates: Record<number, Candidate[]>;
    status: string | null;
    accounts: { id: number; name: string; currency: string }[];
    categories: CategoryOption[];
}

const props = defineProps<Props>();

const { formatDate } = useDate();
const { formatCurrency } = useCurrency();

const editing = ref(false);
const form = useForm({
    institution: props.statement.institution ?? '',
    account_label: props.statement.account_label ?? '',
    account_id: props.statement.account_id
        ? String(props.statement.account_id)
        : '',
    period_start: props.statement.period_start ?? '',
    period_end: props.statement.period_end ?? '',
});

const tabs = [
    { value: null, label: 'All' },
    { value: 'unmatched', label: 'Not in Flusso' },
    { value: 'matched', label: 'Matched' },
    { value: 'imported', label: 'Imported' },
    { value: 'ignored', label: 'Ignored' },
];

const statusVariant: Record<
    string,
    'success' | 'warning' | 'secondary' | 'outline'
> = {
    matched: 'success',
    unmatched: 'warning',
    imported: 'secondary',
    ignored: 'outline',
};

const parserLabels: Record<string, string> = {
    revolut: 'Revolut CSV',
    csv: 'CSV',
    pdf: 'PDF text',
    ai: 'AI-parsed',
    none: 'No lines found',
};

function saveDetails(): void {
    form.patch(update.url(props.statement.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

function filterBy(value: string | null): void {
    router.get(show.url(props.statement.id), value ? { status: value } : {}, {
        preserveScroll: true,
    });
}

function readAgain(): void {
    router.post(reparse.url(props.statement.id), {}, { preserveScroll: true });
}

function remove(): void {
    if (
        window.confirm(
            'Delete this statement and its file for good? This cannot be undone.',
        )
    ) {
        router.delete(destroy.url(props.statement.id));
    }
}

function money(amount: string, currency: string | null): string {
    return formatCurrency(
        Number(amount),
        currency ?? props.statement.currency ?? 'EUR',
    );
}

function count(status: string): number {
    return props.statement.counts[status] ?? 0;
}

const drafts = reactive<Record<number, ImportDraft>>({});
const importAccountId = ref(
    props.statement.account_id ? String(props.statement.account_id) : '',
);
const importErrors = ref<Record<string, string>>({});
const importing = ref(false);

watch(
    () => props.lines.data,
    (lines) => {
        for (const line of lines) {
            if (line.match_status === 'unmatched' && !(line.id in drafts)) {
                drafts[line.id] = {
                    selected: false,
                    description: line.description,
                    category_id: line.suggested_category_id
                        ? String(line.suggested_category_id)
                        : '',
                };
            }
        }
    },
    { immediate: true },
);

const unmatchedLines = computed(() =>
    props.lines.data.filter((line) => line.match_status === 'unmatched'),
);
const selectedLines = computed(() =>
    unmatchedLines.value.filter((line) => drafts[line.id]?.selected),
);
const allSelected = computed(
    () =>
        unmatchedLines.value.length > 0 &&
        selectedLines.value.length === unmatchedLines.value.length,
);

function toggleAll(value: boolean): void {
    for (const line of unmatchedLines.value) {
        drafts[line.id].selected = value;
    }
}

function categoriesFor(line: Line): CategoryOption[] {
    const type = Number(line.amount) < 0 ? 'expense' : 'income';

    return props.categories.filter((category) => category.type === type);
}

function importAllSummary(): string {
    const perCurrency = props.statement.unmatched_totals.map((total) => {
        const currency = total.currency ?? props.statement.currency ?? 'EUR';

        return `${currency}: ${total.count} lines, income ${formatCurrency(total.income, currency)}, expenses ${formatCurrency(total.expense, currency)}`;
    });

    return [
        `Import all ${count('unmatched')} lines that are not in Flusso as transactions?`,
        ...perCurrency,
        'Lines already in Flusso are skipped, and a line in another currency than the account is refused.',
    ].join('\n');
}

function submitImport(everyUnmatched: boolean): void {
    if (everyUnmatched && !window.confirm(importAllSummary())) {
        return;
    }

    const chosen = everyUnmatched ? unmatchedLines.value : selectedLines.value;

    router.post(
        importLines.url(props.statement.id),
        {
            account_id: importAccountId.value,
            import_all: everyUnmatched,
            lines: chosen.map((line) => ({
                id: line.id,
                description: drafts[line.id].description,
                category_id: drafts[line.id].category_id || null,
            })),
        },
        {
            preserveScroll: true,
            onStart: () => {
                importing.value = true;
                importErrors.value = {};
            },
            onError: (errors) => {
                importErrors.value = errors;
            },
            onSuccess: () => toggleAll(false),
            onFinish: () => {
                importing.value = false;
            },
        },
    );
}

function confirmSign(line: Line): void {
    router.post(
        confirmLineSign.url({ statement: props.statement.id, line: line.id }),
        {},
        { preserveScroll: true },
    );
}

function changeAllSigns(flip: boolean): void {
    const action = flip ? flipAllSigns : confirmAllSigns;
    const count = props.statement.sign_uncertain_count;
    const question = flip
        ? `Turn all ${count} uncertain lines into money OUT (expenses)? Use this only when every unsigned amount in this statement is money out.`
        : `Confirm all ${count} uncertain lines as money IN (income)?`;

    if (window.confirm(question)) {
        router.post(
            action.url(props.statement.id),
            {},
            { preserveScroll: true },
        );
    }
}

function flipSign(line: Line): void {
    router.post(
        flipLineSign.url({ statement: props.statement.id, line: line.id }),
        {},
        { preserveScroll: true },
    );
}

// While the AI reads the file in the background, look for the result every few seconds.
const poll = usePoll(
    5000,
    { only: ['statement', 'lines', 'candidates'] },
    { autoStart: false },
);

watch(
    () => props.statement.parse_status,
    (status) => (status === 'pending_ai' ? poll.start() : poll.stop()),
    { immediate: true },
);

function setAside(line: Line, restore: boolean): void {
    const route = restore ? restoreLine : ignoreLine;

    router.post(
        route.url({ statement: props.statement.id, line: line.id }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="statement.original_filename" />

    <div class="space-y-6">
        <Link
            :href="index.url()"
            class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="h-4 w-4" />
            All statements
        </Link>

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <h1
                    class="text-2xl font-bold tracking-tight break-all text-foreground"
                >
                    {{ statement.original_filename }}
                </h1>
                <p class="mt-1 text-muted-foreground">
                    {{
                        [
                            statement.institution,
                            statement.account_name ?? statement.account_label,
                        ]
                            .filter(Boolean)
                            .join(' · ') || 'Unlabeled account'
                    }}
                    ·
                    {{
                        statement.period_start && statement.period_end
                            ? `${formatDate(statement.period_start)} to ${formatDate(statement.period_end)}`
                            : 'Period unknown'
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Read as
                    {{
                        parserLabels[statement.parser ?? 'none'] ??
                        statement.parser
                    }}
                    <template v-if="!statement.has_text">
                        · no text found in the file (a scan?), so it cannot be
                        searched
                    </template>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    :as="'a'"
                    :href="download.url(statement.id)"
                    variant="outline"
                >
                    <Download class="h-4 w-4" />
                    Download
                </Button>
                <Button variant="outline" @click="editing = !editing">
                    Edit details
                </Button>
                <p
                    v-if="!statement.can_reparse"
                    class="self-center text-xs text-muted-foreground"
                >
                    Not read again once a line was imported, ignored or had its
                    sign checked.
                </p>
                <Button
                    v-if="statement.can_reparse"
                    variant="outline"
                    @click="readAgain"
                >
                    <RefreshCw class="h-4 w-4" />
                    Read again
                </Button>
                <Button variant="destructive" @click="remove">
                    <Trash2 class="h-4 w-4" />
                    Delete
                </Button>
            </div>
        </div>

        <Card v-if="editing">
            <CardContent class="pt-6">
                <form class="space-y-4" @submit.prevent="saveDetails">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="institution">Bank</Label>
                            <Input
                                id="institution"
                                v-model="form.institution"
                                :error="form.errors.institution"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="account_label">Account name</Label>
                            <Input
                                id="account_label"
                                v-model="form.account_label"
                                :error="form.errors.account_label"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="account_id">Flusso account</Label>
                            <select
                                id="account_id"
                                v-model="form.account_id"
                                class="flex h-11 w-full rounded-xl border border-input bg-background px-4 py-2 text-base focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                            >
                                <option value="">
                                    None (account not in Flusso)
                                </option>
                                <option
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="String(account.id)"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground">
                                With an account, lines are checked against its
                                transactions.
                            </p>
                            <p
                                v-if="form.errors.account_id"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.account_id }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="period_start">Period start</Label>
                                <Input
                                    id="period_start"
                                    v-model="form.period_start"
                                    type="date"
                                    :error="form.errors.period_start"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="period_end">Period end</Label>
                                <Input
                                    id="period_end"
                                    v-model="form.period_end"
                                    type="date"
                                    :error="form.errors.period_end"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" :loading="form.processing">
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            @click="editing = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <p
            v-if="statement.parse_status === 'pending_ai'"
            class="rounded-xl border border-primary/30 bg-primary/10 p-3 text-sm text-foreground"
            role="status"
        >
            Reading with AI… This can take a few minutes; the lines appear here
            when it is done.
        </p>
        <p
            v-else-if="statement.parse_status === 'ai_partial'"
            class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300"
            role="status"
        >
            Only part of this file was read by the AI. The text is complete and
            searchable; you can try Read again.
        </p>
        <p
            v-else-if="statement.parse_status === 'ai_failed'"
            class="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
            role="alert"
        >
            AI could not read this file. It is still kept and its text is
            searchable; you can try Read again.
        </p>
        <p
            v-if="statement.date_order_assumed"
            class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300"
            role="status"
        >
            Date order assumed: day/month. Nothing in this statement shows
            whether 03/04 means 3 April or 4 March, so check the dates.
        </p>
        <div
            v-if="statement.sign_uncertain_count > 0"
            class="space-y-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300"
            role="status"
        >
            <p>
                {{ statement.sign_uncertain_count }}
                {{
                    statement.sign_uncertain_count === 1
                        ? 'line has'
                        : 'lines have'
                }}
                a sign that could not be told from the debit and credit columns
                and
                {{ statement.sign_uncertain_count === 1 ? 'is' : 'are' }} shown
                as money in. They are not imported until you check them: use
                Flip sign where the amount is money out, or Sign is right where
                it is money in.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    @click="changeAllSigns(false)"
                >
                    Confirm all signs
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    @click="changeAllSigns(true)"
                >
                    Flip all uncertain signs
                </Button>
            </div>
        </div>
        <p
            v-if="statement.lines_truncated"
            class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300"
            role="status"
        >
            Only the first {{ statement.lines_count }} lines were read; the full
            text is still stored and searchable.
        </p>
        <p
            v-if="statement.text_truncated"
            class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300"
            role="status"
        >
            This file is so large that only its first part is searchable. The
            original file is kept in full and can be downloaded.
        </p>

        <div class="flex flex-wrap gap-2">
            <Badge variant="success">{{ count('matched') }} matched</Badge>
            <Badge variant="warning">
                {{ count('unmatched') }}
                {{ statement.account_id ? 'not in Flusso' : 'unchecked' }}
            </Badge>
            <Badge v-if="count('imported')" variant="secondary">
                {{ count('imported') }} imported
            </Badge>
            <Badge v-if="count('ignored')" variant="outline">
                {{ count('ignored') }} ignored
            </Badge>
        </div>

        <p
            v-if="!statement.account_id && statement.counts.unmatched"
            class="text-sm text-muted-foreground"
        >
            Link this statement to a Flusso account (Edit details) to see which
            lines are missing from your transactions.
        </p>

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="tab in tabs"
                :key="tab.label"
                size="sm"
                :variant="status === tab.value ? 'default' : 'outline'"
                @click="filterBy(tab.value)"
            >
                {{ tab.label }}
            </Button>
        </div>

        <Card v-if="count('unmatched') > 0">
            <CardContent class="space-y-3 pt-6">
                <div class="flex flex-wrap items-end gap-3">
                    <div v-if="!statement.account_id" class="space-y-2">
                        <Label for="import_account_id">Import into</Label>
                        <select
                            id="import_account_id"
                            v-model="importAccountId"
                            class="flex h-11 w-full rounded-xl border border-input bg-background px-4 py-2 text-base focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                        >
                            <option value="">Choose an account</option>
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.name }} ({{ account.currency }})
                            </option>
                        </select>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Importing into {{ statement.account_name }}
                    </p>
                    <Button
                        :disabled="selectedLines.length === 0 || importing"
                        @click="submitImport(false)"
                    >
                        Import selected ({{ selectedLines.length }})
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="importing"
                        @click="submitImport(true)"
                    >
                        Import all unmatched ({{ count('unmatched') }})
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Tick the lines to add as transactions. Amounts are copied
                    exactly; lines already in Flusso are detected and skipped.
                </p>
                <p
                    v-for="(message, key) in importErrors"
                    :key="key"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ message }}
                </p>
            </CardContent>
        </Card>

        <EmptyState
            v-if="lines.data.length === 0"
            title="No lines to show"
            :description="
                statement.counts.unmatched +
                    statement.counts.matched +
                    statement.counts.imported +
                    statement.counts.ignored ===
                0
                    ? 'No transactions could be read from this file. It is still kept and its text is searchable.'
                    : 'No line has this status.'
            "
            :icon="FileText"
        />

        <Card v-else>
            <CardContent class="px-2 pt-4 sm:px-6 sm:pt-6">
                <div
                    v-if="unmatchedLines.length > 1"
                    class="mb-2 flex items-center gap-2 px-2 text-sm text-muted-foreground"
                >
                    <Checkbox
                        :model-value="allSelected"
                        aria-label="Select all lines on this page"
                        @update:model-value="toggleAll"
                    />
                    Select all on this page
                </div>
                <ul class="divide-y divide-border">
                    <li v-for="line in lines.data" :key="line.id" class="py-3">
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                        >
                            <Checkbox
                                v-if="
                                    drafts[line.id] &&
                                    line.match_status === 'unmatched'
                                "
                                v-model="drafts[line.id].selected"
                                :aria-label="`Select ${line.description}`"
                            />
                            <span class="w-28 shrink-0 text-muted-foreground">
                                {{ formatDate(line.posted_on) }}
                            </span>
                            <span class="min-w-0 flex-1 break-words">
                                {{ line.description }}
                            </span>
                            <Badge
                                v-if="line.parsed_by === 'ai'"
                                variant="outline"
                            >
                                AI-parsed
                            </Badge>
                            <Badge
                                v-if="
                                    line.sign_uncertain &&
                                    ['unmatched', 'matched'].includes(
                                        line.match_status,
                                    )
                                "
                                variant="warning"
                                title="The sign could not be read from the columns of the PDF."
                            >
                                Check sign
                            </Badge>
                            <span
                                :class="[
                                    'font-medium tabular-nums',
                                    Number(line.amount) < 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : 'text-emerald-600 dark:text-emerald-400',
                                ]"
                            >
                                {{ money(line.amount, line.currency) }}
                            </span>
                            <Badge :variant="statusVariant[line.match_status]">
                                {{ line.match_status }}
                            </Badge>
                            <Button
                                v-if="
                                    line.sign_uncertain &&
                                    ['unmatched', 'matched'].includes(
                                        line.match_status,
                                    )
                                "
                                size="sm"
                                variant="ghost"
                                @click="confirmSign(line)"
                            >
                                Sign is right
                            </Button>
                            <Button
                                v-if="
                                    ['unmatched', 'matched'].includes(
                                        line.match_status,
                                    ) && Number(line.amount) !== 0
                                "
                                size="sm"
                                variant="ghost"
                                @click="flipSign(line)"
                            >
                                Flip sign
                            </Button>
                            <Button
                                v-if="line.match_status === 'unmatched'"
                                size="sm"
                                variant="ghost"
                                @click="setAside(line, false)"
                            >
                                Ignore
                            </Button>
                            <Button
                                v-else-if="line.match_status === 'ignored'"
                                size="sm"
                                variant="ghost"
                                @click="setAside(line, true)"
                            >
                                Restore
                            </Button>
                        </div>

                        <div
                            v-if="
                                drafts[line.id]?.selected &&
                                line.match_status === 'unmatched'
                            "
                            class="mt-2 grid gap-2 sm:grid-cols-2 sm:pl-8"
                        >
                            <Input
                                v-model="drafts[line.id].description"
                                :aria-label="`Description for ${line.description}`"
                                placeholder="Description"
                            />
                            <select
                                v-model="drafts[line.id].category_id"
                                :aria-label="`Category for ${line.description}`"
                                class="flex h-11 w-full rounded-xl border border-input bg-background px-4 py-2 text-base focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                            >
                                <option value="">No category</option>
                                <option
                                    v-for="category in categoriesFor(line)"
                                    :key="category.id"
                                    :value="String(category.id)"
                                >
                                    {{ category.name }}
                                </option>
                            </select>
                        </div>

                        <p
                            v-if="line.matched_transaction"
                            class="mt-1 pl-0 text-xs text-muted-foreground sm:pl-32"
                        >
                            Matches
                            {{
                                line.matched_transaction.description ||
                                'a transaction'
                            }}
                            on {{ formatDate(line.matched_transaction.date) }}
                        </p>

                        <div
                            v-if="candidates[line.id]?.length"
                            class="mt-1 text-xs text-muted-foreground sm:pl-32"
                        >
                            Could be one of:
                            <span
                                v-for="candidate in candidates[line.id]"
                                :key="candidate.id"
                                class="mr-2 inline-block rounded bg-muted px-2 py-0.5"
                            >
                                {{ formatDate(candidate.date) }}
                                {{ candidate.description || candidate.type }}
                            </span>
                        </div>
                    </li>
                </ul>

                <div
                    v-if="lines.last_page > 1"
                    class="mt-4 flex items-center justify-between border-t border-border pt-4"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ lines.from }} to {{ lines.to }} of {{ lines.total }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            v-if="lines.prev_page_url"
                            :as="Link"
                            :href="lines.prev_page_url"
                            preserve-scroll
                            variant="outline"
                            size="sm"
                        >
                            Previous
                        </Button>
                        <Button
                            v-if="lines.next_page_url"
                            :as="Link"
                            :href="lines.next_page_url"
                            preserve-scroll
                            variant="outline"
                            size="sm"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
