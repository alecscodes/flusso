<script setup lang="ts">
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
import {
    destroy,
    download,
    index,
    reparse,
    show,
    update,
} from '@/routes/statements';
import type { PaginatedData } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download, FileText, RefreshCw, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

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
    lines_count: number;
    parsed_at: string | null;
    has_text: boolean;
    counts: Record<string, number>;
    can_reparse: boolean;
}

interface Line {
    id: number;
    posted_on: string;
    amount: string;
    currency: string | null;
    description: string;
    parsed_by: string;
    match_status: string;
    matched_transaction: Candidate | null;
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
                <ul class="divide-y divide-border">
                    <li v-for="line in lines.data" :key="line.id" class="py-3">
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                        >
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
