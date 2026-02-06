<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    SearchableSelect,
    Textarea,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import type { Account } from '@/types';
import { Form } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Props {
    accounts: Account[];
    action: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    success: [];
    cancel: [];
}>();

const { formatCurrency } = useCurrency();
const { toInputFormat } = useDate();

const formData = ref({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    exchange_rate: '',
    description: '',
    date: toInputFormat(new Date()),
});

const fromAccount = computed(() => {
    return props.accounts.find(
        (a) => a.id.toString() === formData.value.from_account_id,
    );
});

const toAccount = computed(() => {
    return props.accounts.find(
        (a) => a.id.toString() === formData.value.to_account_id,
    );
});

const fromAccountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${formatCurrency(a.balance, a.currency)})`,
    })),
);

const toAccountOptions = computed(() =>
    props.accounts
        .filter((a) => a.id.toString() !== formData.value.from_account_id)
        .map((a) => ({
            value: a.id.toString(),
            label: `${a.name} (${a.currency})`,
        })),
);

const needsExchangeRate = computed(() => {
    return (
        fromAccount.value &&
        toAccount.value &&
        fromAccount.value.currency !== toAccount.value.currency
    );
});

const convertedAmount = computed(() => {
    if (
        !needsExchangeRate.value ||
        !formData.value.amount ||
        !formData.value.exchange_rate
    ) {
        return null;
    }
    const amount = parseFloat(formData.value.amount);
    const rate = parseFloat(formData.value.exchange_rate);
    return amount * rate;
});

watch(
    () => formData.value.from_account_id,
    (newVal) => {
        if (newVal === formData.value.to_account_id) {
            formData.value.to_account_id = '';
        }
    },
);
</script>

<template>
    <Form
        :action="action"
        method="post"
        class="space-y-6"
        @success="emit('success')"
    >
        <div class="space-y-4">
            <div class="flex justify-between gap-2">
                <div class="w-full space-y-2">
                    <Label for="from_account_id" required>From Account</Label>
                    <SearchableSelect
                        id="from_account_id"
                        v-model="formData.from_account_id"
                        name="from_account_id"
                        placeholder="Select source"
                        search-placeholder="Search account..."
                        :options="fromAccountOptions"
                        required
                    />
                </div>

                <div class="mb-1 flex items-end justify-center">
                    <div class="rounded-full bg-primary/10 p-2">
                        <ArrowRight class="h-5 w-5 text-primary" />
                    </div>
                </div>

                <div class="w-full space-y-2">
                    <Label for="to_account_id" required>To Account</Label>
                    <SearchableSelect
                        id="to_account_id"
                        v-model="formData.to_account_id"
                        name="to_account_id"
                        placeholder="Select destination"
                        search-placeholder="Search account..."
                        :options="toAccountOptions"
                        required
                    />
                </div>
            </div>

            <div :class="needsExchangeRate ? 'grid gap-4 sm:grid-cols-2' : ''">
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
                            {{ fromAccount?.currency || 'EUR' }}
                        </span>
                    </div>
                </div>

                <div v-if="needsExchangeRate" class="space-y-2">
                    <Label for="exchange_rate">Exchange Rate</Label>
                    <Input
                        id="exchange_rate"
                        v-model="formData.exchange_rate"
                        name="exchange_rate"
                        type="number"
                        step="0.000001"
                        min="0.000001"
                        :placeholder="`1 ${fromAccount?.currency} = ? ${toAccount?.currency}`"
                    />
                    <p
                        v-if="convertedAmount"
                        class="text-sm text-muted-foreground"
                    >
                        ≈
                        {{
                            formatCurrency(
                                convertedAmount,
                                toAccount?.currency || 'EUR',
                            )
                        }}
                    </p>
                </div>
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
                    placeholder="Transfer notes (optional)"
                    rows="2"
                />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit"> Complete Transfer </Button>
        </div>
    </Form>
</template>
