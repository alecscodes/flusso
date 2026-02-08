<script setup lang="ts">
import { Button, Checkbox, Label } from '@/components/ui';
import { SettingsLayout } from '@/layouts';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    registration_enabled: boolean;
}

const props = defineProps<Props>();

const form = useForm({
    registration_enabled: props.registration_enabled,
});

function updateSettings() {
    form.patch('/settings/registration', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Registration Settings" />

    <SettingsLayout>
        <div class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-foreground">
                    Registration Settings
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Control whether users can register new accounts
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="updateSettings">
                <div
                    class="flex flex-row items-start gap-3 rounded-xl border border-border bg-muted/30 p-4"
                >
                    <Checkbox
                        id="registration_enabled"
                        v-model="form.registration_enabled"
                        class="mt-0.5"
                    />
                    <div class="space-y-1">
                        <Label
                            for="registration_enabled"
                            class="cursor-pointer text-sm font-medium"
                        >
                            Enable user registration
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            When enabled, the /register route is active and
                            users can create new accounts. When disabled,
                            registration routes and functions are inactive.
                            Registration is still allowed only if no users exist
                            in the system (for initial setup).
                        </p>
                        <p
                            v-if="form.errors.registration_enabled"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.registration_enabled }}
                        </p>
                    </div>
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
