<script setup lang="ts">
import { BalanceSummary } from '@/components/data-display';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
    Input,
    Label,
} from '@/components/ui';
import { useCurrency, useDate } from '@/composables';
import { assign, show, store } from '@/routes/counterparties';
import type { CounterpartyBalance } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';

interface Suggestion {
    transaction_id: number;
    date: string;
    amount: number;
    currency: string;
    description: string | null;
    counterparty_id: number;
    counterparty_name: string;
}

interface Props {
    counterparties: CounterpartyBalance[];
    suggestions: Suggestion[];
}

defineProps<Props>();

const { formatCurrency } = useCurrency();
const { formatDate } = useDate();

const form = useForm({ name: '' });

function addPerson() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function link(suggestion: Suggestion) {
    router.post(
        assign.url(suggestion.counterparty_id),
        { transaction_id: suggestion.transaction_id },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="People" />

    <div class="space-y-8">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-foreground">
                People
            </h1>
            <p class="mt-1 text-muted-foreground">
                What you paid for others and what they paid back, per person
            </p>
        </div>

        <Card>
            <CardContent class="pt-6">
                <form
                    class="flex flex-col gap-3 sm:flex-row sm:items-end"
                    @submit.prevent="addPerson"
                >
                    <div class="w-full space-y-2 sm:max-w-sm">
                        <Label for="person_name">Add a person</Label>
                        <Input
                            id="person_name"
                            v-model="form.name"
                            name="name"
                            placeholder="e.g. Mama"
                            maxlength="100"
                            :error="form.errors.name"
                        />
                    </div>
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.name.trim()"
                    >
                        <Plus class="h-4 w-4" />
                        Add
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card v-if="suggestions.length > 0">
            <CardHeader>
                <CardTitle>Looks like they paid you</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2">
                <div
                    v-for="suggestion in suggestions"
                    :key="suggestion.transaction_id"
                    class="flex flex-col gap-2 rounded-xl p-3 hover:bg-muted/50 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-foreground">
                            {{ suggestion.description }}
                        </p>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{ formatDate(suggestion.date) }} ·
                            {{
                                formatCurrency(
                                    suggestion.amount,
                                    suggestion.currency,
                                )
                            }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="link(suggestion)"
                    >
                        Link to {{ suggestion.counterparty_name }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="counterparties.length > 0"
            class="grid grid-cols-1 gap-6 lg:grid-cols-2"
        >
            <Link
                v-for="person in counterparties"
                :key="person.id"
                :href="show.url(person.id)"
                class="block"
            >
                <Card hover>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Users class="size-5" />
                            {{ person.name }}
                            <Badge
                                v-if="person.over_settled_count > 0"
                                variant="destructive"
                            >
                                Over-settled
                            </Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BalanceSummary
                            :name="person.name"
                            :balances="person.balances"
                        />
                    </CardContent>
                </Card>
            </Link>
        </div>
        <EmptyState
            v-else
            title="No people yet"
            description="Add someone you pay for, then mark expenses as paid for them"
            :icon="Users"
        />
    </div>
</template>
