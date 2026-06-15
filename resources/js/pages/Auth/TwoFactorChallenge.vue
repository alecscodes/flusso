<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { store } from '@/routes/two-factor/login';
import { Form, Head } from '@inertiajs/vue3';
import { Shield } from 'lucide-vue-next';
import { ref } from 'vue';

const recovery = ref(false);

function toggleRecovery(clearErrors: () => void): void {
    recovery.value = !recovery.value;
    clearErrors();
}
</script>

<template>
    <Head title="Two-Factor Authentication" />

    <div class="text-center">
        <div
            class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10"
        >
            <Shield class="h-8 w-8 text-primary" />
        </div>

        <h1 class="text-2xl font-bold text-foreground">
            Two-Factor Authentication
        </h1>
        <p class="mt-2 text-muted-foreground">
            <template v-if="!recovery">
                Enter the authentication code from your authenticator app.
            </template>
            <template v-else>
                Enter one of your emergency recovery codes.
            </template>
        </p>
    </div>

    <Form
        v-if="!recovery"
        v-bind="store.form()"
        reset-on-error
        v-slot="{ errors, processing, clearErrors }"
        class="mt-6 space-y-5"
    >
        <div class="space-y-2">
            <Label for="code" required>Authentication Code</Label>
            <Input
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                placeholder="000000"
                autocomplete="one-time-code"
                :error="errors.code"
                required
                autofocus
            />
        </div>

        <Button type="submit" class="w-full" :loading="processing">
            Verify
        </Button>

        <button
            type="button"
            class="w-full text-center text-sm text-primary hover:underline"
            @click="toggleRecovery(clearErrors)"
        >
            Use a recovery code
        </button>
    </Form>

    <Form
        v-else
        v-bind="store.form()"
        reset-on-error
        v-slot="{ errors, processing, clearErrors }"
        class="mt-6 space-y-5"
    >
        <div class="space-y-2">
            <Label for="recovery_code" required>Recovery Code</Label>
            <Input
                id="recovery_code"
                name="recovery_code"
                type="text"
                placeholder="xxxxx-xxxxx"
                autocomplete="one-time-code"
                :error="errors.recovery_code"
                required
                autofocus
            />
        </div>

        <Button type="submit" class="w-full" :loading="processing">
            Verify
        </Button>

        <button
            type="button"
            class="w-full text-center text-sm text-primary hover:underline"
            @click="toggleRecovery(clearErrors)"
        >
            Use an authentication code
        </button>
    </Form>
</template>
