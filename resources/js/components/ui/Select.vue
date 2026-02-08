<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';
import { computed, useAttrs } from 'vue';

interface Props {
    modelValue?: string | number;
    placeholder?: string;
    error?: string;
    class?: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string | number];
}>();

const attrs = useAttrs();

const selectClasses = computed(() =>
    cn(
        'flex h-11 w-full appearance-none rounded-xl border bg-background px-4 py-2 pr-10 text-sm transition-all duration-200',
        'focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary',
        'disabled:cursor-not-allowed disabled:opacity-50',
        props.error
            ? 'border-destructive focus:ring-destructive/20 focus:border-destructive'
            : 'border-input hover:border-primary/50',
        props.class
    )
);

function handleChange(event: Event) {
    const target = event.target as HTMLSelectElement;
    emit('update:modelValue', target.value);
}
</script>

<template>
    <div class="relative w-full">
        <select
            :value="modelValue"
            :class="selectClasses"
            v-bind="attrs"
            @change="handleChange"
        >
            <option
                v-if="placeholder"
                value=""
                disabled
            >
                {{ placeholder }}
            </option>
            <slot />
        </select>
        <ChevronDown
            class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        />
        <p
            v-if="error"
            class="mt-1.5 text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
