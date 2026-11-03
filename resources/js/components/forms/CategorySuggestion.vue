<script setup lang="ts">
import { debounce } from '@/lib/utils';
import { suggestCategory } from '@/routes/transactions';
import type { Category } from '@/types';
import type { CategorySuggestion } from '@/types/suggestions';
import { useHttp } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    description: string;
    type: 'income' | 'expense' | 'transfer';
    categories: Category[];
    /** A category is already chosen: nothing to suggest. */
    hasCategory: boolean;
}>();

const emit = defineEmits<{
    accept: [categoryId: number];
}>();

const DEBOUNCE_MS = 600;
const MIN_LENGTH = 3;

const http = useHttp();
const suggestion = ref<CategorySuggestion | null>(null);
// Only the newest request may set the suggestion.
let latestRequest = 0;
let active = true;

const category = computed(() =>
    suggestion.value
        ? props.categories.find(
              (c) =>
                  c.id === suggestion.value?.category_id &&
                  c.type === props.type,
          )
        : undefined,
);

async function fetchSuggestion() {
    const request = ++latestRequest;
    const text = props.description.trim();

    if (
        !active ||
        props.hasCategory ||
        props.type === 'transfer' ||
        text.length < MIN_LENGTH
    ) {
        return;
    }

    try {
        const response = (await http.submit(
            suggestCategory({
                query: { description: text.slice(0, 200), type: props.type },
            }),
        )) as { suggestion: CategorySuggestion | null };

        if (request === latestRequest) {
            suggestion.value = response.suggestion;
        }
    } catch {
        // A suggestion is a convenience: the form works without it.
        if (request === latestRequest) {
            suggestion.value = null;
        }
    }
}

const debouncedFetch = debounce(fetchSuggestion, DEBOUNCE_MS);

watch(
    () => [props.description, props.type],
    () => {
        // The old suggestion no longer fits what is typed.
        latestRequest++;
        suggestion.value = null;
        debouncedFetch();
    },
);

onBeforeUnmount(() => {
    active = false;
    latestRequest++;
});
</script>

<template>
    <div
        v-if="suggestion && category && !hasCategory"
        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
    >
        <span class="text-muted-foreground">Suggested:</span>
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary transition-colors hover:bg-primary/20"
            @click="emit('accept', suggestion.category_id)"
        >
            <Sparkles v-if="suggestion.source === 'ai'" class="h-3 w-3" />
            {{ category.name }}
        </button>
        <span class="text-xs text-muted-foreground">
            {{ suggestion.source === 'ai' ? 'AI: ' : ''
            }}{{ suggestion.reason }}
        </span>
    </div>
</template>
