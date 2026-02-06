<script setup lang="ts">
import { useTheme } from '@/composables';
import { Link } from '@inertiajs/vue3';
import { CreditCard, Moon, Sun } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();

const { toggleTheme, isDark } = useTheme();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <header class="flex items-center justify-between p-6">
            <Link href="/" class="flex items-center gap-3">
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-accent"
                >
                    <CreditCard class="h-5 w-5 text-white" />
                </div>
                <span class="text-xl font-bold text-foreground">Flusso</span>
            </Link>
            <button
                class="rounded-xl p-2.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                @click="toggleTheme"
            >
                <component :is="isDark ? Sun : Moon" class="h-5 w-5" />
            </button>
        </header>

        <main class="flex flex-1 items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div v-if="title" class="mb-8 text-center">
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        {{ title }}
                    </h1>
                    <p v-if="description" class="mt-2 text-muted-foreground">
                        {{ description }}
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-border bg-card p-8 shadow-xl shadow-primary/5"
                >
                    <slot />
                </div>

                <div v-if="$slots.footer" class="mt-6 text-center">
                    <slot name="footer" />
                </div>
            </div>
        </main>

        <footer class="p-6 text-center text-sm text-muted-foreground">
            <p>
                &copy; {{ new Date().getFullYear() }} Flusso. All rights
                reserved.
            </p>
        </footer>
    </div>
</template>
