<script setup lang="ts">
import { cn, getInitials } from '@/lib/utils';
import { computed, ref } from 'vue';

interface Props {
    src?: string | null;
    alt?: string;
    fallback?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
});

const imageError = ref(false);

const sizeClasses = {
    sm: 'h-8 w-8 text-xs',
    md: 'h-10 w-10 text-sm',
    lg: 'h-12 w-12 text-base',
    xl: 'h-16 w-16 text-lg',
};

const classes = computed(() =>
    cn(
        'relative flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary/10 text-primary font-semibold',
        sizeClasses[props.size],
        props.class
    )
);

const initials = computed(() => {
    if (props.fallback) return props.fallback;
    if (props.alt) return getInitials(props.alt);
    return '?';
});

const showImage = computed(() => props.src && !imageError.value);
</script>

<template>
    <div :class="classes">
        <img
            v-if="showImage"
            :src="src!"
            :alt="alt"
            class="aspect-square h-full w-full object-cover"
            @error="imageError = true"
        />
        <span v-else>{{ initials }}</span>
    </div>
</template>
