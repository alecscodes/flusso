<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { Dropdown, Input } from '@/components/ui';

export interface SearchableSelectOption {
    value: string | number;
    label: string;
}

interface Props {
    modelValue?: string | number;
    options: SearchableSelectOption[];
    placeholder?: string;
    name?: string;
    id?: string;
    disabled?: boolean;
    required?: boolean;
    error?: string;
    class?: string;
    searchPlaceholder?: string;
    emptyMessage?: string;
}

const props = withDefaults(defineProps<Props>(), {
    searchPlaceholder: 'Search...',
    emptyMessage: 'No option found.',
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number];
}>();

const open = ref(false);
const searchQuery = ref('');
const searchInputRef = ref<HTMLInputElement | null>(null);

const selectedOption = computed(() =>
    props.options.find(
        (opt) => String(opt.value) === String(props.modelValue ?? ''),
    ),
);

const displayLabel = computed(() => selectedOption.value?.label ?? props.placeholder ?? '');

const filteredOptions = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((opt) =>
        opt.label.toLowerCase().includes(q),
    );
});

const triggerClasses = computed(() =>
    cn(
        'flex h-11 w-full appearance-none items-center justify-between rounded-xl border bg-background px-4 py-2 pr-10 text-sm transition-all duration-200',
        'focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary',
        'disabled:cursor-not-allowed disabled:opacity-50',
        props.error
            ? 'border-destructive focus:ring-destructive/20 focus:border-destructive'
            : 'border-input hover:border-primary/50',
        props.class,
    ),
);

watch(open, (isOpen) => {
    if (isOpen) {
        searchQuery.value = '';
        nextTick(() => searchInputRef.value?.focus());
    }
});

function selectOption(option: SearchableSelectOption) {
    emit('update:modelValue', option.value);
    open.value = false;
}

function handleTriggerClick(event: MouseEvent) {
    if (props.disabled) return;
    event.stopPropagation();
    open.value = !open.value;
}

function closeDropdown() {
    open.value = false;
}
</script>

<template>
    <div class="relative w-full">
        <Dropdown
            v-model:open="open"
            align="left"
            width="auto"
            placement="below"
            class="w-full"
            wrapper-class="w-full"
        >
            <template #trigger>
                <button
                    :id="id"
                    type="button"
                    :class="triggerClasses"
                    :disabled="disabled"
                    :aria-expanded="open"
                    aria-haspopup="listbox"
                    role="combobox"
                    aria-label="Choose an option"
                    @click="handleTriggerClick"
                >
                    <span
                        :class="
                            displayLabel ? 'text-foreground' : 'text-muted-foreground'
                        "
                    >
                        {{ displayLabel }}
                    </span>
                    <ChevronDown
                        class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 shrink-0 text-muted-foreground"
                    />
                </button>
            </template>
            <template #default>
                <div
                    class="w-full min-w-32 rounded-xl border border-border bg-card p-1 shadow-lg"
                    role="listbox"
                >
                    <div class="border-b border-border px-2 pb-2">
                        <input
                            ref="searchInputRef"
                            v-model="searchQuery"
                            type="text"
                            :placeholder="searchPlaceholder"
                            class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-1.5 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/20"
                            @keydown.escape="closeDropdown"
                        />
                    </div>
                    <div
                        class="max-h-60 overflow-auto py-1"
                        role="listbox"
                    >
                        <template v-if="filteredOptions.length > 0">
                            <button
                                v-for="option in filteredOptions"
                                :key="String(option.value)"
                                type="button"
                                role="option"
                                :aria-selected="
                                    String(option.value) ===
                                    String(modelValue ?? '')
                                "
                                :class="
                                    cn(
                                        'relative flex w-full cursor-pointer select-none items-center rounded-lg px-3 py-2 text-sm outline-none transition-colors',
                                        'hover:bg-accent hover:text-accent-foreground',
                                        String(option.value) ===
                                            String(modelValue ?? '')
                                            ? 'bg-accent text-accent-foreground'
                                            : '',
                                    )
                                "
                                @click="selectOption(option)"
                            >
                                {{ option.label }}
                            </button>
                        </template>
                        <p
                            v-else
                            class="py-6 text-center text-sm text-muted-foreground"
                        >
                            {{ emptyMessage }}
                        </p>
                    </div>
                </div>
            </template>
        </Dropdown>
        <input
            v-if="name"
            type="hidden"
            :name="name"
            :value="modelValue ?? ''"
        />
        <p
            v-if="error"
            class="mt-1.5 text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
