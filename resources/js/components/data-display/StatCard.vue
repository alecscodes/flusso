<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

interface Props {
    title: string;
    value: string | number;
    subtitle?: string;
    trend?: 'up' | 'down' | 'neutral';
    trendValue?: string;
    icon?: any;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    trend: 'neutral',
});

const trendColors = {
    up: 'text-emerald-600 dark:text-emerald-400',
    down: 'text-rose-600 dark:text-rose-400',
    neutral: 'text-muted-foreground',
};

const trendBgColors = {
    up: 'bg-emerald-500/10',
    down: 'bg-rose-500/10',
    neutral: 'bg-muted',
};

const cardClasses = computed(() =>
    cn(
        'relative overflow-hidden rounded-2xl border border-border bg-card p-6',
        'transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-primary/5',
        props.class,
    ),
);
</script>

<template>
    <div :class="cardClasses">
        <div class="flex items-start justify-between">
            <div class="space-y-1">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ title }}
                </p>
                <p class="text-3xl font-bold tracking-tight text-foreground">
                    {{ value }}
                </p>
                <p v-if="subtitle" class="text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="icon" class="rounded-xl bg-primary/10 p-3">
                <component :is="icon" class="h-6 w-6 text-primary" />
            </div>
        </div>
        <div v-if="trendValue" class="mt-4 flex items-center gap-2">
            <span
                :class="[
                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                    trendBgColors[trend],
                    trendColors[trend],
                ]"
            >
                <svg
                    v-if="trend === 'up'"
                    class="mr-1 h-3 w-3"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M7 17l9.2-9.2M17 17V7H7"
                    />
                </svg>
                <svg
                    v-else-if="trend === 'down'"
                    class="mr-1 h-3 w-3"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M17 7l-9.2 9.2M7 7v10h10"
                    />
                </svg>
                {{ trendValue }}
            </span>
            <span class="text-xs text-muted-foreground">vs last period</span>
        </div>
        <div
            class="absolute -top-8 -right-8 h-32 w-32 rounded-full bg-primary/5"
        />
    </div>
</template>
