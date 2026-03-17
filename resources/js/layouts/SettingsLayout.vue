<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    KeyRound,
    Palette,
    Shield,
    ShieldOff,
    User,
    UserPlus,
    Wallet,
} from 'lucide-vue-next';

const page = usePage();

const navigation = [
    { name: 'Profile', href: '/settings/profile', icon: User },
    { name: 'Password', href: '/settings/password', icon: KeyRound },
    { name: 'Appearance', href: '/settings/appearance', icon: Palette },
    { name: 'Two-Factor Auth', href: '/settings/two-factor', icon: Shield },
    { name: 'Registration', href: '/settings/registration', icon: UserPlus },
    { name: 'Banned IPs', href: '/settings/banned-ips', icon: ShieldOff },
    { name: 'Finance', href: '/settings/finance', icon: Wallet },
];

const isActive = (href: string) => page.url === href;
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-4xl">
            <div class="mb-8">
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Settings
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Manage your account settings and preferences
                </p>
            </div>

            <div class="flex flex-col gap-8 lg:flex-row">
                <nav class="w-full shrink-0 lg:w-56">
                    <div
                        class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible"
                    >
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            :class="[
                                'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-all',
                                isActive(item.href)
                                    ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/25'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            ]"
                        >
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.name }}
                        </Link>
                    </div>
                </nav>

                <div class="min-w-0 flex-1">
                    <div class="rounded-2xl border border-border bg-card p-6">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
