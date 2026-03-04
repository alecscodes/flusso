<script setup lang="ts">
import { Button, Input, Label } from '@/components/ui';
import {
    categoryIconsList,
    getCategoryIconComponent,
} from '@/lib/categoryIcons';
import type { Category } from '@/types';
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Props {
    category?: Category | null;
    type?: 'income' | 'expense';
    action: string;
    method?: 'post' | 'put' | 'patch';
}

const props = withDefaults(defineProps<Props>(), {
    type: 'expense',
    method: 'post',
});

const emit = defineEmits<{
    success: [];
    cancel: [];
}>();

const isEditing = computed(() => !!props.category);

const formData = ref({
    name: props.category?.name || '',
    type: props.category?.type || props.type,
    icon: props.category?.icon || 'zap',
    color: props.category?.color || '#7c3aed',
});

const iconSearch = ref('');

const filteredIcons = computed(() => {
    const q = iconSearch.value.trim().toLowerCase();
    if (!q) return categoryIconsList;
    return categoryIconsList.filter(({ name }) => name.includes(q));
});

const customColorInputRef = ref<HTMLInputElement | null>(null);

const colors = [
    '#7c3aed',
    '#8b5cf6',
    '#6366f1',
    '#3b82f6',
    '#0ea5e9',
    '#14b8a6',
    '#10b981',
    '#22c55e',
    '#eab308',
    '#f59e0b',
    '#f97316',
    '#ef4444',
    '#ec4899',
    '#d946ef',
    '#a855f7',
];
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
                <Label for="name" required>Category Name</Label>
                <Input
                    id="name"
                    v-model="formData.name"
                    name="name"
                    placeholder="e.g., Groceries, Salary"
                    required
                />
            </div>

            <input type="hidden" name="type" :value="formData.type" />

            <div class="space-y-2">
                <Label>Icon</Label>
                <Input
                    v-model="iconSearch"
                    type="search"
                    placeholder="Search icons..."
                    class="mb-2"
                />
                <div
                    class="grid max-h-50 grid-cols-7 gap-2 overflow-y-auto rounded-xl border border-border p-2"
                >
                    <button
                        v-for="icon in filteredIcons"
                        :key="icon.name"
                        type="button"
                        :class="[
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border-2 transition-all',
                            formData.icon === icon.name
                                ? 'border-primary bg-primary/10'
                                : 'border-border hover:border-primary/50',
                        ]"
                        :title="icon.name"
                        @click="formData.icon = icon.name"
                    >
                        <component
                            :is="icon.component"
                            class="h-5 w-5"
                            :style="{ color: formData.color }"
                        />
                    </button>
                    <p
                        v-if="filteredIcons.length === 0"
                        class="col-span-full text-center text-sm text-muted-foreground"
                    >
                        No icons match "{{ iconSearch }}".
                    </p>
                </div>
                <input type="hidden" name="icon" :value="formData.icon" />
            </div>

            <div class="space-y-2">
                <Label>Color</Label>
                <div class="grid grid-cols-8 gap-2">
                    <button
                        v-for="color in colors"
                        :key="color"
                        type="button"
                        :class="[
                            'h-8 w-8 rounded-full border-2 transition-all',
                            formData.color === color
                                ? 'scale-110 border-foreground'
                                : 'border-transparent hover:scale-105',
                        ]"
                        :style="{ backgroundColor: color }"
                        @click="formData.color = color"
                    />
                    <button
                        type="button"
                        :class="[
                            'h-8 w-8 rounded-full border-2 transition-all',
                            colors.includes(formData.color)
                                ? 'border-4 border-black/50 hover:scale-105'
                                : 'scale-110 border-foreground',
                        ]"
                        :style="{ backgroundColor: formData.color }"
                        title="Pick custom color"
                        @click="customColorInputRef?.click()"
                    />
                </div>
                <input
                    ref="customColorInputRef"
                    v-model="formData.color"
                    type="color"
                    class="sr-only"
                    aria-hidden="true"
                />
                <input type="hidden" name="color" :value="formData.color" />
            </div>

            <div class="rounded-xl border border-border bg-muted/30 p-4">
                <p class="mb-2 text-sm font-medium text-muted-foreground">
                    Preview
                </p>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium"
                    :style="{
                        backgroundColor: `${formData.color}15`,
                        color: formData.color,
                    }"
                >
                    <component
                        :is="getCategoryIconComponent(formData.icon)"
                        class="h-4 w-4"
                    />
                    {{ formData.name || 'Category Name' }}
                </span>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" @click="emit('cancel')">
                Cancel
            </Button>
            <Button type="submit">
                {{ isEditing ? 'Update Category' : 'Create Category' }}
            </Button>
        </div>
    </Form>
</template>
