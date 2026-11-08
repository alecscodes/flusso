<script setup lang="ts">
import { Button } from '@/components/ui';
import { useDate } from '@/composables';
import { restore } from '@/routes/planned-payments/suggestions';
import type { DismissedSuggestion } from '@/types';
import { router } from '@inertiajs/vue3';
import { ChevronDown, EyeOff } from '@lucide/vue';
import { ref } from 'vue';

interface Props {
    dismissed: DismissedSuggestion[];
}

defineProps<Props>();

const { formatDate } = useDate();

const open = ref(false);

function showAgain(item: DismissedSuggestion) {
    router.delete(restore({ key: item.key }).url, { preserveScroll: true });
}
</script>

<template>
    <div v-if="dismissed.length > 0" class="space-y-3">
        <button
            type="button"
            class="flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            :aria-expanded="open"
            @click="open = !open"
        >
            <ChevronDown
                :class="['h-4 w-4 transition-transform', open && 'rotate-180']"
            />
            <EyeOff class="h-4 w-4" />
            Dismissed ({{ dismissed.length }})
        </button>

        <ul v-if="open" class="divide-y rounded-lg border">
            <li
                v-for="item in dismissed"
                :key="item.key"
                class="flex items-center justify-between gap-3 px-4 py-3"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium text-foreground">
                        {{ item.label }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="item.snooze_until">
                            Hidden until {{ formatDate(item.snooze_until) }}
                        </template>
                        <template v-else>Not recurring</template>
                    </p>
                </div>
                <Button variant="outline" size="sm" @click="showAgain(item)">
                    Show again
                </Button>
            </li>
        </ul>
    </div>
</template>
