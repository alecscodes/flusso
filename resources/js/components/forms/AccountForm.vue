<script setup lang="ts">
import { Button, Input, Label, SearchableSelect } from '@/components/ui';
import { useCurrency } from '@/composables';
import type { Account } from '@/types';
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Props {
    account?: Account | null;
    action: string;
    method?: 'post' | 'put' | 'patch';
}

const props = withDefaults(defineProps<Props>(), {
    method: 'post',
});

const emit = defineEmits<{
    success: [];
    cancel: [];
}>();

const { supportedCurrencies } = useCurrency();

const isEditing = computed(() => !!props.account);

const formData = ref({
    name: props.account?.name || '',
    currency: props.account?.currency || 'EUR',
    balance: props.account?.balance || '0',
});

const popularCurrencies = [
    'EUR',
    'USD',
    'GBP',
    'CHF',
    'PLN',
    'CZK',
    'SEK',
    'NOK',
];

const currencyOptions = computed(() => {
    const popular = popularCurrencies.map((c) => ({ value: c, label: c }));
    const rest = supportedCurrencies.value
        .filter((c) => !popularCurrencies.includes(c))
        .map((c) => ({ value: c, label: c }));
    return [...popular, ...rest];
});
</script>

<template>
    <Form
        :action="action"
        :method="method"
        class="space-y-6"
        @success="emit('success')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <Label for="name" required>Account Name</Label>
                <Input
                    id="name"
                    v-model="formData.name"
                    name="name"
                    placeholder="e.g., Main Checking, Savings"
                    required
                />
            </div>

            <div class="space-y-2">
                <Label for="currency" required>Currency</Label>
                <SearchableSelect
                    id="currency"
                    v-model="formData.currency"
                    name="currency"
                    required
                    placeholder="Select currency"
                    search-placeholder="Search currency..."
                    :options="currencyOptions"
                />
            </div>

            <div class="space-y-2">
                <Label for="balance">Current Balance</Label>
                <Input
                    id="balance"
                    v-model="formData.balance"
                    name="balance"
                    type="number"
                    step="0.01"
                    placeholder="0.00"
                    :disabled="isEditing"
                />
                <p v-if="isEditing" class="text-xs text-muted-foreground">
                    Balance is updated by transactions only.
                </p>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit">
                {{ isEditing ? 'Update Account' : 'Create Account' }}
            </Button>
        </div>
    </Form>
</template>
