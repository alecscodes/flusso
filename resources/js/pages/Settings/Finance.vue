<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { SettingsLayout } from '@/layouts';
import type { User } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user as User);

const form = useForm({
    reset_date: user.value?.reset_date?.toString() || '1',
});

function updateSettings() {
    form.patch('/settings/finance', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Finance Settings" />

    <SettingsLayout>
        <div class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-foreground">
                    Finance Settings
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Configure how your financial data is calculated and
                    displayed.
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="updateSettings">
                <div class="space-y-2">
                    <Label for="reset_date">Salary / period start day</Label>
                    <div class="flex max-w-xs items-center gap-3">
                        <Input
                            id="reset_date"
                            v-model="form.reset_date"
                            name="reset_date"
                            type="number"
                            min="1"
                            max="31"
                            class="w-20 text-center tabular-nums"
                            :error="form.errors.reset_date"
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
                        Your monthly period starts on this day. E.g. 15 = from
                        15th to 14th next month. Affects dashboard summary.
                    </p>
                </div>

                <div class="rounded-2xl border border-border bg-muted/30 p-6">
                    <h3 class="text-sm font-medium text-foreground">
                        How it works
                    </h3>
                    <p class="mt-2 text-sm text-muted-foreground">
                        If you set your reset date to the 15th, your monthly
                        period will run from the 15th of each month to the 14th
                        of the next month. This is useful if your salary arrives
                        mid-month.
                    </p>
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :loading="form.processing">
                        Save Settings
                    </Button>
                </div>
            </form>
        </div>
    </SettingsLayout>
</template>
