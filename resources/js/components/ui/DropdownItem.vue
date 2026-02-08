<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    as?: string;
    destructive?: boolean;
    disabled?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
    destructive: false,
    disabled: false,
});

const classes = computed(() =>
    cn(
        'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors',
        'focus:outline-none focus:bg-accent',
        props.destructive
            ? 'text-destructive hover:bg-destructive/10'
            : 'text-foreground hover:bg-accent',
        props.disabled && 'pointer-events-none opacity-50',
        props.class
    )
);
</script>

<template>
    <component
        :is="as"
        :class="classes"
        :disabled="disabled"
    >
        <slot />
    </component>
</template>
