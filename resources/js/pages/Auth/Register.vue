<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';

setLayoutProps({
    title: 'Create your account',
    description: 'Start managing your finances today',
});
</script>

<template>
    <Head title="Create Account" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="space-y-5"
    >
        <div class="space-y-2">
            <Label for="name" required>Full Name</Label>
            <Input
                id="name"
                name="name"
                type="text"
                placeholder="John Doe"
                autocomplete="name"
                :error="errors.name"
                required
                autofocus
            />
        </div>

        <div class="space-y-2">
            <Label for="email" required>Email</Label>
            <Input
                id="email"
                name="email"
                type="email"
                placeholder="you@example.com"
                autocomplete="email"
                :error="errors.email"
                required
            />
        </div>

        <div class="space-y-2">
            <Label for="password" required>Password</Label>
            <Input
                id="password"
                name="password"
                type="password"
                placeholder="••••••••"
                autocomplete="new-password"
                :error="errors.password"
                required
            />
        </div>

        <div class="space-y-2">
            <Label for="password_confirmation" required>Confirm Password</Label>
            <Input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                placeholder="••••••••"
                autocomplete="new-password"
                :error="errors.password_confirmation"
                required
            />
        </div>

        <Button type="submit" class="w-full" :loading="processing">
            Create Account
        </Button>
    </Form>

    <div class="mt-6 text-center">
        <p class="text-sm text-muted-foreground">
            Already have an account?
            <Link
                :href="login().url"
                class="font-medium text-primary hover:underline"
            >
                Sign in
            </Link>
        </p>
    </div>
</template>
