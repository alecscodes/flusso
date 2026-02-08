<script setup lang="ts">
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, watch } from 'vue';

interface Props {
    open: boolean;
    title?: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
});

const emit = defineEmits<{
    close: [];
}>();

const sizeClasses = {
    sm: 'max-w-sm',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
    full: 'max-w-[calc(100vw-2rem)]',
};

const contentClasses = computed(() =>
    cn(
        'relative w-full rounded-2xl bg-card p-6 shadow-2xl',
        'animate-in fade-in-0 zoom-in-95 duration-200',
        sizeClasses[props.size],
        props.class
    )
);

function handleEscape(e: KeyboardEvent) {
    if (e.key === 'Escape' && props.open) {
        emit('close');
    }
}

function handleBackdropClick(e: MouseEvent) {
    if (e.target === e.currentTarget) {
        emit('close');
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }
);

onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
            >
                <div
                    class="fixed inset-0 bg-black/50 backdrop-blur-sm"
                    @click="handleBackdropClick"
                />
                <div :class="contentClasses">
                    <div
                        v-if="title || $slots.header"
                        class="mb-4 flex items-start justify-between"
                    >
                        <div>
                            <slot name="header">
                                <h2 class="text-xl font-semibold text-foreground">
                                    {{ title }}
                                </h2>
                                <p
                                    v-if="description"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{ description }}
                                </p>
                            </slot>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            @click="emit('close')"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <slot />
                    <div
                        v-if="$slots.footer"
                        class="mt-6 flex justify-end gap-3"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
