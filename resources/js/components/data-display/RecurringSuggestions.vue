<script setup lang="ts">
import { Badge, Button, Card, CardContent } from '@/components/ui';
import { useDate } from '@/composables';
import { intervalLabel } from '@/lib/utils';
import { dismiss } from '@/routes/planned-payments/suggestions';
import type { RecurringSuggestion } from '@/types';
import { router } from '@inertiajs/vue3';
import { ChevronDown, Lightbulb } from '@lucide/vue';
import { computed, ref } from 'vue';
import AmountDisplay from './AmountDisplay.vue';

interface Props {
    /** Undefined while the deferred prop is still loading. */
    suggestions?: RecurringSuggestion[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    create: [suggestion: RecurringSuggestion];
}>();

const { formatDate } = useDate();

const open = ref(false);
const loading = computed(() => props.suggestions === undefined);

function hide(
    suggestion: RecurringSuggestion,
    kind: 'not_recurring' | 'temporary',
) {
    router.post(
        dismiss().url,
        { key: suggestion.key, kind },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div v-if="loading || (suggestions?.length ?? 0) > 0" class="space-y-3">
        <button
            type="button"
            class="flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            :aria-expanded="open"
            @click="open = !open"
        >
            <ChevronDown
                :class="['h-4 w-4 transition-transform', open && 'rotate-180']"
            />
            <Lightbulb class="h-4 w-4" />
            Suggested<template v-if="!loading">
                ({{ suggestions?.length }})</template
            >
        </button>

        <div v-if="open" class="space-y-3">
            <p class="text-sm text-muted-foreground">
                Charges that keep coming back. Nothing is planned until you say
                so; hide the ones you are only trying out.
            </p>

            <div
                v-if="loading"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                aria-busy="true"
            >
                <Card v-for="n in 3" :key="n">
                    <CardContent class="animate-pulse space-y-3 py-4">
                        <div class="h-4 w-2/3 rounded bg-muted" />
                        <div class="h-6 w-1/3 rounded bg-muted" />
                        <div class="h-3 w-1/2 rounded bg-muted" />
                    </CardContent>
                </Card>
            </div>

            <div
                v-else
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Card v-for="suggestion in suggestions" :key="suggestion.key">
                    <CardContent class="space-y-3 py-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-foreground">
                                    {{ suggestion.label }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        intervalLabel(
                                            suggestion.interval_value,
                                            suggestion.interval_type,
                                        )
                                    }}
                                    · seen {{ suggestion.occurrences }} times
                                </p>
                            </div>
                            <Badge
                                v-if="suggestion.price_jumped"
                                variant="warning"
                            >
                                Price jumped
                            </Badge>
                        </div>

                        <AmountDisplay
                            :amount="suggestion.amount"
                            :currency="suggestion.currency"
                            type="expense"
                            :show-sign="false"
                        />
                        <p
                            v-if="suggestion.price_jumped"
                            class="text-sm text-amber-600 dark:text-amber-400"
                        >
                            Was {{ suggestion.previous_amount }}, last charge
                            {{ suggestion.amount }}
                            {{ suggestion.currency }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Last {{ formatDate(suggestion.last_date) }} · next
                            around {{ formatDate(suggestion.next_expected) }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                @click="emit('create', suggestion)"
                            >
                                Create planned payment
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="hide(suggestion, 'not_recurring')"
                            >
                                Not recurring
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="hide(suggestion, 'temporary')"
                            >
                                Temporary (hide for 6 months)
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
