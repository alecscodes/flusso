<script setup lang="ts">
export interface TextSegment {
    text: string;
    hit: boolean;
}

defineProps<{
    segments: TextSegment[];
}>();
</script>

<template>
    <span>
        <template v-for="(segment, index) in segments" :key="index">
            <mark
                v-if="segment.hit"
                class="rounded bg-amber-200/70 px-0.5 text-foreground dark:bg-amber-400/30"
                >{{ segment.text }}</mark
            >
            <template v-else>{{ segment.text }}</template>
        </template>
    </span>
</template>
