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
    Moon,
    MoreHorizontal,
    Receipt,
    RefreshCw,
    Settings,
    Sun,
    Tag,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage();
const { toggleTheme, isDark } = useTheme();
const user = computed(() => page.props.auth?.user as User);
const showMoreMenu = ref(false);

const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Accounts', href: '/accounts', icon: Wallet },
    { name: 'Transactions', href: '/transactions', icon: ArrowLeftRight },
    { name: 'Payments', href: '/payments', icon: Receipt },
];

const secondaryNavigation = [
    { name: 'Planned', href: '/recurring-payments', icon: RefreshCw },
    { name: 'Categories', href: '/categories', icon: Tag },
    { name: 'Settings', href: '/settings/profile', icon: Settings },
];

const bottomNavItems = [
    ...navigation.slice(0, 4), // Limit to 4 items for bottom nav
    { name: 'More', href: '#', icon: MoreHorizontal },
];

const isActive = (href: string) =>
    href === '/dashboard' ? page.url === href : page.url.startsWith(href);
</script>

<template>
    <div class="min-h-screen bg-background pb-[env(safe-area-inset-bottom)]">
        <!-- Mobile: No header, just content -->
        <!-- Desktop: Keep existing sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 hidden w-72 flex-col border-r border-border bg-card lg:flex"
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
                    v-for="item in [...navigation, ...secondaryNavigation]"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-all',
                        isActive(item.href)
                            ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/25'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
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

        <!-- Main Content -->
        <main class="lg:pl-72">
            <div class="min-h-screen p-4 pb-20 lg:p-8 lg:pb-8">
                <slot />
            </div>
        </main>

        <!-- Mobile Bottom Navigation -->
        <nav
            class="fixed right-0 bottom-0 left-0 z-50 border-t border-border bg-card/95 backdrop-blur supports-backdrop-filter:bg-card/80 lg:hidden"
        >
            <div class="grid h-16 grid-cols-5">
                <Link
                    v-for="item in bottomNavItems.slice(0, -1)"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'relative flex flex-col items-center justify-center gap-1 transition-colors',
                        isActive(item.href)
                            ? 'text-primary'
                            : 'text-muted-foreground hover:text-foreground',
                    ]"
                >
                    <component
                        :is="item.icon"
                        class="h-5 w-5"
                        :class="isActive(item.href) ? 'text-primary' : ''"
                    />
                    <span class="text-xs font-medium">{{ item.name }}</span>

                    <!-- Active indicator -->
                    <div
                        v-if="isActive(item.href)"
                        class="absolute -top-1 h-1 w-8 rounded-full bg-primary"
                    />
                </Link>

                <!-- More Button -->
                <button
                    type="button"
                    class="relative flex flex-col items-center justify-center gap-1 text-muted-foreground transition-colors hover:text-foreground"
                    @click="showMoreMenu = !showMoreMenu"
                >
                    <MoreHorizontal class="h-5 w-5" />
                    <span class="text-xs font-medium">More</span>
                </button>
            </div>

            <!-- More Menu Overlay -->
            <div
                v-if="showMoreMenu"
                class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm lg:hidden"
                @click="showMoreMenu = false"
            />

            <!-- More Menu Panel -->
            <div
                v-if="showMoreMenu"
                class="fixed right-0 bottom-16 left-0 z-50 rounded-t-2xl border-t border-border bg-card shadow-lg lg:hidden"
            >
                <div class="p-4">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-foreground">
                            More
                        </h3>
                        <button
                            @click="showMoreMenu = false"
                            class="flex h-8 w-8 items-center justify-center rounded-lg hover:bg-muted"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="space-y-2">
                        <Link
                            v-for="item in secondaryNavigation"
                            :key="item.name"
                            :href="item.href"
                            class="flex items-center gap-3 rounded-xl p-3 text-muted-foreground hover:bg-muted hover:text-foreground"
                            @click="showMoreMenu = false"
                        >
                            <component :is="item.icon" class="h-5 w-5" />
                            <span class="text-sm font-medium">{{
                                item.name
                            }}</span>
                        </Link>
                        <div class="my-2 border-t border-border" />
                        <button
                            @click="
                                toggleTheme();
                                showMoreMenu = false;
                            "
                            class="flex w-full items-center gap-3 rounded-xl p-3 text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <Sun v-if="isDark" class="h-5 w-5" />
                            <Moon v-else class="h-5 w-5" />
                            <span class="text-sm font-medium">
                                {{ isDark ? 'Light Mode' : 'Dark Mode' }}
                            </span>
                        </button>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-3 rounded-xl p-3 text-destructive hover:bg-destructive/10"
                            @click="showMoreMenu = false"
                        >
                            <LogOut class="h-5 w-5" />
                            <span class="text-sm font-medium">Sign Out</span>
                        </Link>
                    </div>
                </div>
            </div>
        </nav>
    </div>
</template>
