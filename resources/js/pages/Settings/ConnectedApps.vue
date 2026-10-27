<script setup lang="ts">
import { Button } from '@/components/ui';
import { destroy as revokeRoute } from '@/routes/connected-apps';
import { Head, router } from '@inertiajs/vue3';
import { Plug, Unplug } from '@lucide/vue';

interface ConnectedApp {
    id: string;
    name: string;
    connected_at: string;
    last_used_at: string;
    expires_at: string | null;
}

defineProps<{ apps: ConnectedApp[] }>();

function revoke(app: ConnectedApp): void {
    if (
        confirm(
            `Revoke access for ${app.name}? It will have to be connected again.`,
        )
    ) {
        router.delete(revokeRoute.url({ client: app.id }), {
            preserveScroll: true,
        });
    }
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString();
}
</script>

<template>
    <Head title="Connected apps" />

    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-foreground">
                Connected apps
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Apps you let see and change your finances, such as Claude
            </p>
        </div>

        <div
            v-if="apps.length === 0"
            class="rounded-2xl border border-border bg-muted/30 p-6"
        >
            <div class="flex items-center gap-2 text-muted-foreground">
                <Unplug class="h-5 w-5" />
                <span class="font-medium">No connected apps</span>
            </div>
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="app in apps"
                :key="app.id"
                class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6"
            >
                <div class="min-w-0 flex-1 space-y-1">
                    <span class="flex items-center gap-2 font-semibold">
                        <Plug class="h-4 w-4 shrink-0" />
                        <span class="truncate">{{ app.name }}</span>
                    </span>
                    <p class="text-sm text-muted-foreground">
                        Connected {{ formatDate(app.connected_at) }} · last used
                        {{ formatDate(app.last_used_at) }}
                        <template v-if="app.expires_at">
                            · expires {{ formatDate(app.expires_at) }}
                        </template>
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    class="w-full shrink-0 sm:w-auto"
                    @click="revoke(app)"
                >
                    Revoke
                </Button>
            </div>
        </div>
    </div>
</template>
