<script setup lang="ts">
import { Button, Checkbox, Label } from '@/components/ui';
import { edit } from '@/routes/registration';
import { Form, Head } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Props {
    registration_enabled: boolean;
}

const props = defineProps<Props>();

const registrationEnabled = ref<boolean>(props.registration_enabled);

watch(
    () => props.registration_enabled,
    (newValue) => {
        registrationEnabled.value = newValue;
    },
);
</script>

<template>
    <Head title="Registration Settings" />

    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-foreground">
                Registration Settings
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Control whether users can register new accounts
            </p>
        </div>

        <Form
            :action="edit().url"
            method="patch"
            :options="{ preserveScroll: true }"
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div
                class="flex flex-row items-start gap-3 rounded-xl border border-border bg-muted/30 p-4"
            >
                <Checkbox
                    id="registration_enabled"
                    v-model="registrationEnabled"
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
                        When enabled, the /register route is active and users
                        can create new accounts. When disabled, registration
                        routes and functions are inactive. Registration is still
                        allowed only if no users exist in the system (for
                        initial setup).
                    </p>
                    <p
                        v-if="errors.registration_enabled"
                        class="text-sm text-destructive"
                    >
                        {{ errors.registration_enabled }}
                    </p>
                </div>
            </div>

            <input
                type="hidden"
                name="registration_enabled"
                :value="registrationEnabled ? '1' : '0'"
            />

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
