<script setup lang="ts">
import HighlightedText, {
    type TextSegment,
} from '@/components/data-display/HighlightedText.vue';
import {
    Badge,
    Button,
    Card,
    CardContent,
    EmptyState,
    Input,
    Label,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { download, index, show, store } from '@/routes/statements';
import type { PaginatedData } from '@/types';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { Archive, Download, Search, Upload, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

interface StatementSummary {
    id: number;
    original_filename: string;
    institution: string | null;
    account_label: string | null;
    account_name: string | null;
    period_start: string | null;
    period_end: string | null;
    size: number;
    parser: string | null;
    lines_truncated: boolean;
    text_truncated: boolean;
    parse_status: 'done' | 'pending_ai' | 'ai_failed';
    lines_count: number;
    matched_lines_count: number;
    unmatched_lines_count: number;
}

interface SearchLine {
    id: number;
    posted_on: string;
    amount: string;
    currency: string | null;
    description: TextSegment[];
    match_status: string;
}

interface SearchResult {
    id: number;
    original_filename: string;
    institution: string | null;
    account_label: string | null;
    account_name: string | null;
    period_start: string | null;
    period_end: string | null;
    snippet: TextSegment[] | null;
    lines_total: number;
    lines: SearchLine[];
}

interface Filters {
    q: string;
    date_from: string;
    date_to: string;
    institution: string;
    account_id: string;
    amount_min: string;
    amount_max: string;
}

interface Props {
    statements: PaginatedData<StatementSummary>;
    search: { results: SearchResult[]; truncated: boolean } | null;
    filters: Filters;
    accounts: { id: number; name: string; currency: string }[];
    institutions: string[];
    aiEnabled: boolean;
    maxUploadMb: number;
}

const props = defineProps<Props>();

const { formatDate } = useDate();
const { formatCurrency } = useCurrency();

// While the AI reads a file in the background, look for the result every few seconds.
const poll = usePoll(5000, { only: ['statements'] }, { autoStart: false });

watch(
    () => props.statements.data.some((s) => s.parse_status === 'pending_ai'),
    (waiting) => (waiting ? poll.start() : poll.stop()),
    { immediate: true },
);

const filters = ref<Filters>({ ...props.filters });
const showMoreFilters = ref(
    !!(
        props.filters.date_from ||
        props.filters.date_to ||
        props.filters.institution ||
        props.filters.account_id ||
        props.filters.amount_min ||
        props.filters.amount_max
    ),
);

const fileInput = ref<HTMLInputElement | null>(null);
const uploadForm = useForm({
    files: [] as File[],
    institution: '',
    account_label: '',
    account_id: '',
    period_start: '',
    period_end: '',
});

const fileErrors = computed(() =>
    Object.entries(uploadForm.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
);

function onFilesChosen(event: Event): void {
    const target = event.target as HTMLInputElement;
    uploadForm.files = target.files ? Array.from(target.files) : [];
}

function submitUpload(): void {
    uploadForm.post(store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function runSearch(): void {
    const query = Object.fromEntries(
        Object.entries(filters.value).filter(([, value]) => value !== ''),
    );

    router.get(index.url(), query, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearSearch(): void {
    filters.value = {
        q: '',
        date_from: '',
        date_to: '',
        institution: '',
        account_id: '',
        amount_min: '',
        amount_max: '',
    };
    runSearch();
}

function period(item: {
    period_start: string | null;
    period_end: string | null;
}): string {
    if (item.period_start && item.period_end) {
        return `${formatDate(item.period_start)} to ${formatDate(item.period_end)}`;
    }

    return 'Period unknown';
}

function origin(item: {
    institution: string | null;
    account_name: string | null;
    account_label: string | null;
}): string {
    return (
        [item.institution, item.account_name ?? item.account_label]
            .filter(Boolean)
            .join(' · ') || 'Unlabeled account'
    );
}

function money(amount: string, currency: string | null): string {
    return formatCurrency(Number(amount), currency ?? 'EUR');
}

const statusVariant: Record<
    string,
    'success' | 'warning' | 'secondary' | 'outline'
> = {
    matched: 'success',
    unmatched: 'warning',
    imported: 'secondary',
    ignored: 'outline',
};
</script>

<template>
    <Head title="Statements" />

    <div class="space-y-6">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-foreground">
                Statements
            </h1>
            <p class="mt-1 text-muted-foreground">
                Every bank statement you keep, searchable in one place, even
                from accounts that are not in Flusso.
            </p>
        </div>

        <Card>
            <CardContent class="space-y-4 pt-6">
                <form class="space-y-4" @submit.prevent="runSearch">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-3.5 left-3 h-4 w-4 text-muted-foreground"
                            />
                            <Input
                                v-model="filters.q"
                                class="pl-9"
                                type="search"
                                aria-label="Search statements"
                                placeholder="Search text and lines: a fine, a name, a reference…"
                                autocomplete="off"
                            />
                        </div>
                        <Button type="submit">Search</Button>
                        <Button
                            v-if="search"
                            type="button"
                            variant="outline"
                            @click="clearSearch"
                        >
                            <X class="h-4 w-4" />
                            Clear
                        </Button>
                    </div>

                    <button
                        type="button"
                        class="text-sm text-primary hover:underline"
                        @click="showMoreFilters = !showMoreFilters"
                    >
                        {{ showMoreFilters ? 'Hide filters' : 'More filters' }}
                    </button>

                    <div
                        v-if="showMoreFilters"
                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div class="space-y-2">
                            <Label for="date_from">From</Label>
                            <Input
                                id="date_from"
                                v-model="filters.date_from"
                                type="date"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="date_to">To</Label>
                            <Input
                                id="date_to"
                                v-model="filters.date_to"
                                type="date"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="filter_institution">Institution</Label>
                            <Input
                                id="filter_institution"
                                v-model="filters.institution"
                                list="institution-options"
                                autocomplete="off"
                            />
                            <datalist id="institution-options">
                                <option
                                    v-for="name in institutions"
                                    :key="name"
                                    :value="name"
                                />
                            </datalist>
                        </div>
                        <div class="space-y-2">
                            <Label for="filter_account">Account</Label>
                            <select
                                id="filter_account"
                                v-model="filters.account_id"
                                class="flex h-11 w-full rounded-xl border border-input bg-background px-4 py-2 text-base focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                            >
                                <option value="">All accounts</option>
                                <option
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="String(account.id)"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <Label for="amount_min">Amount from</Label>
                            <Input
                                id="amount_min"
                                v-model="filters.amount_min"
                                type="number"
                                step="0.01"
                                min="0"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="amount_max">Amount to</Label>
                            <Input
                                id="amount_max"
                                v-model="filters.amount_max"
                                type="number"
                                step="0.01"
                                min="0"
                            />
                        </div>
                    </div>
                </form>
            </CardContent>
        </Card>

        <div v-if="search" class="space-y-3">
            <h2 class="text-lg font-semibold text-foreground">
                {{ search.results.length }}
                {{ search.results.length === 1 ? 'statement' : 'statements' }}
                found
            </h2>

            <EmptyState
                v-if="search.results.length === 0"
                title="Nothing found"
                description="Try fewer or shorter words, or widen the filters. Scanned PDFs without text cannot be searched."
                :icon="Search"
            />

            <Card v-for="result in search.results" :key="result.id" hover>
                <CardContent class="space-y-3 pt-6">
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="show.url(result.id)"
                                class="font-semibold break-all text-foreground hover:text-primary"
                            >
                                {{ result.original_filename }}
                            </Link>
                            <p class="text-sm text-muted-foreground">
                                {{ origin(result) }} · {{ period(result) }}
                            </p>
                        </div>
                        <Button
                            :as="'a'"
                            :href="download.url(result.id)"
                            variant="outline"
                            size="sm"
                        >
                            <Download class="h-4 w-4" />
                            File
                        </Button>
                    </div>

                    <p
                        v-if="result.snippet"
                        class="rounded-lg bg-muted/50 p-3 text-sm break-words text-muted-foreground"
                    >
                        <HighlightedText :segments="result.snippet" />
                    </p>

                    <ul
                        v-if="result.lines.length"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="line in result.lines"
                            :key="line.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-sm"
                        >
                            <span class="w-28 shrink-0 text-muted-foreground">
                                {{ formatDate(line.posted_on) }}
                            </span>
                            <span class="min-w-0 flex-1 break-words">
                                <HighlightedText :segments="line.description" />
                            </span>
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
                        </li>
                    </ul>
                    <Link
                        v-if="result.lines_total > result.lines.length"
                        :href="show.url(result.id)"
                        class="text-sm text-primary hover:underline"
                    >
                        {{ result.lines_total - result.lines.length }} more
                        matching lines
                    </Link>
                </CardContent>
            </Card>

            <p v-if="search.truncated" class="text-sm text-muted-foreground">
                Only the first results are shown. Narrow the search to see the
                rest.
            </p>
        </div>

        <Card>
            <CardContent class="pt-6">
                <form class="space-y-4" @submit.prevent="submitUpload">
                    <div>
                        <h2 class="text-lg font-semibold text-foreground">
                            Add statements
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            PDF or CSV, up to {{ maxUploadMb }} MB each. Files
                            are kept privately and never copied into your
                            transactions.
                            <span v-if="aiEnabled">
                                Unusual layouts are read with your AI model.
                            </span>
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="files">Files</Label>
                        <input
                            id="files"
                            ref="fileInput"
                            type="file"
                            multiple
                            accept=".pdf,.csv,application/pdf,text/csv"
                            class="block w-full text-sm text-muted-foreground file:mr-4 file:rounded-lg file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary"
                            @change="onFilesChosen"
                        />
                        <p
                            v-for="message in fileErrors"
                            :key="message"
                            class="text-sm text-destructive"
                        >
                            {{ message }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="institution">Bank (optional)</Label>
                            <Input
                                id="institution"
                                v-model="uploadForm.institution"
                                :error="uploadForm.errors.institution"
                                placeholder="e.g. Revolut"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="account_label">
                                Account name (optional)
                            </Label>
                            <Input
                                id="account_label"
                                v-model="uploadForm.account_label"
                                :error="uploadForm.errors.account_label"
                                placeholder="For accounts not in Flusso"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="account_id">
                                Flusso account (optional)
                            </Label>
                            <select
                                id="account_id"
                                v-model="uploadForm.account_id"
                                class="flex h-11 w-full rounded-xl border border-input bg-background px-4 py-2 text-base focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                            >
                                <option value="">None</option>
                                <option
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="String(account.id)"
                                >
                                    {{ account.name }}
                                </option>
                            </select>
                            <p
                                v-if="uploadForm.errors.account_id"
                                class="text-sm text-destructive"
                            >
                                {{ uploadForm.errors.account_id }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="period_start">Period start</Label>
                            <Input
                                id="period_start"
                                v-model="uploadForm.period_start"
                                type="date"
                                :error="uploadForm.errors.period_start"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="period_end">Period end</Label>
                            <Input
                                id="period_end"
                                v-model="uploadForm.period_end"
                                type="date"
                                :error="uploadForm.errors.period_end"
                            />
                        </div>
                    </div>

                    <Button
                        type="submit"
                        :loading="uploadForm.processing"
                        :disabled="uploadForm.files.length === 0"
                    >
                        <Upload class="h-4 w-4" />
                        Add to vault
                    </Button>
                </form>
            </CardContent>
        </Card>

        <div class="space-y-3">
            <h2 class="text-lg font-semibold text-foreground">Your vault</h2>

            <EmptyState
                v-if="statements.data.length === 0"
                title="No statements yet"
                description="Add the PDFs or CSVs your banks give you and find anything in them later."
                :icon="Archive"
            />

            <Card
                v-for="statement in statements.data"
                :key="statement.id"
                hover
            >
                <CardContent
                    class="flex flex-wrap items-center justify-between gap-3 py-4"
                >
                    <div class="min-w-0">
                        <Link
                            :href="show.url(statement.id)"
                            class="font-semibold break-all text-foreground hover:text-primary"
                        >
                            {{ statement.original_filename }}
                        </Link>
                        <p class="text-sm text-muted-foreground">
                            {{ origin(statement) }} · {{ period(statement) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            v-if="statement.parse_status === 'pending_ai'"
                            variant="secondary"
                        >
                            Reading with AI…
                        </Badge>
                        <Badge
                            v-else-if="statement.parse_status === 'ai_failed'"
                            variant="destructive"
                        >
                            AI could not read this file
                        </Badge>
                        <Badge
                            v-if="
                                statement.lines_truncated ||
                                statement.text_truncated
                            "
                            variant="warning"
                            title="Not everything was read; the file itself is kept in full."
                        >
                            Partly read
                        </Badge>
                        <Badge
                            v-if="statement.lines_count === 0"
                            variant="outline"
                        >
                            Text only
                        </Badge>
                        <template v-else>
                            <Badge variant="secondary">
                                {{ statement.lines_count }} lines
                            </Badge>
                            <Badge
                                v-if="statement.matched_lines_count"
                                variant="success"
                            >
                                {{ statement.matched_lines_count }} matched
                            </Badge>
                            <Badge
                                v-if="statement.unmatched_lines_count"
                                variant="warning"
                            >
                                {{ statement.unmatched_lines_count }} not in
                                Flusso
                            </Badge>
                        </template>
                        <Button
                            :as="'a'"
                            :href="download.url(statement.id)"
                            variant="outline"
                            size="sm"
                        >
                            <Download class="h-4 w-4" />
                            File
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div
                v-if="statements.last_page > 1"
                class="flex items-center justify-between pt-2"
            >
                <p class="text-sm text-muted-foreground">
                    {{ statements.from }} to {{ statements.to }} of
                    {{ statements.total }}
                </p>
                <div class="flex gap-2">
                    <Button
                        v-if="statements.prev_page_url"
                        :as="Link"
                        :href="statements.prev_page_url"
                        preserve-scroll
                        variant="outline"
                        size="sm"
                    >
                        Previous
                    </Button>
                    <Button
                        v-if="statements.next_page_url"
                        :as="Link"
                        :href="statements.next_page_url"
                        preserve-scroll
                        variant="outline"
                        size="sm"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
