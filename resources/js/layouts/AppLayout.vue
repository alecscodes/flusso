<script setup lang="ts">
import { Avatar, Dropdown, DropdownItem, Separator } from '@/components/ui';
import { useTheme } from '@/composables';
import type { User } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CreditCard,
    LayoutDashboard,
    LogOut,
    Menu,
    Moon,
    Receipt,
    RefreshCw,
    Settings,
    Sun,
    Tag,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage();
const { toggleTheme, isDark } = useTheme();
const user = computed(() => page.props.auth?.user as User);
const sidebarOpen = ref(false);

const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Accounts', href: '/accounts', icon: Wallet },
    { name: 'Transactions', href: '/transactions', icon: ArrowLeftRight },
    { name: 'Categories', href: '/categories', icon: Tag },
    { name: 'Planned', href: '/recurring-payments', icon: RefreshCw },
    { name: 'Payments', href: '/payments', icon: Receipt },
];

const isActive = (href: string) =>
    href === '/dashboard' ? page.url === href : page.url.startsWith(href);
</script>

<template>
    <div class="min-h-screen bg-background">
        <header
            class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-border bg-card/95 px-4 pt-[env(safe-area-inset-top)] backdrop-blur supports-backdrop-filter:bg-card/80 lg:hidden"
        >
            <button
                type="button"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted transition-colors hover:bg-muted/80 active:bg-muted/60"
                aria-label="Open menu"
                @click="sidebarOpen = true"
            >
                <Menu class="h-5 w-5 text-foreground" />
            </button>
            <div class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
                <div
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-primary to-accent"
                >
                    <CreditCard class="h-4 w-4 text-white" />
                </div>
                <span class="truncate text-lg font-semibold text-foreground">
                    Flusso
                </span>
            </div>
        </header>

        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm lg:hidden"
            aria-hidden="true"
            @click="sidebarOpen = false"
        />

        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-border bg-card transition-transform duration-300 lg:z-40 lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div
                class="flex h-16 items-center gap-3 border-b border-border px-6"
            >
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-linear-to-br from-primary to-accent"
                >
                    <CreditCard class="h-5 w-5 text-white" />
                </div>
                <span class="text-xl font-bold text-foreground">Flusso</span>
            </div>

            <nav class="flex-1 space-y-1 p-4">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-all',
                        isActive(item.href)
                            ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/25'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="sidebarOpen = false"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.name }}
                </Link>
            </nav>

            <div class="border-t border-border p-4">
                <Dropdown align="right" width="md" wrapper-class="w-full">
                    <template #trigger>
                        <button
                            class="flex w-full items-center justify-between rounded-xl bg-muted/50 p-3 transition-colors hover:bg-muted"
                        >
                            <div class="flex items-center gap-3">
                                <Avatar :alt="user?.name" size="sm" />
                                <div class="min-w-0 text-left">
                                    <p
                                        class="truncate text-sm font-medium text-foreground"
                                    >
                                        {{ user?.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ user?.email }}
                                    </p>
                                </div>
                            </div>
                            <Settings
                                class="h-5 w-5 shrink-0 text-muted-foreground"
                            />
                        </button>
                    </template>
                    <template #default="{ close }">
                        <Link href="/settings/profile" @click="close">
                            <DropdownItem>
                                <Settings class="h-4 w-4" />
                                Settings
                            </DropdownItem>
                        </Link>
                        <DropdownItem @click="toggleTheme">
                            <Sun v-if="isDark" class="h-4 w-4" />
                            <Moon v-else class="h-4 w-4" />
                            {{ isDark ? 'Light Mode' : 'Dark Mode' }}
                        </DropdownItem>
                        <Separator class="my-1" />
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            class="w-full"
                            @click="close"
                        >
                            <DropdownItem destructive>
                                <LogOut class="h-4 w-4" />
                                Sign Out
                            </DropdownItem>
                        </Link>
                    </template>
                </Dropdown>
            </div>
        </aside>

        <main class="lg:pl-72">
            <div class="min-h-screen p-4 lg:p-8">
                <slot />
            </div>
        </main>
    </div>
</template>
