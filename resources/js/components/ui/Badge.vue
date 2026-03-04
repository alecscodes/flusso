<script setup lang="ts">
import { cn } from '@/lib/utils';
import { cva, type VariantProps } from 'class-variance-authority';
import { computed } from 'vue';

const badgeVariants = cva(
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors',
    {
        variants: {
            variant: {
                default: 'bg-primary/10 text-primary',
                secondary: 'bg-secondary text-secondary-foreground',
                destructive: 'bg-destructive/10 text-destructive',
                success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                outline: 'border border-border text-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    }
);

type BadgeVariants = VariantProps<typeof badgeVariants>;

interface Props {
    variant?: BadgeVariants['variant'];
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
});

const classes = computed(() => cn(badgeVariants({ variant: props.variant }), props.class));
</script>

<template>
    <span :class="classes">
        <slot />
    </span>
</template>
