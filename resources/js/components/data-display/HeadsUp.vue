<script setup lang="ts">
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui';
import { useDate } from '@/composables';
import { read as readAlerts } from '@/routes/liquidity-alerts';
import type { Liquidity } from '@/types';
import { router } from '@inertiajs/vue3';
import { AlertCircle, Bell } from '@lucide/vue';

interface Props {
    liquidity: Liquidity;
}

defineProps<Props>();

const { formatDate } = useDate();

function markAlertsRead() {
    router.post(readAlerts().url, {}, { preserveScroll: true });
}
</script>

<template>
    <Card
        v-if="liquidity.at_risk.length > 0 || liquidity.alerts.length > 0"
        class="border-amber-200 dark:border-amber-900/50"
    >
        <CardHeader class="flex flex-row items-center justify-between">
            <CardTitle class="flex items-center gap-2">
                <AlertCircle class="h-5 w-5 text-amber-500" />
                Heads up
            </CardTitle>
            <span class="text-xs text-muted-foreground">
                Next {{ liquidity.days }} days
            </span>
        </CardHeader>
        <CardContent class="space-y-4">
            <ul v-if="liquidity.at_risk.length > 0" class="space-y-3">
                <li
                    v-for="account in liquidity.at_risk"
                    :key="account.account_id"
                    class="rounded-xl border border-border p-3"
                >
                    <p class="text-sm font-medium text-foreground">
                        {{ account.message }}
                    </p>
                    <ul
                        v-if="account.payments.length > 0"
                        class="mt-2 space-y-1 text-xs text-muted-foreground"
                    >
                        <li
                            v-for="payment in account.payments"
                            :key="payment.id"
                            class="flex justify-between gap-2"
                        >
                            <span>
                                {{ payment.name }}
                                <span
                                    v-if="payment.overdue"
                                    class="text-destructive"
                                >
                                    (overdue since
                                    {{ formatDate(payment.due_date) }})
                                </span>
                                <span v-else>
                                    ({{ formatDate(payment.due_date) }})
                                </span>
                            </span>
                            <span
                                :class="{
                                    'text-destructive': payment.rate_missing,
                                }"
                            >
                                {{ payment.amount }} {{ payment.currency }}
                                <template v-if="payment.rate_missing">
                                    (no rate)
                                </template>
                            </span>
                        </li>
                    </ul>
                </li>
            </ul>

            <div
                v-if="liquidity.alerts.length > 0"
                class="flex items-start justify-between gap-3 rounded-xl bg-muted/50 p-3"
            >
                <div class="space-y-1">
                    <p
                        class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                    >
                        <Bell class="h-3.5 w-3.5" />
                        New alerts
                    </p>
                    <p
                        v-for="alert in liquidity.alerts"
                        :key="alert.id"
                        class="text-sm text-foreground"
                    >
                        {{ alert.message }}
                    </p>
                </div>
                <Button variant="ghost" size="sm" @click="markAlertsRead">
                    Dismiss
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
