<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    orientation?: 'horizontal' | 'vertical';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    orientation: 'horizontal',
});

const classes = computed(() =>
    cn(
        'shrink-0 bg-border',
        props.orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px',
        props.class
    )
);
</script>

<template>
    <div
        role="separator"
        :aria-orientation="orientation"
        :class="classes"
    />
</template>
