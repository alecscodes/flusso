<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { update } from '@/routes/password';
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';

setLayoutProps({
    title: 'Reset your password',
    description: 'Enter your new password below',
});

interface Props {
    email: string;
    token: string;
}

const props = defineProps<Props>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head title="Reset Password" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="space-y-5"
    >
        <div class="space-y-2">
            <Label for="email" required>Email</Label>
            <Input
                id="email"
                name="email"
                v-model="inputEmail"
                type="email"
                autocomplete="email"
                :error="errors.email"
                required
                readonly
            />
        </div>

        <div class="space-y-2">
            <Label for="password" required>New Password</Label>
            <Input
                id="password"
                name="password"
                type="password"
                placeholder="••••••••"
                autocomplete="new-password"
                :error="errors.password"
                required
                autofocus
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
            Reset Password
        </Button>
    </Form>
</template>
