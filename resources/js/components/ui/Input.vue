<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

interface Props {
    modelValue?: string | number;
    type?: string;
    error?: string;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    type: 'text',
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number];
}>();

const attrs = useAttrs();

const inputClasses = computed(() =>
    cn(
        'flex h-11 w-full rounded-xl border bg-background px-4 py-2 text-sm transition-all duration-200',
        'placeholder:text-muted-foreground',
        'focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'file:border-0 file:bg-transparent file:text-sm file:font-medium',
        props.error
            ? 'border-destructive focus:ring-destructive/20 focus:border-destructive'
            : 'border-input hover:border-primary/50',
        props.class
    )
);

function handleInput(event: Event) {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
}
</script>

<template>
    <div class="w-full">
        <input
            :type="type"
            :value="modelValue"
            :class="inputClasses"
            v-bind="attrs"
            @input="handleInput"
        />
        <p
            v-if="error"
            class="mt-1.5 text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
