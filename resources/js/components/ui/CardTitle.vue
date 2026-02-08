<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    class?: string;
    as?: string;
}

const props = withDefaults(defineProps<Props>(), {
    as: 'h3',
});

const classes = computed(() =>
    cn('text-xl font-semibold leading-none tracking-tight', props.class)
);
</script>

<template>
    <component
        :is="as"
        :class="classes"
    >
        <slot />
    </component>
</template>
