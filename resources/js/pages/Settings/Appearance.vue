<script setup lang="ts">
import { useTheme } from '@/composables';
import { SettingsLayout } from '@/layouts';
import { Head } from '@inertiajs/vue3';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

const { theme, setTheme } = useTheme();

const themes = [
    {
        value: 'light',
        label: 'Light',
        description: 'A clean, bright appearance',
        icon: Sun,
    },
    {
        value: 'dark',
        label: 'Dark',
        description: 'Easy on the eyes in low light',
        icon: Moon,
    },
    {
        value: 'auto',
        label: 'System',
        description: 'Follows your system preference',
        icon: Monitor,
    },
];
</script>

<template>
    <Head title="Appearance Settings" />

    <SettingsLayout>
        <div class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-foreground">
                    Appearance
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Customize how Flusso looks on your device.
                </p>
            </div>

            <div class="space-y-4">
                <h3 class="text-sm font-medium text-foreground">Theme</h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <button
                        v-for="option in themes"
                        :key="option.value"
                        type="button"
                        :class="[
                            'flex flex-col items-center gap-3 rounded-2xl border-2 p-6 transition-all',
                            theme === option.value
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:border-primary/50 hover:bg-muted/50',
                        ]"
                        @click="
                            setTheme(option.value as 'light' | 'dark' | 'auto')
                        "
                    >
                        <div
                            :class="[
                                'flex h-12 w-12 items-center justify-center rounded-xl',
                                theme === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                        >
                            <component :is="option.icon" class="h-6 w-6" />
                        </div>
                        <div class="text-center">
                            <p class="font-medium text-foreground">
                                {{ option.label }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ option.description }}
                            </p>
                        </div>
                    </button>
                </div>
            </div>

            <div class="rounded-2xl border border-border bg-muted/30 p-6">
                <h3 class="text-sm font-medium text-foreground">Preview</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    This is how your interface will look with the selected
                    theme.
                </p>
                <div class="mt-4 flex gap-4">
                    <div class="flex-1 rounded-xl bg-card p-4 shadow-sm">
                        <div class="h-3 w-20 rounded bg-foreground/20" />
                        <div
                            class="mt-2 h-2 w-32 rounded bg-muted-foreground/20"
                        />
                        <div class="mt-4 flex gap-2">
                            <div class="h-8 w-16 rounded-lg bg-primary" />
                            <div class="h-8 w-16 rounded-lg bg-secondary" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
