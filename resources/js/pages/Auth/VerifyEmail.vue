<script setup lang="ts">
import { Button } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';

interface Props {
    status?: string;
}

defineProps<Props>();

const form = useForm({});

function submit() {
    form.post('/email/verification-notification');
}
</script>

<template>
    <Head title="Verify Email" />

    <AuthLayout>
        <div class="text-center">
            <div
                class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10"
            >
                <Mail class="h-8 w-8 text-primary" />
            </div>

            <h1 class="text-2xl font-bold text-foreground">Check your email</h1>
            <p class="mt-2 text-muted-foreground">
                We've sent a verification link to your email address. Please
                click the link to verify your account.
            </p>
        </div>

        <div
            v-if="status === 'verification-link-sent'"
            class="mt-6 rounded-xl bg-emerald-500/10 p-4 text-sm text-emerald-600 dark:text-emerald-400"
        >
            A new verification link has been sent to your email address.
        </div>

        <div class="mt-6 space-y-4">
            <form @submit.prevent="submit">
                <Button type="submit" class="w-full" :loading="form.processing">
                    Resend Verification Email
                </Button>
            </form>

            <Link href="/logout" method="post" as="button" class="w-full">
                <Button variant="outline" class="w-full"> Sign Out </Button>
            </Link>
        </div>
    </AuthLayout>
</template>
