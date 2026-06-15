<script setup lang="ts">
import FinanceController from '@/actions/App/Http/Controllers/Settings/FinanceController';
import { Button, Input, Label } from '@/components/ui';
import { Form, Head } from '@inertiajs/vue3';

interface Props {
    resetDate: number | null;
    currentPeriod: {
        start: string;
        end: string;
    };
}

defineProps<Props>();
</script>

<template>
    <Head title="Finance Settings" />

    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-foreground">
                Finance Settings
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Configure how your financial data is calculated and displayed.
            </p>
        </div>

        <Form
            v-bind="FinanceController.update.form()"
            :options="{ preserveScroll: true }"
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="space-y-2">
                <Label for="reset_date">Salary / period start day</Label>
                <div class="flex max-w-xs items-center gap-3">
                    <Input
                        id="reset_date"
                        name="reset_date"
                        type="number"
                        min="1"
                        max="31"
                        class="w-20 text-center tabular-nums"
                        :default-value="resetDate ?? 1"
                        :error="errors.reset_date"
                        aria-describedby="reset_date_hint"
                    />
                    <span
                        id="reset_date_hint"
                        class="text-sm text-muted-foreground"
                    >
                        of each month (1–31)
                    </span>
                </div>
                <p class="text-xs text-muted-foreground">
                    Your monthly period starts on this day. E.g. 15 = from 15th
                    to 14th next month. Affects dashboard summary.
                </p>
            </div>

            <div class="rounded-2xl border border-border bg-muted/30 p-6">
                <h3 class="text-sm font-medium text-foreground">
                    How it works
                </h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    If you set your reset date to the 15th, your monthly period
                    will run from the 15th of each month to the 14th of the next
                    month. This is useful if your salary arrives mid-month.
                </p>
                <p
                    v-if="currentPeriod"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    Current period:
                    {{ currentPeriod.start }} — {{ currentPeriod.end }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-4">
                <p
                    v-show="recentlySuccessful"
                    class="text-sm text-muted-foreground"
                >
                    Saved.
                </p>
                <Button type="submit" :loading="processing">
                    Save Settings
                </Button>
            </div>
        </Form>
    </div>
</template>
