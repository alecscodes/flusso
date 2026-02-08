<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Create Account" />

    <AuthLayout
        title="Create your account"
        description="Start managing your finances today"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="name" required>Full Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="John Doe"
                    autocomplete="name"
                    :error="form.errors.name"
                    required
                    autofocus
                />
            </div>

            <div class="space-y-2">
                <Label for="email" required>Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    placeholder="you@example.com"
                    autocomplete="email"
                    :error="form.errors.email"
                    required
                />
            </div>

            <div class="space-y-2">
                <Label for="password" required>Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    :error="form.errors.password"
                    required
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
                Create Account
            </Button>
        </form>

        <template #footer>
            <p class="text-sm text-muted-foreground">
                Already have an account?
                <Link
                    href="/login"
                    class="font-medium text-primary hover:underline"
                >
                    Sign in
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
