<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import { useTwoFactorAuth } from '@/composables';
import {
    confirm,
    disable,
    enable,
    regenerateRecoveryCodes,
} from '@/routes/two-factor';
import { Form, Head } from '@inertiajs/vue3';
import { Shield, ShieldCheck, ShieldOff } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';

interface Props {
    twoFactorEnabled: boolean;
    requiresConfirmation?: boolean;
}

withDefaults(defineProps<Props>(), {
    requiresConfirmation: false,
});

const {
    qrCodeSvg,
    manualSetupKey,
    recoveryCodesList,
    hasSetupData,
    fetchSetupData,
    fetchRecoveryCodes,
    clearTwoFactorAuthData,
} = useTwoFactorAuth();

const confirming = ref(false);
const showingRecoveryCodes = ref(false);

onUnmounted(() => {
    clearTwoFactorAuthData();
});

async function handleEnableSuccess(): Promise<void> {
    await fetchSetupData();
    confirming.value = true;
}

async function handleConfirmSuccess(): Promise<void> {
    confirming.value = false;
    showingRecoveryCodes.value = true;
    await fetchRecoveryCodes();
}

async function handleRegenerateSuccess(): Promise<void> {
    showingRecoveryCodes.value = true;
    await fetchRecoveryCodes();
}
</script>

<template>
    <Head title="Two-Factor Authentication" />

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
            v-if="!twoFactorEnabled && !confirming && !hasSetupData"
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
                    <h3 class="font-semibold text-foreground">Not Enabled</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Two-factor authentication adds an extra layer of
                        security to your account. When enabled, you'll need to
                        enter a code from your authenticator app when signing
                        in.
                    </p>
                    <Form
                        v-bind="enable.form()"
                        :options="{ preserveScroll: true }"
                        @success="handleEnableSuccess"
                        #default="{ processing }"
                    >
                        <Button
                            class="mt-4"
                            type="submit"
                            :loading="processing"
                        >
                            <Shield class="h-4 w-4" />
                            Enable Two-Factor Auth
                        </Button>
                    </Form>
                </div>
            </div>
        </div>

        <div v-if="confirming && hasSetupData" class="space-y-6">
            <div class="rounded-2xl border border-border bg-muted/30 p-6">
                <h3 class="font-semibold text-foreground">Scan QR Code</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Scan this QR code with your authenticator app (Google
                    Authenticator, Authy, etc.)
                </p>
                <div
                    v-if="qrCodeSvg"
                    class="mt-4 inline-block rounded-xl bg-white p-4"
                    v-html="qrCodeSvg"
                />
                <div v-if="manualSetupKey" class="mt-4">
                    <p class="text-sm text-muted-foreground">
                        Or enter this code manually:
                    </p>
                    <code
                        class="mt-1 block rounded-lg bg-muted px-3 py-2 font-mono text-sm"
                    >
                        {{ manualSetupKey }}
                    </code>
                </div>
            </div>

            <Form
                v-if="requiresConfirmation"
                v-bind="confirm.form()"
                :options="{ preserveScroll: true }"
                @success="handleConfirmSuccess"
                class="space-y-4"
                #default="{ errors, processing }"
            >
                <div class="space-y-2">
                    <Label for="code" required>Verification Code</Label>
                    <Input
                        id="code"
                        name="code"
                        type="text"
                        inputmode="numeric"
                        placeholder="000000"
                        autocomplete="one-time-code"
                        :error="errors.code"
                        required
                    />
                </div>
                <Button type="submit" :loading="processing">
                    Confirm & Enable
                </Button>
            </Form>
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
                        <h3 class="font-semibold text-foreground">Enabled</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Two-factor authentication is currently enabled. Your
                            account is more secure.
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="showingRecoveryCodes && recoveryCodesList.length"
                class="rounded-2xl border border-border bg-muted/30 p-6"
            >
                <h3 class="font-semibold text-foreground">Recovery Codes</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Store these recovery codes in a secure location. They can be
                    used to recover access to your account if you lose your
                    authenticator device.
                </p>
                <div
                    class="mt-4 grid gap-2 rounded-xl bg-muted p-4 font-mono text-sm sm:grid-cols-2"
                >
                    <div v-for="code in recoveryCodesList" :key="code">
                        {{ code }}
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <Form
                    v-bind="regenerateRecoveryCodes.form()"
                    :options="{ preserveScroll: true }"
                    @success="handleRegenerateSuccess"
                    #default="{ processing }"
                >
                    <Button
                        variant="outline"
                        type="submit"
                        :loading="processing"
                    >
                        Regenerate Recovery Codes
                    </Button>
                </Form>

                <Form
                    v-bind="disable.form()"
                    :options="{ preserveScroll: true }"
                    @success="clearTwoFactorAuthData"
                    #default="{ processing }"
                >
                    <Button
                        variant="destructive"
                        type="submit"
                        :loading="processing"
                    >
                        Disable Two-Factor Auth
                    </Button>
                </Form>
            </div>
        </div>
    </div>
</template>
