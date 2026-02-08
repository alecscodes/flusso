<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    class?: string;
}

const props = defineProps<Props>();

const classes = computed(() => cn('p-6 pt-0', props.class));
</script>

<template>
    <div :class="classes">
        <slot />
    </div>
</template>
