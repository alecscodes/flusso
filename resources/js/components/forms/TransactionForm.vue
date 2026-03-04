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
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

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

const form = useForm({
    type: props.transaction?.type || 'expense',
    account_id: props.transaction?.account_id?.toString() || '',
    category_id: props.transaction?.category_id?.toString() || '',
    amount: props.transaction?.amount || '',
    description: props.transaction?.description || '',
    date: props.transaction?.date
        ? toInputFormat(props.transaction.date)
        : toInputFormat(new Date()),
    files: [] as File[],
});

const filteredCategories = computed(() =>
    props.categories.filter((c) => c.type === form.type),
);

const selectedAccount = computed(() => {
    return props.accounts.find((a) => a.id.toString() === form.account_id);
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
    () => form.type,
    () => {
        form.category_id = '';
    },
);

function handleFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        const validFiles = Array.from(target.files).filter((file) => {
            return file.size <= 10 * 1024 * 1024; // 10MB limit
        });

        form.files = validFiles;
    }
}

function submit() {
    const method = isEditing.value ? 'put' : 'post';
    form[method](props.action, {
        onSuccess: () => emit('success'),
        onError: (errors) => {
            if (errors.files) {
                // Handle file upload errors specifically
                console.error('File upload errors:', errors.files);
            }
        },
    });
}

function removeFile(index: number) {
    form.files.splice(index, 1);
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <div class="space-y-4">
            <div class="space-y-2">
                <Label required>Transaction Type</Label>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-4 py-3 text-sm font-medium transition-all',
                            form.type === 'expense'
                                ? 'border-rose-500 bg-rose-500/10 text-rose-600'
                                : 'border-border hover:border-rose-300 hover:bg-rose-500/5',
                        ]"
                        @click="form.type = 'expense'"
                    >
                        Expense
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-xl border-2 px-4 py-3 text-sm font-medium transition-all',
                            form.type === 'income'
                                ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600'
                                : 'border-border hover:border-emerald-300 hover:bg-emerald-500/5',
                        ]"
                        @click="form.type = 'income'"
                    >
                        Income
                    </button>
                </div>
                <input type="hidden" name="type" :value="form.type" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="account_id" required>Account</Label>
                    <SearchableSelect
                        id="account_id"
                        v-model="form.account_id"
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
                            v-model="form.amount"
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
                    v-model="form.category_id"
                    name="category_id"
                    placeholder="Select category (optional)"
                    search-placeholder="Search category..."
                    :options="categoryOptions"
                />
            </div>

            <div class="space-y-2">
                <Label for="date">Date</Label>
                <Input id="date" v-model="form.date" name="date" type="date" />
            </div>

            <div class="space-y-2">
                <Label for="files">Attachments</Label>
                <Input
                    id="files"
                    name="files"
                    type="file"
                    multiple
                    @change="handleFileChange"
                    accept=".pdf,.doc,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                />
                <div v-if="form.files.length > 0" class="mt-2 space-y-1">
                    <div
                        v-for="(file, index) in form.files"
                        :key="index"
                        class="flex items-center justify-between rounded bg-muted/50 p-2"
                    >
                        <span class="text-sm text-muted-foreground">{{
                            file.name
                        }}</span>
                        <button
                            type="button"
                            @click="removeFile(index)"
                            class="text-sm text-rose-500 hover:text-rose-700"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            </div>

            <div class="space-y-2">
                <Label for="description">Description</Label>
                <Textarea
                    id="description"
                    v-model="form.description"
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
            <Button type="submit" :disabled="form.processing">
                {{ isEditing ? 'Update Transaction' : 'Add Transaction' }}
            </Button>
        </div>
    </form>
</template>
