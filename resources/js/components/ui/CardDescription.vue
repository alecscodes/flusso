<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    class?: string;
}

const props = defineProps<Props>();

const classes = computed(() => cn('text-sm text-muted-foreground', props.class));
</script>

<template>
    <p :class="classes">
        <slot />
    </p>
</template>
