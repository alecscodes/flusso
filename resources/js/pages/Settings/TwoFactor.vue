<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { useToast } from '@/composables';
import { SettingsLayout } from '@/layouts';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Shield, ShieldCheck, ShieldOff } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    twoFactorEnabled: boolean;
    qrCode?: string;
    setupKey?: string;
    recoveryCodes?: string[];
}

const props = defineProps<Props>();

const toast = useToast();

const showingQrCode = ref(false);
const showingRecoveryCodes = ref(false);
const confirming = ref(false);

const confirmationForm = useForm({
    code: '',
});

const twoFactorEnabled = computed(() => props.twoFactorEnabled);

function enableTwoFactor() {
    router.post(
        '/user/two-factor-authentication',
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showingQrCode.value = true;
                confirming.value = true;
            },
        },
    );
}

function confirmTwoFactor() {
    confirmationForm.post('/user/confirmed-two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            showingQrCode.value = false;
            showingRecoveryCodes.value = true;
            toast.success('Two-factor authentication enabled');
        },
    });
}

function disableTwoFactor() {
    if (
        confirm('Are you sure you want to disable two-factor authentication?')
    ) {
        router.delete('/user/two-factor-authentication', {
            preserveScroll: true,
            onSuccess: () => {
                showingQrCode.value = false;
                showingRecoveryCodes.value = false;
                toast.success('Two-factor authentication disabled');
            },
        });
    }
}

function regenerateRecoveryCodes() {
    router.post(
        '/user/two-factor-recovery-codes',
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showingRecoveryCodes.value = true;
                toast.success('Recovery codes regenerated');
            },
        },
    );
}
</script>

<template>
    <Head title="Two-Factor Authentication" />

    <SettingsLayout>
        <div class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-foreground">
                    Two-Factor Authentication
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Add additional security to your account using two-factor
                    authentication.
                </p>
            </div>

            <div
                v-if="!twoFactorEnabled && !confirming"
                class="rounded-2xl border border-border bg-muted/30 p-6"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500/10"
                    >
                        <ShieldOff
                            class="h-6 w-6 text-amber-600 dark:text-amber-400"
                        />
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-foreground">
                            Not Enabled
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Two-factor authentication adds an extra layer of
                            security to your account. When enabled, you'll need
                            to enter a code from your authenticator app when
                            signing in.
                        </p>
                        <Button class="mt-4" @click="enableTwoFactor">
                            <Shield class="h-4 w-4" />
                            Enable Two-Factor Auth
                        </Button>
                    </div>
                </div>
            </div>

            <div v-if="confirming && qrCode" class="space-y-6">
                <div class="rounded-2xl border border-border bg-muted/30 p-6">
                    <h3 class="font-semibold text-foreground">Scan QR Code</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Scan this QR code with your authenticator app (Google
                        Authenticator, Authy, etc.)
                    </p>
                    <div
                        class="mt-4 inline-block rounded-xl bg-white p-4"
                        v-html="qrCode"
                    />
                    <div v-if="setupKey" class="mt-4">
                        <p class="text-sm text-muted-foreground">
                            Or enter this code manually:
                        </p>
                        <code
                            class="mt-1 block rounded-lg bg-muted px-3 py-2 font-mono text-sm"
                        >
                            {{ setupKey }}
                        </code>
                    </div>
                </div>

                <form class="space-y-4" @submit.prevent="confirmTwoFactor">
                    <div class="space-y-2">
                        <Label for="code" required>Verification Code</Label>
                        <Input
                            id="code"
                            v-model="confirmationForm.code"
                            type="text"
                            inputmode="numeric"
                            placeholder="000000"
                            autocomplete="one-time-code"
                            :error="confirmationForm.errors.code"
                            required
                        />
                    </div>
                    <Button
                        type="submit"
                        :loading="confirmationForm.processing"
                    >
                        Confirm & Enable
                    </Button>
                </form>
            </div>

            <div v-if="twoFactorEnabled && !confirming" class="space-y-6">
                <div
                    class="rounded-2xl border border-emerald-200 bg-emerald-500/5 p-6 dark:border-emerald-900/50"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10"
                        >
                            <ShieldCheck
                                class="h-6 w-6 text-emerald-600 dark:text-emerald-400"
                            />
                        </div>
                        <div class="flex-1">
                            <h3 class="font-semibold text-foreground">
                                Enabled
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Two-factor authentication is currently enabled.
                                Your account is more secure.
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="showingRecoveryCodes && recoveryCodes"
                    class="rounded-2xl border border-border bg-muted/30 p-6"
                >
                    <h3 class="font-semibold text-foreground">
                        Recovery Codes
                    </h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Store these recovery codes in a secure location. They
                        can be used to recover access to your account if you
                        lose your authenticator device.
                    </p>
                    <div
                        class="mt-4 grid gap-2 rounded-xl bg-muted p-4 font-mono text-sm sm:grid-cols-2"
                    >
                        <div v-for="code in recoveryCodes" :key="code">
                            {{ code }}
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <Button variant="outline" @click="regenerateRecoveryCodes">
                        Regenerate Recovery Codes
                    </Button>
                    <Button variant="destructive" @click="disableTwoFactor">
                        Disable Two-Factor Auth
                    </Button>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
