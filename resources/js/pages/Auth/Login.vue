<script setup lang="ts">
import { Button, Checkbox, Input, Label } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Props {
    status?: string;
    canRegister?: boolean;
}

withDefaults(defineProps<Props>(), {
    canRegister: false,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign In" />

    <AuthLayout
        title="Welcome back"
        description="Sign in to your account to continue"
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

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <Label for="password" required>Password</Label>
                    <Link
                        href="/forgot-password"
                        class="text-sm text-primary hover:underline"
                    >
                        Forgot password?
                    </Link>
                </div>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    :error="form.errors.password"
                    required
                />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="remember" v-model="form.remember" />
                <Label for="remember" class="cursor-pointer">Remember me</Label>
            </div>

            <Button type="submit" class="w-full" :loading="form.processing">
                Sign In
            </Button>
        </form>

        <template v-if="canRegister" #footer>
            <p class="text-sm text-muted-foreground">
                Don't have an account?
                <Link
                    href="/register"
                    class="font-medium text-primary hover:underline"
                >
                    Create one
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
