<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    SearchableSelect,
    Switch,
} from '@/components/ui';
import { useDate } from '@/composables';
import type { Account, Category, RecurringPayment } from '@/types';
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Props {
    recurringPayment?: RecurringPayment | null;
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

const isEditing = computed(() => !!props.recurringPayment);

const formData = ref({
    name: props.recurringPayment?.name || '',
    account_id: props.recurringPayment?.account_id?.toString() || '',
    category_id: props.recurringPayment?.category_id?.toString() || '',
    amount: props.recurringPayment?.amount || '',
    interval_type: props.recurringPayment?.interval_type || 'months',
    interval_value: props.recurringPayment?.interval_value?.toString() || '1',
    start_date: props.recurringPayment?.start_date
        ? toInputFormat(props.recurringPayment.start_date)
        : toInputFormat(new Date()),
    end_date: props.recurringPayment?.end_date
        ? toInputFormat(props.recurringPayment.end_date)
        : '',
    installments: props.recurringPayment?.installments?.toString() || '',
    is_active: props.recurringPayment?.is_active ?? true,
});

const selectedAccount = computed(() => {
    return props.accounts.find(
        (a) => a.id.toString() === formData.value.account_id,
    );
});

const expenseCategories = computed(() => {
    return props.categories.filter((c) => c.type === 'expense');
});

const accountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})`,
    })),
);

const categoryOptions = computed(() =>
    expenseCategories.value.map((c) => ({
        value: c.id.toString(),
        label: c.name,
    })),
);

const intervalOptions = [
    { value: 'days', label: 'Days' },
    { value: 'weeks', label: 'Weeks' },
    { value: 'months', label: 'Months' },
    { value: 'years', label: 'Years' },
];

const frequencyPreview = computed(() => {
    const value = parseInt(formData.value.interval_value) || 1;
    const type = formData.value.interval_type;

    if (value === 1) {
        switch (type) {
            case 'days':
                return 'Daily';
            case 'weeks':
                return 'Weekly';
            case 'months':
                return 'Monthly';
            case 'years':
                return 'Yearly';
        }
    }
    return `Every ${value} ${type}`;
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
                <Label for="name" required>Payment Name</Label>
                <Input
                    id="name"
                    v-model="formData.name"
                    name="name"
                    placeholder="e.g., Netflix, Rent, Gym"
                    required
                />
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
                    <Label for="category_id" required>Category</Label>
                    <SearchableSelect
                        id="category_id"
                        v-model="formData.category_id"
                        name="category_id"
                        placeholder="Select category"
                        search-placeholder="Search category..."
                        :options="categoryOptions"
                        required
                    />
                </div>
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

            <div class="space-y-2">
                <Label required>Frequency</Label>
                <div class="grid grid-cols-[1fr,2fr] gap-2">
                    <Input
                        v-model="formData.interval_value"
                        name="interval_value"
                        type="number"
                        min="1"
                        placeholder="1"
                        required
                    />
                    <SearchableSelect
                        v-model="formData.interval_type"
                        name="interval_type"
                        placeholder="Select interval"
                        search-placeholder="Search..."
                        :options="intervalOptions"
                        required
                    />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ frequencyPreview }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="start_date" required>Start Date</Label>
                    <Input
                        id="start_date"
                        v-model="formData.start_date"
                        name="start_date"
                        type="date"
                        required
                    />
                </div>

                <div class="space-y-2">
                    <Label for="end_date">End Date (Optional)</Label>
                    <Input
                        id="end_date"
                        v-model="formData.end_date"
                        name="end_date"
                        type="date"
                    />
                </div>
            </div>

            <div class="space-y-2">
                <Label for="installments"
                    >Number of Installments (Optional)</Label
                >
                <Input
                    id="installments"
                    v-model="formData.installments"
                    name="installments"
                    type="number"
                    min="1"
                    placeholder="Leave empty for indefinite"
                />
                <p class="text-xs text-muted-foreground">
                    Set a fixed number of payments, or leave empty for ongoing
                </p>
            </div>

            <div
                class="flex items-center justify-between rounded-xl border border-border bg-muted/30 p-4"
            >
                <div>
                    <p class="font-medium text-foreground">Active</p>
                    <p class="text-sm text-muted-foreground">
                        Generate payment reminders
                    </p>
                </div>
                <Switch v-model="formData.is_active" name="is_active" />
                <input
                    type="hidden"
                    name="is_active"
                    :value="formData.is_active ? '1' : '0'"
                />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit">
                {{
                    isEditing
                        ? 'Update Planned Payment'
                        : 'Create Planned Payment'
                }}
            </Button>
        </div>
    </Form>
</template>
