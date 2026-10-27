<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { edit as appearanceEdit } from '@/routes/appearance';
import { index as bannedIpsIndex } from '@/routes/banned-ips';
import { index as connectedAppsIndex } from '@/routes/connected-apps';
import { edit as profileEdit } from '@/routes/profile';
import { edit as registrationEdit } from '@/routes/registration';
import { edit as financeEdit } from '@/routes/settings/finance';
import { show as twoFactorShow } from '@/routes/two-factor';
import { edit as passwordEdit } from '@/routes/user-password';
import { Link, usePage } from '@inertiajs/vue3';
import {
    KeyRound,
    Palette,
    Plug,
    Shield,
    ShieldOff,
    User,
    UserPlus,
    Wallet,
} from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';

const page = usePage();

const navigation = [
    { name: 'Profile', href: profileEdit.url(), icon: User },
    { name: 'Password', href: passwordEdit.url(), icon: KeyRound },
    { name: 'Appearance', href: appearanceEdit.url(), icon: Palette },
    { name: 'Two-Factor Auth', href: twoFactorShow.url(), icon: Shield },
    { name: 'Registration', href: registrationEdit.url(), icon: UserPlus },
    { name: 'Connected apps', href: connectedAppsIndex.url(), icon: Plug },
    { name: 'Banned IPs', href: bannedIpsIndex.url(), icon: ShieldOff },
    { name: 'Finance', href: financeEdit.url(), icon: Wallet },
];

const isActive = (href: string) => page.url.split('?')[0] === href;

const tabBar = ref<HTMLElement | null>(null);

/** The tab bar scrolls sideways on mobile: keep the current tab in view. */
async function scrollActiveTabIntoView(): Promise<void> {
    await nextTick();

    const bar = tabBar.value;
    const tab = bar?.querySelector<HTMLElement>('[aria-current="page"]');

    if (bar && tab) {
        bar.scrollLeft =
            tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2;
    }
}

onMounted(scrollActiveTabIntoView);
watch(() => page.url, scrollActiveTabIntoView);
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-4xl">
            <div class="mb-6 lg:mb-8">
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
                        ref="tabBar"
                        class="relative flex [scrollbar-width:none] gap-1 overflow-x-auto lg:flex-col lg:overflow-visible [&::-webkit-scrollbar]:hidden"
                    >
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            :aria-current="
                                isActive(item.href) ? 'page' : undefined
                            "
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
