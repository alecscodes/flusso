<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { useToast } from '@/composables';
import { SettingsLayout } from '@/layouts';
import { Head, useForm } from '@inertiajs/vue3';

const toast = useToast();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function updatePassword() {
    form.put('/settings/password', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            toast.success('Password updated successfully');
        },
        onError: () => {
            form.reset('current_password', 'password', 'password_confirmation');
        },
    });
}
</script>

<template>
    <Head title="Password Settings" />

    <SettingsLayout>
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

            <form class="space-y-4" @submit.prevent="updatePassword">
                <div class="space-y-2">
                    <Label for="current_password" required
                        >Current Password</Label
                    >
                    <Input
                        id="current_password"
                        v-model="form.current_password"
                        type="password"
                        autocomplete="current-password"
                        :error="form.errors.current_password"
                        required
                    />
                </div>

                <div class="space-y-2">
                    <Label for="password" required>New Password</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :error="form.errors.password"
                        required
                    />
                </div>

                <div class="space-y-2">
                    <Label for="password_confirmation" required
                        >Confirm New Password</Label
                    >
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        :error="form.errors.password_confirmation"
                        required
                    />
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :loading="form.processing">
                        Update Password
                    </Button>
                </div>
            </form>
        </div>
    </SettingsLayout>
</template>
