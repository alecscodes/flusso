<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { Button, Input, Label } from '@/components/ui';
import { Form, Head, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = page.props.auth.user;
</script>

<template>
    <Head title="Profile Settings" />

    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-foreground">
                Profile Information
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Update your account's profile information and email address.
            </p>
        </div>

        <Form
            v-bind="ProfileController.update.form()"
            :options="{ preserveScroll: true }"
            class="space-y-4"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="space-y-2">
                <Label for="name" required>Name</Label>
                <Input
                    id="name"
                    name="name"
                    type="text"
                    autocomplete="name"
                    :default-value="user.name"
                    :error="errors.name"
                    required
                />
            </div>

            <div class="space-y-2">
                <Label for="email" required>Email</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    :default-value="user.email"
                    :error="errors.email"
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
                    Save Changes
                </Button>
            </div>
        </Form>

        <div class="border-t border-border pt-8">
            <h2 class="text-xl font-semibold text-destructive">Danger Zone</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Once you delete your account, all of your data will be
                permanently removed.
            </p>

            <Form
                v-bind="ProfileController.destroy.form()"
                class="mt-4 space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="space-y-2">
                    <Label for="delete-password" required
                        >Confirm Password</Label
                    >
                    <Input
                        id="delete-password"
                        name="password"
                        type="password"
                        placeholder="Enter your password to confirm"
                        :error="errors.password"
                        required
                    />
                </div>

                <Button
                    type="submit"
                    variant="destructive"
                    :loading="processing"
                >
                    Delete Account
                </Button>
            </Form>
        </div>
    </div>
</template>
