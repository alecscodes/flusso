<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    class?: string;
    hover?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    hover: false,
});

const cardClasses = computed(() =>
    cn(
        'rounded-2xl border border-border bg-card text-card-foreground shadow-sm',
        props.hover &&
            'transition-all duration-300 hover:shadow-lg hover:shadow-primary/5 hover:-translate-y-1',
        props.class
    )
);
</script>

<template>
    <div :class="cardClasses">
        <slot />
    </div>
</template>
