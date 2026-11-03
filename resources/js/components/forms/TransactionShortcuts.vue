<script setup lang="ts">
import { useCurrency } from '@/composables';
import { shortcuts as shortcutsRoute } from '@/routes/transactions';
import type { Account, Category } from '@/types';
import type { TransactionShortcut } from '@/types/suggestions';
import { useHttp } from '@inertiajs/vue3';
import { Zap } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
    accounts: Account[];
    categories: Category[];
}>();

const emit = defineEmits<{
    pick: [shortcut: TransactionShortcut];
}>();

const http = useHttp();
const { formatCurrency } = useCurrency();
const shortcuts = ref<TransactionShortcut[]>([]);

// What the owner books most often, from their own history. The form works the same without it.
onMounted(async () => {
    try {
        const response = (await http.submit(shortcutsRoute())) as {
            shortcuts: TransactionShortcut[];
        };

        shortcuts.value = response.shortcuts;
    } catch {
        shortcuts.value = [];
    }
});

const items = computed(() =>
    shortcuts.value.flatMap((shortcut) => {
        const account = props.accounts.find(
            (a) => a.id === shortcut.account_id,
        );
        const category = props.categories.find(
            (c) => c.id === shortcut.category_id,
        );

        if (!account || (shortcut.category_id !== null && !category)) {
            return [];
        }

        return [
            {
                shortcut,
                key: [
                    shortcut.account_id,
                    shortcut.category_id,
                    shortcut.type,
                    shortcut.description,
                    shortcut.amount,
                ].join('|'),
                label: shortcut.description || category?.name || 'Transaction',
                amount: formatCurrency(shortcut.amount, account.currency),
                title: [category?.name, account.name]
                    .filter(Boolean)
                    .join(' · '),
            },
        ];
    }),
);
</script>

<template>
    <div v-if="items.length > 0" class="space-y-2">
        <p
            class="flex items-center gap-1 text-xs font-medium text-muted-foreground"
        >
            <Zap class="h-3 w-3" />
            Frequent
        </p>
        <div class="flex flex-wrap gap-2">
            <button
                v-for="item in items"
                :key="item.key"
                type="button"
                :title="item.title"
                class="inline-flex max-w-full items-center gap-2 rounded-full border border-border bg-background px-3 py-1.5 text-sm transition-colors hover:border-primary/50 hover:bg-accent"
                @click="emit('pick', item.shortcut)"
            >
                <span class="truncate font-medium">{{ item.label }}</span>
                <span class="shrink-0 text-muted-foreground">{{
                    item.amount
                }}</span>
            </button>
        </div>
    </div>
</template>
