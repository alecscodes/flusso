<script setup lang="ts">
import { Button, Checkbox, Input, Label } from '@/components/ui';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';

setLayoutProps({
    title: 'Welcome back',
    description: 'Sign in to your account to continue',
});

interface Props {
    status?: string;
    canRegister?: boolean;
}

withDefaults(defineProps<Props>(), {
    canRegister: false,
});

const remember = ref(false);
</script>

<template>
    <Head title="Sign In" />

    <div
        v-if="status"
        class="mb-6 rounded-xl bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
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

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <Label for="password" required>Password</Label>
                <Link
                    :href="request().url"
                    class="text-sm text-primary hover:underline"
                >
                    Forgot password?
                </Link>
            </div>
            <Input
                id="password"
                name="password"
                type="password"
                placeholder="••••••••"
                autocomplete="current-password"
                :error="errors.password"
                required
            />
        </div>

        <div class="flex items-center gap-2">
            <Checkbox id="remember" v-model="remember" />
            <Label for="remember" class="cursor-pointer">Remember me</Label>
            <input v-if="remember" type="hidden" name="remember" value="1" />
        </div>

        <Button type="submit" class="w-full" :loading="processing">
            Sign In
        </Button>
    </Form>

    <div v-if="canRegister" class="mt-6 text-center">
        <p class="text-sm text-muted-foreground">
            Don't have an account?
            <Link
                :href="register().url"
                class="font-medium text-primary hover:underline"
            >
                Create one
            </Link>
        </p>
    </div>
</template>
