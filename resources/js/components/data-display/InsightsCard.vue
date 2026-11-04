<script setup lang="ts">
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { index as insightsIndex } from '@/routes/insights';
import type { InsightsCard } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage();
const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

/** A deferred prop of the dashboard: undefined until it has loaded. */
const insights = computed(
    () => page.props.insights as InsightsCard | undefined,
);
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-center justify-between">
            <CardTitle class="flex items-center gap-2">
                <Sparkles class="h-5 w-5 text-primary" />
                Period insights
            </CardTitle>
            <Link :href="insightsIndex().url">
                <Button variant="ghost" size="sm">Details</Button>
            </Link>
        </CardHeader>
        <CardContent>
            <div v-if="insights" class="space-y-2">
                <p class="text-sm text-foreground">{{ insights.sentence }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ formatDate(insights.period.start) }} -
                    {{ formatDate(insights.period.end) }} · Net
                    {{
                        formatCurrency(insights.summary.net, insights.currency)
                    }}
                    <span v-if="insights.is_estimate">(estimate)</span>
                </p>
            </div>
            <div v-else class="animate-pulse space-y-2" aria-busy="true">
                <div class="h-4 w-3/4 rounded bg-muted" />
                <div class="h-3 w-1/2 rounded bg-muted" />
            </div>
        </CardContent>
    </Card>
</template>
