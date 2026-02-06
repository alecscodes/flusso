<script setup lang="ts">
import { AmountDisplay, CategoryBadge } from '@/components/data-display';
import { RecurringPaymentForm } from '@/components/forms';
import {
    Badge,
    Button,
    Card,
    CardContent,
    EmptyState,
    Modal,
    Switch,
} from '@/components/ui';
import { useDate } from '@/composables';
import { AppLayout } from '@/layouts';
import type { Account, Category, RecurringPayment } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Calendar, Plus, RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

interface Props {
    recurringPayments: RecurringPayment[];
    accounts: Account[];
    categories: Category[];
}

const props = defineProps<Props>();

const { formatDate } = useDate();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingPayment = ref<RecurringPayment | null>(null);

function getIntervalLabel(payment: RecurringPayment): string {
    const value = payment.interval_value;
    const type = payment.interval_type;

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
}

function openEditModal(payment: RecurringPayment) {
    editingPayment.value = payment;
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingPayment.value = null;
}

function handleCreateSuccess() {
    showCreateModal.value = false;
}

function handleEditSuccess() {
    closeEditModal();
}

function toggleActive(payment: RecurringPayment) {
    router.patch(
        `/recurring-payments/${payment.id}`,
        {
            is_active: !payment.is_active,
        },
        {
            preserveScroll: true,
        },
    );
}

function deletePayment(payment: RecurringPayment) {
    if (confirm(`Are you sure you want to delete "${payment.name}"?`)) {
        router.delete(`/recurring-payments/${payment.id}`);
    }
}

function generatePayments(payment: RecurringPayment) {
    router.post(
        `/recurring-payments/${payment.id}/generate-payments`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Planned Payments" />

    <AppLayout>
        <div class="space-y-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Planned Payments
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Manage your subscriptions and planned bills
                    </p>
                </div>
                <Button @click="showCreateModal = true">
                    <Plus class="h-4 w-4" />
                    Add Planned Payment
                </Button>
            </div>

            <div
                v-if="props.recurringPayments.length > 0"
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Card
                    v-for="payment in props.recurringPayments"
                    :key="payment.id"
                    :class="
                        !payment.is_active
                            ? 'group opacity-60 transition-all duration-300'
                            : 'group transition-all duration-300'
                    "
                    hover
                >
                    <CardContent class="pt-6">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    :class="[
                                        'flex h-12 w-12 items-center justify-center rounded-xl',
                                        payment.is_active
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    ]"
                                >
                                    <RefreshCw class="h-6 w-6" />
                                </div>
                                <div>
                                    <h3 class="font-semibold text-foreground">
                                        {{ payment.name }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ getIntervalLabel(payment) }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Switch
                                    :model-value="payment.is_active"
                                    @update:model-value="toggleActive(payment)"
                                />
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between">
                            <AmountDisplay
                                :amount="payment.amount"
                                :currency="payment.currency"
                                type="expense"
                                size="lg"
                                :show-sign="false"
                            />
                            <Badge
                                :variant="
                                    payment.is_active ? 'success' : 'secondary'
                                "
                            >
                                {{ payment.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>

                        <div class="mt-4 space-y-2">
                            <div
                                v-if="payment.category"
                                class="flex items-center gap-2"
                            >
                                <CategoryBadge
                                    :category="payment.category"
                                    size="sm"
                                />
                            </div>
                            <div
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <Calendar class="h-4 w-4" />
                                Started {{ formatDate(payment.start_date) }}
                            </div>
                            <div
                                v-if="payment.account"
                                class="text-sm text-muted-foreground"
                            >
                                From: {{ payment.account.name }}
                            </div>
                            <div
                                v-if="payment.installments"
                                class="text-sm text-muted-foreground"
                            >
                                {{ payment.installments }} installments
                            </div>
                        </div>

                        <div
                            class="mt-4 flex gap-2 border-t border-border pt-4"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                class="flex-1"
                                @click="openEditModal(payment)"
                            >
                                Edit
                            </Button>
                            <Button
                                v-if="payment.is_active"
                                variant="outline"
                                size="sm"
                                class="flex-1"
                                @click="generatePayments(payment)"
                            >
                                Generate
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive"
                                @click="deletePayment(payment)"
                            >
                                Delete
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <EmptyState
                v-else
                title="No planned payments"
                description="Set up planned payments to track your subscriptions and bills"
                :icon="RefreshCw"
            >
                <template #action>
                    <Button @click="showCreateModal = true">
                        <Plus class="h-4 w-4" />
                        Add Planned Payment
                    </Button>
                </template>
            </EmptyState>
        </div>

        <Modal
            :open="showCreateModal"
            title="Create Planned Payment"
            description="Set up a new planned payment, subscription or bill"
            size="lg"
            @close="showCreateModal = false"
        >
            <RecurringPaymentForm
                :accounts="props.accounts"
                :categories="props.categories"
                action="/recurring-payments"
                method="post"
                @success="handleCreateSuccess"
                @cancel="showCreateModal = false"
            />
        </Modal>

        <Modal
            :open="showEditModal"
            title="Edit Planned Payment"
            description="Update planned payment details"
            size="lg"
            @close="closeEditModal"
        >
            <RecurringPaymentForm
                v-if="editingPayment"
                :recurring-payment="editingPayment"
                :accounts="props.accounts"
                :categories="props.categories"
                :action="`/recurring-payments/${editingPayment.id}`"
                method="patch"
                @success="handleEditSuccess"
                @cancel="closeEditModal"
            />
        </Modal>
    </AppLayout>
</template>
