<script setup lang="ts">
import { cn } from '@/lib/utils';
import { X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    provide,
    ref,
    shallowRef,
    useId,
    watch,
} from 'vue';
import { modalFooterKey } from './modalFooter';

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

const titleId = useId();
const panelRef = ref<HTMLElement | null>(null);
const bodyRef = ref<HTMLElement | null>(null);
const footerEl = shallowRef<HTMLElement | null>(null);
const hasTeleportedFooter = ref(false);
let previouslyFocused: HTMLElement | null = null;

provide(modalFooterKey, {
    target: footerEl,
    claim: (claimed: boolean) => (hasTeleportedFooter.value = claimed),
});

const sizeClasses = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-4xl',
    full: 'sm:max-w-[calc(100vw-2rem)]',
};

const contentClasses = computed(() =>
    cn(
        'relative flex w-full flex-col overflow-hidden bg-card shadow-2xl',
        'max-h-[90dvh] rounded-t-2xl sm:max-h-[calc(100dvh-2rem)] sm:rounded-2xl',
        'animate-in fade-in-0 slide-in-from-bottom-8 duration-200 sm:slide-in-from-bottom-0 sm:zoom-in-95',
        sizeClasses[props.size],
        props.class,
    ),
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
    async (open) => {
        document.body.style.overflow = open ? 'hidden' : '';

        if (open) {
            previouslyFocused = document.activeElement as HTMLElement | null;
            await nextTick();
            const firstField = bodyRef.value?.querySelector<HTMLElement>(
                'input:not([type=hidden]):not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled])',
            );
            (firstField ?? panelRef.value)?.focus({ preventScroll: true });
        } else {
            previouslyFocused?.focus({ preventScroll: true });
            previouslyFocused = null;
        }
    },
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
                class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
            >
                <div
                    class="fixed inset-0 bg-black/50 backdrop-blur-sm"
                    @click="handleBackdropClick"
                />
                <div
                    ref="panelRef"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="title ? titleId : undefined"
                    tabindex="-1"
                    :class="contentClasses"
                >
                    <div
                        v-if="title || $slots.header"
                        class="flex shrink-0 items-start justify-between px-6 pt-6 pb-4"
                    >
                        <div>
                            <slot name="header">
                                <h2
                                    :id="titleId"
                                    class="text-xl font-semibold text-foreground"
                                >
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
                            aria-label="Close"
                            class="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            @click="emit('close')"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <div
                        ref="bodyRef"
                        class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 pb-6"
                    >
                        <slot />
                    </div>
                    <div
                        v-show="$slots.footer || hasTeleportedFooter"
                        ref="footerEl"
                        class="sticky bottom-0 flex shrink-0 justify-end gap-3 border-t border-border bg-card px-6 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
