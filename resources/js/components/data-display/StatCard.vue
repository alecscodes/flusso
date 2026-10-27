<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { computed, type Component } from 'vue';

interface Props {
    title: string;
    value: string | number;
    subtitle?: string;
    trend?: 'up' | 'down' | 'neutral';
    trendValue?: string;
    icon?: Component;
    /** Overrides the default primary tint of the icon. */
    iconClass?: string;
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
        '@container relative min-w-0 overflow-hidden rounded-2xl border border-border bg-card p-4 sm:p-6',
        'transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-primary/5',
        props.class,
    ),
);
</script>

<template>
    <div :class="cardClasses">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <p
                    class="text-xs font-medium text-muted-foreground @[10rem]:text-sm"
                >
                    {{ title }}
                </p>
                <!-- Money is never cut off: the size follows the card's width and, at worst, the value wraps. -->
                <p
                    class="text-base font-bold tracking-tight break-words text-foreground tabular-nums @[10rem]:text-lg @[14rem]:text-xl @[20rem]:text-2xl @[24rem]:text-3xl"
                >
                    {{ value }}
                </p>
                <p v-if="subtitle" class="text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <div
                v-if="icon"
                :class="
                    cn(
                        'hidden shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary sm:p-3 @[14rem]:block',
                        iconClass,
                    )
                "
            >
                <component :is="icon" class="h-6 w-6" />
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
                <TrendingUp v-if="trend === 'up'" class="mr-1 h-3 w-3" />
                <TrendingDown
                    v-else-if="trend === 'down'"
                    class="mr-1 h-3 w-3"
                />
                {{ trendValue }}
            </span>
            <span class="text-xs text-muted-foreground">vs last period</span>
        </div>
        <div
            class="absolute -top-8 -right-8 h-32 w-32 rounded-full bg-primary/5"
        />
    </div>
</template>
