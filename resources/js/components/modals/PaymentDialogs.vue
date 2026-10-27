<script setup lang="ts">
import {
    Button,
    Input,
    Label,
    Modal,
    ModalFooter,
    SearchableSelect,
    Textarea,
} from '@/components/ui';
import {
    paymentName,
    useDate,
    useLastValue,
    usePaymentActions,
} from '@/composables';
import { markPaid, update } from '@/routes/payments';
import type { Account } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';

/**
 * The two dialogs behind every payment card: pay (prefilled, one tap to
 * confirm, but amount/date/account can be changed) and edit one occurrence.
 * Render it once per page.
 */
const props = defineProps<{ accounts: Account[] }>();

const { paying, editing } = usePaymentActions();

// A closing dialog keeps its content until it has faded out.
const lastPaying = useLastValue(paying);
const lastEditing = useLastValue(editing);
const { toInputFormat } = useDate();
const payFormId = useId();
const editFormId = useId();

const accountOptions = computed(() =>
    props.accounts.map((a) => ({
        value: a.id.toString(),
        label: `${a.name} (${a.currency})`,
    })),
);

const errorOf = (errors: object): string | undefined =>
    (errors as Record<string, string>).payment;

const payForm = useForm({ amount: '', date: '', account_id: '' });
const editForm = useForm({
    amount: '',
    due_date: '',
    account_id: '',
    description: '',
});

watch(paying, (payment) => {
    if (!payment) return;
    payForm.reset();
    payForm.clearErrors();
    payForm.amount = payment.amount;
    payForm.date = toInputFormat(new Date());
    payForm.account_id = payment.account_id.toString();
});

watch(editing, (payment) => {
    if (!payment) return;
    editForm.clearErrors();
    editForm.amount = payment.amount;
    editForm.due_date = toInputFormat(payment.due_date);
    editForm.account_id = payment.account_id.toString();
    editForm.description = payment.description ?? '';
});

const payCurrency = computed(
    () =>
        props.accounts.find((a) => a.id.toString() === payForm.account_id)
            ?.currency ?? paying.value?.currency,
);

function pay() {
    if (!paying.value) return;

    payForm.patch(markPaid.url(paying.value), {
        preserveScroll: true,
        onSuccess: () => (paying.value = null),
    });
}

function save() {
    if (!editing.value) return;

    editForm.patch(update.url(editing.value), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}
</script>

<template>
    <div class="hidden">
        <Modal
            :open="!!paying"
            title="Mark as paid"
            :description="lastPaying ? paymentName(lastPaying) : undefined"
            size="sm"
            @close="paying = null"
        >
            <form
                v-if="lastPaying"
                :id="payFormId"
                class="space-y-4"
                @submit.prevent="pay"
            >
                <div class="space-y-2">
                    <Label for="pay_amount" required>Amount paid</Label>
                    <div class="relative">
                        <Input
                            id="pay_amount"
                            v-model="payForm.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="pr-16"
                            required
                        />
                        <span
                            class="absolute top-1/2 right-4 -translate-y-1/2 text-sm text-muted-foreground"
                        >
                            {{ payCurrency }}
                        </span>
                    </div>
                    <p
                        v-if="payForm.errors.amount"
                        class="text-sm text-destructive"
                    >
                        {{ payForm.errors.amount }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="pay_date" required>Date</Label>
                    <Input
                        id="pay_date"
                        v-model="payForm.date"
                        type="date"
                        required
                    />
                    <p
                        v-if="payForm.errors.date"
                        class="text-sm text-destructive"
                    >
                        {{ payForm.errors.date }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="pay_account" required>Account</Label>
                    <SearchableSelect
                        id="pay_account"
                        v-model="payForm.account_id"
                        placeholder="Select account"
                        search-placeholder="Search account..."
                        :options="accountOptions"
                        required
                    />
                    <p
                        v-if="payForm.errors.account_id"
                        class="text-sm text-destructive"
                    >
                        {{ payForm.errors.account_id }}
                    </p>
                </div>
                <p
                    v-if="errorOf(payForm.errors)"
                    class="text-sm text-destructive"
                >
                    {{ errorOf(payForm.errors) }}
                </p>

                <ModalFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="paying = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="success"
                        :form="payFormId"
                        :disabled="payForm.processing"
                    >
                        Confirm payment
                    </Button>
                </ModalFooter>
            </form>
        </Modal>

        <Modal
            :open="!!editing"
            title="Edit payment"
            description="Only this payment changes. The planned payment keeps its schedule."
            size="sm"
            @close="editing = null"
        >
            <form
                v-if="lastEditing"
                :id="editFormId"
                class="space-y-4"
                @submit.prevent="save"
            >
                <div class="space-y-2">
                    <Label for="edit_amount" required>Amount</Label>
                    <Input
                        id="edit_amount"
                        v-model="editForm.amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        required
                    />
                    <p
                        v-if="editForm.errors.amount"
                        class="text-sm text-destructive"
                    >
                        {{ editForm.errors.amount }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="edit_due_date" required>Due date</Label>
                    <Input
                        id="edit_due_date"
                        v-model="editForm.due_date"
                        type="date"
                        required
                    />
                    <p
                        v-if="editForm.errors.due_date"
                        class="text-sm text-destructive"
                    >
                        {{ editForm.errors.due_date }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="edit_account" required>Account</Label>
                    <SearchableSelect
                        id="edit_account"
                        v-model="editForm.account_id"
                        placeholder="Select account"
                        search-placeholder="Search account..."
                        :options="accountOptions"
                        required
                    />
                    <p
                        v-if="editForm.errors.account_id"
                        class="text-sm text-destructive"
                    >
                        {{ editForm.errors.account_id }}
                    </p>
                </div>
                <div class="space-y-2">
                    <Label for="edit_description">Description</Label>
                    <Textarea
                        id="edit_description"
                        v-model="editForm.description"
                        rows="2"
                    />
                </div>
                <p
                    v-if="errorOf(editForm.errors)"
                    class="text-sm text-destructive"
                >
                    {{ errorOf(editForm.errors) }}
                </p>

                <ModalFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="editing = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :form="editFormId"
                        :disabled="editForm.processing"
                    >
                        Save
                    </Button>
                </ModalFooter>
            </form>
        </Modal>
    </div>
</template>
