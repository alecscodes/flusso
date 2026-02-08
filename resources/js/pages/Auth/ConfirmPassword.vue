<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';

const form = useForm({
    password: '',
});

function submit() {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Confirm Password" />

    <AuthLayout>
        <div class="text-center">
            <div
                class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10"
            >
                <Lock class="h-8 w-8 text-primary" />
            </div>

            <h1 class="text-2xl font-bold text-foreground">
                Confirm your password
            </h1>
            <p class="mt-2 text-muted-foreground">
                This is a secure area. Please confirm your password before
                continuing.
            </p>
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="password" required>Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    :error="form.errors.password"
                    required
                    autofocus
                />
            </div>

            <Button type="submit" class="w-full" :loading="form.processing">
                Confirm
            </Button>
        </form>
    </AuthLayout>
</template>
