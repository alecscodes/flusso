<script setup lang="ts">
import FinanceController from '@/actions/App/Http/Controllers/Settings/FinanceController';
import { Button, Checkbox, Input, Label } from '@/components/ui';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Props {
    resetDate: number | null;
    countAdvancesUntilSettled?: boolean;
    currentPeriod: {
        start: string;
        end: string;
    };
}

const props = defineProps<Props>();

const countNet = ref(props.countAdvancesUntilSettled ?? false);
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

            <div class="space-y-2">
                <div class="flex items-start gap-3">
                    <Checkbox id="count_net" v-model="countNet" />
                    <div>
                        <Label for="count_net">
                            Count advances only until they are settled
                        </Label>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Money you paid for other people stops counting as
                            your spending (and what they paid back as income)
                            once it is settled. Off by default.
                        </p>
                    </div>
                </div>
                <input
                    type="hidden"
                    name="count_advances_until_settled"
                    :value="countNet ? 1 : 0"
                />
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
