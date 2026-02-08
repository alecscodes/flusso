<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    class?: string;
}

const props = defineProps<Props>();

const classes = computed(() => cn('animate-pulse rounded-xl bg-muted', props.class));
</script>

<template>
    <div :class="classes" />
</template>
