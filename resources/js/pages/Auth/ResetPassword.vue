<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    email: string;
    token: string;
}

const props = defineProps<Props>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Reset Password" />

    <AuthLayout
        title="Reset your password"
        description="Enter your new password below"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="email" required>Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    :error="form.errors.email"
                    required
                    readonly
                />
            </div>

            <div class="space-y-2">
                <Label for="password" required>New Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    :error="form.errors.password"
                    required
                    autofocus
                />
            </div>

            <div class="space-y-2">
                <Label for="password_confirmation" required
                    >Confirm Password</Label
                >
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    :error="form.errors.password_confirmation"
                    required
                />
            </div>

            <Button type="submit" class="w-full" :loading="form.processing">
                Reset Password
            </Button>
        </form>
    </AuthLayout>
</template>
