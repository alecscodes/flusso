<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const commit = usePage().props.commit as string | null;
</script>

<template>
    <footer
        v-if="commit"
        class="mt-auto border-t border-border px-6 py-3 text-end"
    >
        <span class="font-mono text-xs text-muted-foreground">{{
            commit
        }}</span>
    </footer>
</template>
