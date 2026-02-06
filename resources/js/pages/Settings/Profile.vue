<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { useToast } from '@/composables';
import { SettingsLayout } from '@/layouts';
import type { User } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const toast = useToast();

const user = computed(() => page.props.auth?.user as User);

const profileForm = useForm({
    name: user.value?.name || '',
    email: user.value?.email || '',
});

function updateProfile() {
    profileForm.patch('/settings/profile', {
        preserveScroll: true,
        onSuccess: () => toast.success('Profile updated successfully'),
    });
}

const deleteForm = useForm({
    password: '',
});

function deleteAccount() {
    if (
        confirm(
            'Are you sure you want to delete your account? This action cannot be undone.',
        )
    ) {
        deleteForm.delete('/settings/profile', {
            onSuccess: () => toast.success('Account deleted'),
        });
    }
}
</script>

<template>
    <Head title="Profile Settings" />

    <SettingsLayout>
        <div class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-foreground">
                    Profile Information
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Update your account's profile information and email address.
                </p>
            </div>

            <form class="space-y-4" @submit.prevent="updateProfile">
                <div class="space-y-2">
                    <Label for="name" required>Name</Label>
                    <Input
                        id="name"
                        v-model="profileForm.name"
                        type="text"
                        autocomplete="name"
                        :error="profileForm.errors.name"
                        required
                    />
                </div>

                <div class="space-y-2">
                    <Label for="email" required>Email</Label>
                    <Input
                        id="email"
                        v-model="profileForm.email"
                        type="email"
                        autocomplete="email"
                        :error="profileForm.errors.email"
                        required
                    />
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :loading="profileForm.processing">
                        Save Changes
                    </Button>
                </div>
            </form>

            <div class="border-t border-border pt-8">
                <h2 class="text-xl font-semibold text-destructive">
                    Danger Zone
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Once you delete your account, all of your data will be
                    permanently removed.
                </p>

                <form class="mt-4 space-y-4" @submit.prevent="deleteAccount">
                    <div class="space-y-2">
                        <Label for="delete-password" required
                            >Confirm Password</Label
                        >
                        <Input
                            id="delete-password"
                            v-model="deleteForm.password"
                            type="password"
                            placeholder="Enter your password to confirm"
                            :error="deleteForm.errors.password"
                            required
                        />
                    </div>

                    <Button
                        type="submit"
                        variant="destructive"
                        :loading="deleteForm.processing"
                    >
                        Delete Account
                    </Button>
                </form>
            </div>
        </div>
    </SettingsLayout>
</template>
