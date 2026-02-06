<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    SearchableSelect,
    Textarea,
} from '@/components/ui';
import { useDate } from '@/composables';
import type { Account, Category, Transaction } from '@/types';
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Props {
    transaction?: Transaction | null;
    accounts: Account[];
    categories: Category[];
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

const { toInputFormat } = useDate();

const isEditing = computed(() => !!props.transaction);

const formData = ref({
    type: props.transaction?.type || 'expense',
    account_id: props.transaction?.account_id?.toString() || '',
    category_id: props.transaction?.category_id?.toString() || '',
    amount: props.transaction?.amount || '',
    description: props.transaction?.description || '',
    date: props.transaction?.date
        ? toInputFormat(props.transaction.date)
        : toInputFormat(new Date()),
});

const filteredCategories = computed(() =>
    props.categories.filter((c) => c.type === formData.value.type),
);

const selectedAccount = computed(() => {
    return props.accounts.find(
        (a) => a.id.toString() === formData.value.account_id,
    );
});

const accountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})`,
    })),
);

const categoryOptions = computed(() => [
    { value: '', label: 'No category' },
    ...filteredCategories.value.map((c) => ({
        value: c.id.toString(),
        label: c.name,
    })),
]);

watch(
    () => formData.value.type,
    () => {
        formData.value.category_id = '';
    },
);
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
                <Label required>Transaction Type</Label>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-4 py-3 text-sm font-medium transition-all',
                            formData.type === 'expense'
                                ? 'border-rose-500 bg-rose-500/10 text-rose-600'
                                : 'border-border hover:border-rose-300 hover:bg-rose-500/5',
                        ]"
                        @click="formData.type = 'expense'"
                    >
                        Expense
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-4 py-3 text-sm font-medium transition-all',
                            formData.type === 'income'
                                ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600'
                                : 'border-border hover:border-emerald-300 hover:bg-emerald-500/5',
                        ]"
                        @click="formData.type = 'income'"
                    >
                        Income
                    </button>
                </div>
                <input type="hidden" name="type" :value="formData.type" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="account_id" required>Account</Label>
                    <SearchableSelect
                        id="account_id"
                        v-model="formData.account_id"
                        name="account_id"
                        placeholder="Select account"
                        search-placeholder="Search account..."
                        :options="accountOptions"
                        required
                    />
                </div>

                <div class="space-y-2">
                    <Label for="amount" required>Amount</Label>
                    <div class="relative">
                        <Input
                            id="amount"
                            v-model="formData.amount"
                            name="amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            class="pr-16"
                            required
                        />
                        <span
                            class="absolute top-1/2 right-4 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            {{ selectedAccount?.currency || 'EUR' }}
                        </span>
                    </div>
                    <input
                        type="hidden"
                        name="currency"
                        :value="selectedAccount?.currency || 'EUR'"
                    />
                </div>
            </div>

            <div class="space-y-2">
                <Label for="category_id">Category</Label>
                <SearchableSelect
                    id="category_id"
                    v-model="formData.category_id"
                    name="category_id"
                    placeholder="Select category (optional)"
                    search-placeholder="Search category..."
                    :options="categoryOptions"
                />
            </div>

            <div class="space-y-2">
                <Label for="date">Date</Label>
                <Input
                    id="date"
                    v-model="formData.date"
                    name="date"
                    type="date"
                />
            </div>

            <div class="space-y-2">
                <Label for="description">Description</Label>
                <Textarea
                    id="description"
                    v-model="formData.description"
                    name="description"
                    placeholder="What was this transaction for?"
                    rows="2"
                />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit">
                {{ isEditing ? 'Update Transaction' : 'Add Transaction' }}
            </Button>
        </div>
    </Form>
</template>
