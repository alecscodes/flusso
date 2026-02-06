<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    for?: string;
    required?: boolean;
    class?: string;
}

const props = defineProps<Props>();

const labelClasses = computed(() =>
    cn(
        'text-sm font-medium leading-none text-foreground peer-disabled:cursor-not-allowed peer-disabled:opacity-70',
        props.class
    )
);
</script>

<template>
    <label
        :for="props.for"
        :class="labelClasses"
    >
        <slot />
        <span
            v-if="required"
            class="ml-1 text-destructive"
            >*</span
        >
    </label>
</template>
