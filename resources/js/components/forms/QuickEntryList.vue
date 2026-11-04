<script setup lang="ts">
import { Button } from '@/components/ui';
import { useCurrency } from '@/composables';
import type { Account, Category } from '@/types';
import type { QuickEntry } from '@/types/suggestions';
import { Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    entries: QuickEntry[];
    accounts: Account[];
    categories: Category[];
    /** Index of the entry being saved right now. */
    savingIndex: number | null;
}>();

const emit = defineEmits<{
    load: [index: number];
    save: [index: number];
}>();

const { formatCurrency } = useCurrency();
// An entry is only saved on the second tap, so a stray tap never books anything.
const confirming = ref<number | null>(null);

const rows = computed(() =>
    props.entries.map((entry, index) => {
        const account = props.accounts.find((a) => a.id === entry.account_id);
        const category = props.categories.find(
            (c) => c.id === entry.category_id && c.type === entry.type,
        );

        return {
            index,
            entry,
            account,
            category,
            label: entry.description || 'No description',
            amount: formatCurrency(entry.amount, account?.currency ?? 'EUR'),
            canSave: !!account,
        };
    }),
);

function save(index: number) {
    if (confirming.value !== index) {
        confirming.value = index;

        return;
    }

    confirming.value = null;
    emit('save', index);
}

function load(index: number) {
    confirming.value = null;
    emit('load', index);
}
</script>

<template>
    <ul class="space-y-2">
        <li
            v-for="row in rows"
            :key="`${row.index}-${row.entry.amount}-${row.entry.description}`"
            class="rounded-xl border border-border bg-muted/30 p-3 text-sm"
        >
            <div class="flex items-start justify-between gap-3">
                <span class="min-w-0 truncate font-medium">{{
                    row.label
                }}</span>
                <span
                    :class="[
                        'shrink-0 font-semibold',
                        row.entry.type === 'income'
                            ? 'text-emerald-600'
                            : 'text-rose-600',
                    ]"
                >
                    {{ row.entry.type === 'income' ? '+' : '-'
                    }}{{ row.amount }}
                </span>
            </div>
            <p
                class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
            >
                <span>{{ row.account?.name ?? 'No account found' }}</span>
                <span v-if="row.category" class="inline-flex items-center gap-1">
                    <Sparkles
                        v-if="row.entry.source === 'ai'"
                        class="h-3 w-3"
                    />
                    {{ row.category.name }}
                </span>
                <span>{{ row.entry.date }}</span>
            </p>
            <div class="mt-2 flex flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="load(row.index)"
                >
                    Edit in form
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :disabled="!row.canSave || savingIndex !== null"
                    :title="
                        row.canSave ? undefined : 'Choose an account first'
                    "
                    @click="save(row.index)"
                >
                    {{ confirming === row.index ? 'Tap again to save' : 'Save' }}
                </Button>
            </div>
        </li>
    </ul>
</template>
