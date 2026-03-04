<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Props {
    status?: string;
}

defineProps<Props>();

const form = useForm({
    email: '',
});

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <Head title="Forgot Password" />

    <AuthLayout
        title="Forgot your password?"
        description="No worries, we'll send you reset instructions"
    >
        <div
            v-if="status"
            class="mb-6 rounded-xl bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ status }}
        </div>

        <form class="space-y-5" @submit.prevent="submit">
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
                    autofocus
                />
            </div>

            <Button type="submit" class="w-full" :loading="form.processing">
                Send Reset Link
            </Button>
        </form>

        <template #footer>
            <Link
                href="/login"
                class="text-sm font-medium text-primary hover:underline"
            >
                Back to sign in
            </Link>
        </template>
    </AuthLayout>
</template>
