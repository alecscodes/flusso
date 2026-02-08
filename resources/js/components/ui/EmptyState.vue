<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { Component } from 'vue';
import { computed } from 'vue';

interface Props {
    title: string;
    description?: string;
    icon?: Component;
    class?: string;
}

const props = defineProps<Props>();

const classes = computed(() =>
    cn(
        'flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-border bg-muted/30 p-12 text-center',
        props.class
    )
);
</script>

<template>
    <div :class="classes">
        <div
            v-if="icon"
            class="mb-4 rounded-full bg-primary/10 p-4"
        >
            <component
                :is="icon"
                class="h-8 w-8 text-primary"
            />
        </div>
        <h3 class="text-lg font-semibold text-foreground">{{ title }}</h3>
        <p
            v-if="description"
            class="mt-1 max-w-sm text-sm text-muted-foreground"
        >
            {{ description }}
        </p>
        <div
            v-if="$slots.action"
            class="mt-6"
        >
            <slot name="action" />
        </div>
    </div>
</template>
