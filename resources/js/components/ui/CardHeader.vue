<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    class?: string;
}

const props = defineProps<Props>();

const classes = computed(() => cn('flex flex-col space-y-1.5 p-6', props.class));
</script>

<template>
    <div :class="classes">
        <slot />
    </div>
</template>
