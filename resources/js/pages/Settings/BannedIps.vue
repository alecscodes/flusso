<script setup lang="ts">
import { Badge, Button } from '@/components/ui';
import { SettingsLayout } from '@/layouts';
import { Head, router } from '@inertiajs/vue3';
import { ShieldOff, Trash2 } from 'lucide-vue-next';

interface BannedIp {
    ip: string;
    reason: string | null;
    banned_at: string;
}

interface Props {
    bannedIps: BannedIp[];
}

defineProps<Props>();

function unbanIp(ip: string): void {
    if (confirm(`Are you sure you want to unban IP address ${ip}?`)) {
        router.delete('/settings/banned-ips/unban', {
            data: { ip },
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['bannedIps'] });
            },
        });
    }
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleString();
}
</script>

<template>
    <Head title="Banned IPs" />

    <SettingsLayout>
        <div class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-foreground">
                    Banned IP Addresses ({{ bannedIps.length }})
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    View and manage IP addresses that have been automatically
                    banned
                </p>
            </div>

            <div
                v-if="bannedIps.length === 0"
                class="rounded-2xl border border-border bg-muted/30 p-6"
            >
                <div class="flex items-center gap-2 text-muted-foreground">
                    <ShieldOff class="h-5 w-5" />
                    <span class="font-medium">No Banned IPs</span>
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    There are currently no banned IP addresses.
                </p>
            </div>

            <div v-else class="space-y-4">
                <div
                    v-for="bannedIp in bannedIps"
                    :key="bannedIp.ip"
                    class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6"
                >
                    <div class="min-w-0 flex-1 space-y-2 overflow-hidden">
                        <span
                            class="block font-mono text-sm font-semibold break-all sm:text-base"
                        >
                            {{ bannedIp.ip }}
                        </span>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                v-if="bannedIp.reason"
                                variant="secondary"
                                class="max-w-full text-xs break-words"
                            >
                                {{ bannedIp.reason }}
                            </Badge>
                            <span v-else class="text-sm text-muted-foreground">
                                No reason provided
                            </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Banned at: {{ formatDate(bannedIp.banned_at) }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        class="w-full shrink-0 sm:w-auto"
                        @click="unbanIp(bannedIp.ip)"
                    >
                        <Trash2 class="mr-2 h-4 w-4" />
                        Unban
                    </Button>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
