<script setup lang="ts">
import { CategoryBadge } from '@/components/data-display';
import { CategoryForm } from '@/components/forms';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyState,
    Modal,
} from '@/components/ui';
import { AppLayout } from '@/layouts';
import type { Category } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArrowUp, Plus, Tag } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    categories: Category[];
}

const props = defineProps<Props>();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingCategory = ref<Category | null>(null);
const createType = ref<'income' | 'expense'>('expense');

const incomeCategories = computed(() =>
    props.categories.filter((c) => c.type === 'income'),
);
const expenseCategories = computed(() =>
    props.categories.filter((c) => c.type === 'expense'),
);

function openCreateModal(type: 'income' | 'expense') {
    createType.value = type;
    showCreateModal.value = true;
}

function openEditModal(category: Category) {
    editingCategory.value = category;
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingCategory.value = null;
}

function handleCreateSuccess() {
    showCreateModal.value = false;
}

function handleEditSuccess() {
    closeEditModal();
}

function deleteCategory(category: Category) {
    if (confirm(`Are you sure you want to delete "${category.name}"?`)) {
        router.delete(`/categories/${category.id}`);
    }
}
</script>

<template>
    <Head title="Categories" />

    <AppLayout>
        <div class="space-y-8">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Categories
                    </h1>
                    <p class="mt-1 text-muted-foreground">
                        Organize your transactions with custom categories
                    </p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between"
                    >
                        <CardTitle class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-emerald-500" />
                            Income Categories
                        </CardTitle>
                        <Button size="sm" @click="openCreateModal('income')">
                            <Plus class="h-4 w-4" />
                            Add
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="incomeCategories.length > 0"
                            class="space-y-2"
                        >
                            <div
                                v-for="category in incomeCategories"
                                :key="category.id"
                                class="group flex items-center justify-between rounded-xl p-3 transition-colors hover:bg-muted/50"
                            >
                                <CategoryBadge :category="category" size="md" />
                                <div
                                    class="flex gap-1 opacity-100 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="openEditModal(category)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive hover:text-destructive"
                                        @click="deleteCategory(category)"
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <EmptyState
                            v-else
                            title="No income categories"
                            description="Add categories to organize your income"
                            :icon="ArrowUp"
                            class="py-8"
                        >
                            <template #action>
                                <Button
                                    size="sm"
                                    @click="openCreateModal('income')"
                                >
                                    <Plus class="h-4 w-4" />
                                    Add Category
                                </Button>
                            </template>
                        </EmptyState>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between"
                    >
                        <CardTitle class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-rose-500" />
                            Expense Categories
                        </CardTitle>
                        <Button size="sm" @click="openCreateModal('expense')">
                            <Plus class="h-4 w-4" />
                            Add
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="expenseCategories.length > 0"
                            class="space-y-2"
                        >
                            <div
                                v-for="category in expenseCategories"
                                :key="category.id"
                                class="group flex items-center justify-between rounded-xl p-3 transition-colors hover:bg-muted/50"
                            >
                                <CategoryBadge :category="category" size="md" />
                                <div
                                    class="flex gap-1 opacity-100 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="openEditModal(category)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive hover:text-destructive"
                                        @click="deleteCategory(category)"
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <EmptyState
                            v-else
                            title="No expense categories"
                            description="Add categories to organize your expenses"
                            :icon="Tag"
                            class="py-8"
                        >
                            <template #action>
                                <Button
                                    size="sm"
                                    @click="openCreateModal('expense')"
                                >
                                    <Plus class="h-4 w-4" />
                                    Add Category
                                </Button>
                            </template>
                        </EmptyState>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Modal
            :open="showCreateModal"
            title="Create Category"
            :description="`Add a new ${createType} category`"
            @close="showCreateModal = false"
        >
            <CategoryForm
                :type="createType"
                action="/categories"
                method="post"
                @success="handleCreateSuccess"
                @cancel="showCreateModal = false"
            />
        </Modal>

        <Modal
            :open="showEditModal"
            title="Edit Category"
            description="Update category details"
            @close="closeEditModal"
        >
            <CategoryForm
                v-if="editingCategory"
                :category="editingCategory"
                :type="editingCategory.type"
                :action="`/categories/${editingCategory.id}`"
                method="patch"
                @success="handleEditSuccess"
                @cancel="closeEditModal"
            />
        </Modal>
    </AppLayout>
</template>
