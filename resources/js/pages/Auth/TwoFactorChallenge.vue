<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { AuthLayout } from '@/layouts';
import { Head, useForm } from '@inertiajs/vue3';
import { Shield } from 'lucide-vue-next';
import { ref } from 'vue';

const recovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

function submit() {
    form.post('/two-factor-challenge');
}

function toggleRecovery() {
    recovery.value = !recovery.value;
    form.code = '';
    form.recovery_code = '';
}
</script>

<template>
    <Head title="Two-Factor Authentication" />

    <AuthLayout>
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

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div v-if="!recovery" class="space-y-2">
                <Label for="code" required>Authentication Code</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    placeholder="000000"
                    autocomplete="one-time-code"
                    :error="form.errors.code"
                    required
                    autofocus
                />
            </div>

            <div v-else class="space-y-2">
                <Label for="recovery_code" required>Recovery Code</Label>
                <Input
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    placeholder="xxxxx-xxxxx"
                    autocomplete="one-time-code"
                    :error="form.errors.recovery_code"
                    required
                    autofocus
                />
            </div>

            <Button type="submit" class="w-full" :loading="form.processing">
                Verify
            </Button>

            <button
                type="button"
                class="w-full text-center text-sm text-primary hover:underline"
                @click="toggleRecovery"
            >
                <template v-if="!recovery"> Use a recovery code </template>
                <template v-else> Use an authentication code </template>
            </button>
        </form>
    </AuthLayout>
</template>
