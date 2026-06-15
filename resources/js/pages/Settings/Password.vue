<script setup lang="ts">
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import { Button, Input, Label } from '@/components/ui';
import { Form, Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Password Settings" />

    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-foreground">
                Update Password
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Ensure your account is using a long, random password to stay
                secure.
            </p>
        </div>

        <Form
            v-bind="PasswordController.update.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-4"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="space-y-2">
                <Label for="current_password" required>Current Password</Label>
                <Input
                    id="current_password"
                    name="current_password"
                    type="password"
                    autocomplete="current-password"
                    :error="errors.current_password"
                    required
                />
            </div>

            <div class="space-y-2">
                <Label for="password" required>New Password</Label>
                <Input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    :error="errors.password"
                    required
                />
            </div>

            <div class="space-y-2">
                <Label for="password_confirmation" required
                    >Confirm New Password</Label
                >
                <Input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    :error="errors.password_confirmation"
                    required
                />
            </div>

            <div class="flex items-center justify-end gap-4">
                <p
                    v-show="recentlySuccessful"
                    class="text-sm text-muted-foreground"
                >
                    Saved.
                </p>
                <Button type="submit" :loading="processing">
                    Update Password
                </Button>
            </div>
        </Form>
    </div>
</template>
