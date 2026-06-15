<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';

setLayoutProps({
    title: 'Forgot your password?',
    description: "No worries, we'll send you reset instructions",
});

interface Props {
    status?: string;
}

defineProps<Props>();
</script>

<template>
    <Head title="Forgot Password" />

    <div
        v-if="status"
        class="mb-6 rounded-xl bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
    >
        {{ status }}
    </div>

    <Form
        v-bind="email.form()"
        v-slot="{ errors, processing }"
        class="space-y-5"
    >
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
                autofocus
            />
        </div>

        <Button type="submit" class="w-full" :loading="processing">
            Send Reset Link
        </Button>
    </Form>

    <div class="mt-6 text-center">
        <Link
            :href="login().url"
            class="text-sm font-medium text-primary hover:underline"
        >
            Back to sign in
        </Link>
    </div>
</template>
