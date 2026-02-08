<script setup lang="ts">
import { getCategoryIconComponent } from '@/lib/categoryIcons';
import { cn } from '@/lib/utils';
import type { Category } from '@/types';
import { computed } from 'vue';

interface Props {
    category: Category;
    size?: 'sm' | 'md' | 'lg';
    showIcon?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
    showIcon: true,
});

const iconComponent = computed(() =>
    getCategoryIconComponent(props.category.icon),
);

const sizeClasses = {
    sm: 'px-2 py-0.5 text-xs gap-1',
    md: 'px-2.5 py-1 text-sm gap-1.5',
    lg: 'px-3 py-1.5 text-base gap-2',
};

const iconSizes = {
    sm: 'h-3 w-3',
    md: 'h-3.5 w-3.5',
    lg: 'h-4 w-4',
};

const badgeClasses = computed(() =>
    cn(
        'inline-flex items-center rounded-full font-medium',
        sizeClasses[props.size],
        props.class,
    ),
);

const badgeStyle = computed(() => {
    const color = props.category.color || '#7c3aed';
    return {
        backgroundColor: `${color}15`,
        color: color,
    };
});
</script>

<template>
    <span :class="badgeClasses" :style="badgeStyle">
        <component
            v-if="showIcon && iconComponent"
            :is="iconComponent"
            :class="iconSizes[size]"
        />
        {{ category.name }}
    </span>
</template>
