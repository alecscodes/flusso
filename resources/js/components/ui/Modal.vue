<script setup lang="ts">
import { cn, isCoarsePointer, lockPageScroll } from '@/lib/utils';
import { X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onUnmounted,
    provide,
    ref,
    useId,
    watch,
} from 'vue';
import { modalFooterId } from './modalFooter';

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

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]';
const NOT_SKIPPED = ':not([tabindex="-1"])';

const titleId = useId();
const footerId = useId();
const panelRef = ref<HTMLElement | null>(null);
const bodyRef = ref<HTMLElement | null>(null);
// Stays mounted until the leave transition ends, so the content does not vanish mid-fade.
const rendered = ref(props.open);
let previouslyFocused: HTMLElement | null = null;
let releaseScroll: (() => void) | null = null;

provide(modalFooterId, footerId);

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

function focusables(root: HTMLElement | null): HTMLElement[] {
    return Array.from(
        root?.querySelectorAll<HTMLElement>(
            FOCUSABLE.split(', ')
                .map((selector) => selector + NOT_SKIPPED)
                .join(', '),
        ) ?? [],
    ).filter((el) => el.getClientRects().length > 0);
}

/** Keeps Tab and Shift+Tab inside the dialog. */
function trapFocus(event: KeyboardEvent) {
    const panel = panelRef.value;
    const items = focusables(panel);
    const first = items[0];
    const last = items[items.length - 1];

    if (!panel || !first || !last) {
        event.preventDefault();
        panel?.focus();
    } else if (
        event.shiftKey &&
        (document.activeElement === first || document.activeElement === panel)
    ) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            rendered.value = true;
            releaseScroll ??= lockPageScroll();
            previouslyFocused = document.activeElement as HTMLElement | null;
            await nextTick();
            // On touch devices a focused field raises the keyboard over the sheet: focus the dialog and let the user tap a field.
            const firstField = isCoarsePointer()
                ? undefined
                : focusables(bodyRef.value)[0];
            (firstField ?? panelRef.value)?.focus({ preventScroll: true });
        } else {
            releaseScroll?.();
            releaseScroll = null;
            previouslyFocused?.focus({ preventScroll: true });
            previouslyFocused = null;
        }
    },
    { immediate: true },
);

onUnmounted(() => releaseScroll?.());
</script>

<template>
    <Teleport to="body">
        <Transition
            appear
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
            @after-leave="rendered = false"
        >
            <div
                v-if="rendered"
                v-show="open"
                class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
            >
                <div
                    class="fixed inset-0 bg-black/50 backdrop-blur-sm"
                    @click="emit('close')"
                />
                <div
                    ref="panelRef"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="title ? titleId : undefined"
                    :aria-label="title ? undefined : 'Dialog'"
                    tabindex="-1"
                    :class="contentClasses"
                    @keydown.esc.stop="emit('close')"
                    @keydown.tab="trapFocus"
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
                        :id="footerId"
                        class="flex shrink-0 justify-end gap-3 border-t border-border bg-card px-6 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))] empty:hidden"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
