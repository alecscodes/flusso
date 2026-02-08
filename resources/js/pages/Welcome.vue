<script setup lang="ts">
import { Button } from '@/components/ui';
import { GITHUB_REPO_URL } from '@/lib/constants';
import { Head, Link } from '@inertiajs/vue3';
import { AlertCircle, CreditCard, Github } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Personal Use Only" />

    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background p-6"
    >
        <div class="w-full max-w-2xl space-y-8">
            <!-- Header Navigation -->
            <nav class="flex items-center justify-end">
                <Link v-if="$page.props.auth?.user" href="/dashboard">
                    <Button variant="ghost">Dashboard</Button>
                </Link>
                <Link v-else href="/login">
                    <Button variant="ghost">Sign In</Button>
                </Link>
            </nav>

            <!-- Main Content -->
            <div
                class="flex flex-col items-center space-y-4 rounded-xl border border-border bg-card p-8 shadow-lg dark:shadow-2xl"
            >
                <div class="flex items-center justify-center">
                    <div
                        class="mb-1 flex h-16 w-16 items-center justify-center rounded-md bg-black p-1.5 dark:bg-white"
                    >
                        <CreditCard
                            class="size-full object-contain text-white dark:text-black"
                        />
                    </div>
                </div>

                <div class="space-y-4 text-center">
                    <h1 class="text-4xl font-bold tracking-tight">Flusso</h1>

                    <p class="text-lg text-muted-foreground">
                        Personal finance for personal use
                    </p>
                </div>

                <!-- Personal Use Notice -->
                <div
                    class="w-full space-y-4 rounded-lg border border-amber-500/50 bg-amber-500/10 p-6"
                >
                    <div class="flex items-start gap-3">
                        <AlertCircle
                            class="h-5 w-5 shrink-0 text-amber-500 dark:text-amber-400"
                        />
                        <div class="space-y-2 text-sm">
                            <p class="font-semibold text-foreground">
                                Personal Use Only
                            </p>

                            <p class="text-muted-foreground">
                                This application is configured for personal use
                                only. It is not a public service and is not
                                intended for public access or commercial use.
                            </p>

                            <p class="text-muted-foreground">
                                If you're interested in using this application,
                                please clone the repository and set it up for
                                your own personal use.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col gap-4 sm:flex-row sm:justify-center">
                    <a
                        :href="GITHUB_REPO_URL"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <Button class="w-full sm:w-auto">
                            <Github class="mr-2 h-4 w-4" />
                            Clone Repository
                        </Button>
                    </a>

                    <template v-if="!$page.props.auth?.user">
                        <Link href="/login">
                            <Button variant="outline" class="w-full sm:w-auto">
                                Sign In
                            </Button>
                        </Link>

                        <Link v-if="canRegister" href="/register">
                            <Button class="w-full sm:w-auto"> Sign Up </Button>
                        </Link>
                    </template>

                    <Link v-else href="/dashboard">
                        <Button variant="outline" class="w-full sm:w-auto">
                            Go to Dashboard
                        </Button>
                    </Link>
                </div>

                <!-- Footer Note -->
                <p class="text-center text-xs text-muted-foreground">
                    This instance is for personal use. For your own instance,
                    clone and configure the repository.
                </p>
            </div>
        </div>
    </div>
</template>
