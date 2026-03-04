<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Check } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    modelValue?: boolean;
    id?: string;
    disabled?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

const checkboxClasses = computed(() =>
    cn(
        'peer h-5 w-5 shrink-0 rounded-md border-2 transition-all duration-200',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/20',
        'disabled:cursor-not-allowed disabled:opacity-50',
        props.modelValue
            ? 'border-primary bg-primary text-primary-foreground'
            : 'border-input hover:border-primary/50',
        props.class
    )
);

function toggle() {
    if (!props.disabled) {
        emit('update:modelValue', !props.modelValue);
    }
}
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="modelValue"
        :id="id"
        :disabled="disabled"
        :class="checkboxClasses"
        @click="toggle"
    >
        <Check
            v-if="modelValue"
            class="h-3.5 w-3.5 text-white"
        />
    </button>
</template>
