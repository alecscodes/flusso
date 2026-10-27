<script setup lang="ts">
import { Button } from '@/components/ui';
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';

setLayoutProps({
    title: 'Connect an app',
    description: 'Review what this app is asking for',
});

defineProps<{
    clientName: string;
    scopes: string[];
    authToken: string;
    clientId: string;
    state: string | null;
    csrfToken: string;
    authorizeUrl: string;
}>();
</script>

<template>
    <Head title="Authorize" />

    <p class="text-center text-foreground">
        <strong>{{ clientName }}</strong> wants to connect to your Flusso
        account.
    </p>

    <ul class="mt-6 space-y-2 rounded-xl bg-muted/50 p-4 text-sm">
        <li v-for="scope in scopes" :key="scope" class="flex gap-2">
            <Check class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
            {{ scope }}
        </li>
    </ul>

    <!-- Plain form posts: the answer is a redirect back to the app, which Inertia's XHR cannot follow. -->
    <div class="mt-6 grid grid-cols-2 gap-3">
        <form method="POST" :action="authorizeUrl">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input type="hidden" name="_method" value="DELETE" />
            <input type="hidden" name="state" :value="state ?? ''" />
            <input type="hidden" name="client_id" :value="clientId" />
            <input type="hidden" name="auth_token" :value="authToken" />
            <Button type="submit" variant="outline" class="w-full">
                Deny
            </Button>
        </form>

        <form method="POST" :action="authorizeUrl">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input type="hidden" name="state" :value="state ?? ''" />
            <input type="hidden" name="client_id" :value="clientId" />
            <input type="hidden" name="auth_token" :value="authToken" />
            <Button type="submit" class="w-full">Approve</Button>
        </form>
    </div>
</template>
