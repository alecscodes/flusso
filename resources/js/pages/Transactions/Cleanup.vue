<script setup lang="ts">
import { AmountDisplay } from '@/components/data-display';
import {
    Button,
    Card,
    CardContent,
    EmptyState,
    SearchableSelect,
} from '@/components/ui';
import { useDate } from '@/composables';
import {
    apply as cleanupApply,
    applyAll as cleanupApplyAll,
} from '@/routes/cleanup';
import {
    index as transactionsIndex,
    suggestCategory,
} from '@/routes/transactions';
import type { Category, PaginatedData } from '@/types';
import type { CategorySuggestion, CleanupRow } from '@/types/suggestions';
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ArrowLeft, CheckCheck, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

interface Props {
    transactions: PaginatedData<CleanupRow>;
    categories: Category[];
    aiEnabled: boolean;
}

const props = defineProps<Props>();

const { formatDate } = useDate();
const http = useHttp();

// The category picked per transaction, starting from the suggestion.
const choices = ref<Record<number, string>>({});
// What the model suggested for a row on request (history suggestions come with the page).
const aiSuggestions = ref<Record<number, CategorySuggestion | null>>({});
const asking = ref<number | null>(null);
const confirmingAll = ref(false);
const working = ref(false);

watch(
    () => props.transactions.data,
    (rows) => {
        for (const row of rows) {
            choices.value[row.id] ??=
                row.suggestion?.category_id.toString() ?? '';
        }
    },
    { immediate: true },
);

const historyMatches = computed(
    () => props.transactions.data.filter((row) => row.suggestion).length,
);

function optionsFor(row: CleanupRow) {
    return [
        { value: '', label: 'Choose a category' },
        ...props.categories
            .filter((c) => c.type === row.type)
            .map((c) => ({ value: c.id.toString(), label: c.name })),
    ];
}

function suggestionFor(row: CleanupRow): CategorySuggestion | null {
    return row.suggestion ?? aiSuggestions.value[row.id] ?? null;
}

async function askAi(row: CleanupRow) {
    asking.value = row.id;

    try {
        const response = (await http.submit(
            suggestCategory({
                query: {
                    description: (row.description ?? '').slice(0, 200),
                    type: row.type,
                },
            }),
        )) as { suggestion: CategorySuggestion | null };

        aiSuggestions.value[row.id] = response.suggestion;

        if (response.suggestion) {
            choices.value[row.id] = response.suggestion.category_id.toString();
        }
    } catch {
        aiSuggestions.value[row.id] = null;
    } finally {
        asking.value = null;
    }
}

function apply(row: CleanupRow) {
    const categoryId = choices.value[row.id];

    if (!categoryId) {
        return;
    }

    router.patch(
        cleanupApply.url(row.id),
        { category_id: Number(categoryId) },
        { preserveScroll: true },
    );
}

function applyAll() {
    working.value = true;
    router.post(
        cleanupApplyAll.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                working.value = false;
                confirmingAll.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Clean up transactions" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Clean up
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Transactions without a category. Nothing changes until you
                    apply it.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <Button
                    variant="outline"
                    :as="Link"
                    :href="transactionsIndex.url()"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Transactions
                </Button>
                <template v-if="historyMatches > 0">
                    <Button v-if="!confirmingAll" @click="confirmingAll = true">
                        <CheckCheck class="h-4 w-4" />
                        Apply all high-confidence
                    </Button>
                    <template v-else>
                        <Button :disabled="working" @click="applyAll">
                            Confirm: apply to every history match
                        </Button>
                        <Button
                            variant="ghost"
                            :disabled="working"
                            @click="confirmingAll = false"
                        >
                            Cancel
                        </Button>
                    </template>
                </template>
            </div>
        </div>

        <EmptyState
            v-if="transactions.data.length === 0"
            title="Everything has a category"
            description="Transactions you add without a category will show up here."
        />

        <Card v-else>
            <CardContent class="divide-y divide-border pt-2">
                <div
                    v-for="row in transactions.data"
                    :key="row.id"
                    class="flex flex-col gap-3 py-4 lg:flex-row lg:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-foreground">
                            {{ row.description || 'No description' }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDate(row.date) }} · {{ row.account.name }}
                        </p>
                    </div>
                    <AmountDisplay
                        :amount="row.amount"
                        :currency="row.currency"
                        :type="row.type"
                    />
                    <div class="w-full space-y-1 lg:w-64">
                        <SearchableSelect
                            :model-value="choices[row.id]"
                            @update:model-value="
                                (value) => (choices[row.id] = String(value))
                            "
                            :aria-label="`Category for ${row.description || 'transaction'}`"
                            placeholder="Choose a category"
                            search-placeholder="Search category..."
                            :options="optionsFor(row)"
                        />
                        <p
                            v-if="suggestionFor(row)"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                suggestionFor(row)?.source === 'ai'
                                    ? 'AI: '
                                    : ''
                            }}{{ suggestionFor(row)?.reason }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="
                                aiEnabled &&
                                !row.suggestion &&
                                row.description &&
                                aiSuggestions[row.id] === undefined
                            "
                            variant="outline"
                            size="sm"
                            :disabled="asking === row.id"
                            @click="askAi(row)"
                        >
                            <Sparkles class="h-4 w-4" />
                            Ask AI
                        </Button>
                        <Button
                            size="sm"
                            :disabled="!choices[row.id]"
                            @click="apply(row)"
                        >
                            Apply
                        </Button>
                    </div>
                </div>

                <div
                    v-if="transactions.last_page > 1"
                    class="flex items-center justify-between pt-4"
                >
                    <p class="text-sm text-muted-foreground">
                        Showing {{ transactions.from }} to
                        {{ transactions.to }} of
                        {{ transactions.total }} transactions
                    </p>
                    <div class="flex gap-2">
                        <Button
                            v-if="transactions.prev_page_url"
                            :as="Link"
                            :href="transactions.prev_page_url"
                            preserve-scroll
                            variant="outline"
                            size="sm"
                        >
                            Previous
                        </Button>
                        <Button
                            v-if="transactions.next_page_url"
                            :as="Link"
                            :href="transactions.next_page_url"
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
