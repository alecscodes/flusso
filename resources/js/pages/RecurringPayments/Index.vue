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
} from '@/components/ui';
import { useDate } from '@/composables';
import { intervalLabel } from '@/lib/utils';
import {
    destroy as destroyRecurringPayment,
    show as showRecurringPayment,
    store as recurringPaymentStore,
    update as recurringPaymentUpdate,
} from '@/routes/recurring-payments';
import type { Account, Category, RecurringPayment } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Calendar, ChevronDown, Plus, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';

interface Props {
    recurringPayments: RecurringPayment[];
    accounts: Account[];
    categories: Category[];
}

const props = defineProps<Props>();

const { formatDate } = useDate();

const showCreateModal = ref(false);
const editingRule = ref<RecurringPayment | null>(null);
const showFinished = ref(false);

const current = computed(() =>
    props.recurringPayments.filter((rule) => rule.status !== 'finished'),
);
const finished = computed(() =>
    props.recurringPayments.filter((rule) => rule.status === 'finished'),
);

function scheduleLabel(rule: RecurringPayment): string {
    return rule.installments === 1
        ? 'Once'
        : intervalLabel(rule.interval_value, rule.interval_type);
}

function nextDue(rule: RecurringPayment): string | null {
    return (
        rule.payments
            ?.filter((p) => p.transaction_id === null && p.skipped_at === null)
            .map((p) => p.due_date)
            .sort()[0] ?? null
    );
}

function progress(rule: RecurringPayment): string | null {
    if (!rule.installments || rule.installments === 1) return null;

    const paid = rule.payments?.filter((p) => p.transaction_id !== null);

    return `${paid?.length ?? 0} of ${rule.installments} paid`;
}

function deleteRule(rule: RecurringPayment) {
    if (
        confirm(
            `Delete "${rule.name}"? Unpaid payments are removed, what you already paid stays in your transactions.`,
        )
    ) {
        router.delete(destroyRecurringPayment.url(rule));
    }
}
</script>

<template>
    <Head title="Planned Payments" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Planned Payments
                </h1>
                <p class="mt-1 text-muted-foreground">
                    Your bills, subscriptions and one-off payments
                </p>
            </div>
            <Button @click="showCreateModal = true">
                <Plus class="h-4 w-4" />
                Add planned payment
            </Button>
        </div>

        <div
            v-if="current.length > 0"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <Card
                v-for="rule in current"
                :key="rule.id"
                :class="
                    rule.status === 'paused'
                        ? 'group opacity-60 transition-all duration-300'
                        : 'group transition-all duration-300'
                "
                hover
            >
                <CardContent class="pt-6">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                :class="[
                                    'flex h-12 w-12 shrink-0 items-center justify-center rounded-xl',
                                    rule.status === 'active'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-muted text-muted-foreground',
                                ]"
                            >
                                <RefreshCw class="h-6 w-6" />
                            </div>
                            <div class="min-w-0">
                                <Link
                                    :href="showRecurringPayment.url(rule)"
                                    class="block truncate font-semibold text-foreground hover:text-primary"
                                >
                                    {{ rule.name }}
                                </Link>
                                <p class="text-sm text-muted-foreground">
                                    {{ scheduleLabel(rule) }}
                                </p>
                            </div>
                        </div>
                        <Badge
                            :variant="
                                rule.status === 'active'
                                    ? 'success'
                                    : 'secondary'
                            "
                            class="shrink-0"
                        >
                            {{ rule.status === 'active' ? 'Active' : 'Paused' }}
                        </Badge>
                    </div>

                    <div class="mt-4">
                        <AmountDisplay
                            :amount="rule.amount"
                            :currency="rule.currency"
                            :type="
                                rule.category?.type === 'income'
                                    ? 'income'
                                    : 'expense'
                            "
                            size="lg"
                            :show-sign="false"
                        />
                    </div>

                    <div class="mt-4 space-y-2">
                        <CategoryBadge
                            v-if="rule.category"
                            :category="rule.category"
                            size="sm"
                        />
                        <div
                            v-if="nextDue(rule)"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Calendar class="h-4 w-4" />
                            Next {{ formatDate(nextDue(rule)!) }}
                        </div>
                        <div
                            v-if="rule.account"
                            class="text-sm text-muted-foreground"
                        >
                            From: {{ rule.account.name }}
                        </div>
                        <div
                            v-if="progress(rule)"
                            class="text-sm text-muted-foreground"
                        >
                            {{ progress(rule) }}
                        </div>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4"
                    >
                        <Link
                            :href="showRecurringPayment.url(rule)"
                            class="min-w-24 flex-1"
                        >
                            <Button variant="outline" size="sm" class="w-full">
                                Details
                            </Button>
                        </Link>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="editingRule = rule"
                        >
                            Edit
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            @click="deleteRule(rule)"
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
            description="Add the bills and subscriptions you pay, so you always know what is coming"
            :icon="RefreshCw"
        >
            <template #action>
                <Button @click="showCreateModal = true">
                    <Plus class="h-4 w-4" />
                    Add planned payment
                </Button>
            </template>
        </EmptyState>

        <div v-if="finished.length > 0" class="space-y-3">
            <button
                type="button"
                class="flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                :aria-expanded="showFinished"
                @click="showFinished = !showFinished"
            >
                <ChevronDown
                    :class="[
                        'h-4 w-4 transition-transform',
                        showFinished && 'rotate-180',
                    ]"
                />
                Finished ({{ finished.length }})
            </button>

            <div
                v-if="showFinished"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Card
                    v-for="rule in finished"
                    :key="rule.id"
                    class="opacity-70"
                >
                    <CardContent
                        class="flex items-center justify-between gap-3 py-4"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="showRecurringPayment.url(rule)"
                                class="block truncate font-medium text-foreground hover:text-primary"
                            >
                                {{ rule.name }}
                            </Link>
                            <p class="text-sm text-muted-foreground">
                                {{ scheduleLabel(rule) }} ·
                                {{ rule.payments?.length ?? 0 }} paid or skipped
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            @click="deleteRule(rule)"
                        >
                            Delete
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>

    <Modal
        :open="showCreateModal"
        title="Add planned payment"
        description="A bill, a subscription or a one-off you want to remember"
        size="lg"
        @close="showCreateModal = false"
    >
        <RecurringPaymentForm
            :accounts="accounts"
            :categories="categories"
            :action="recurringPaymentStore.url()"
            @success="showCreateModal = false"
            @cancel="showCreateModal = false"
        />
    </Modal>

    <Modal
        :open="!!editingRule"
        title="Edit planned payment"
        size="lg"
        @close="editingRule = null"
    >
        <RecurringPaymentForm
            v-if="editingRule"
            :recurring-payment="editingRule"
            :accounts="accounts"
            :categories="categories"
            :action="recurringPaymentUpdate.url(editingRule)"
            @success="editingRule = null"
            @cancel="editingRule = null"
        />
    </Modal>
</template>
