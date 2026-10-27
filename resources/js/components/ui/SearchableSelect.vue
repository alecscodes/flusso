<script setup lang="ts">
import { cn, isCoarsePointer } from '@/lib/utils';
import { ChevronDown } from '@lucide/vue';
import { onClickOutside, useEventListener } from '@vueuse/core';
import { computed, nextTick, ref, useId, watch } from 'vue';

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
    ariaLabel?: string;
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
const activeIndex = ref(0);
const searchInputRef = ref<HTMLInputElement | null>(null);
const triggerRef = ref<HTMLButtonElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const panelStyle = ref<Record<string, string>>({});
const listboxId = useId();

const selectedOption = computed(() =>
    props.options.find(
        (opt) => String(opt.value) === String(props.modelValue ?? ''),
    ),
);

const displayLabel = computed(
    () => selectedOption.value?.label ?? props.placeholder ?? '',
);

const filteredOptions = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((opt) =>
        opt.label.toLowerCase().includes(q),
    );
});

const optionId = (index: number) => `${listboxId}-${index}`;

const activeOptionId = computed(() =>
    filteredOptions.value.length > 0 ? optionId(activeIndex.value) : undefined,
);

const triggerClasses = computed(() =>
    cn(
        'relative flex h-11 w-full appearance-none items-center justify-between rounded-xl border bg-background px-4 py-2 pr-10 text-base md:text-sm transition-all duration-200',
        'focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary',
        'disabled:cursor-not-allowed disabled:opacity-50',
        props.error
            ? 'border-destructive focus:ring-destructive/20 focus:border-destructive'
            : 'border-input hover:border-primary/50',
        props.class,
    ),
);

/**
 * The panel is teleported to <body> and positioned with `fixed`, so inside a
 * scrolling modal it overlays the content instead of growing the scroll height.
 * Fixed coordinates are relative to the layout viewport; the visible part of it
 * is the visual viewport, which shrinks when the mobile keyboard is up.
 */
function positionPanel() {
    const rect = triggerRef.value?.getBoundingClientRect();
    if (!rect) return;

    const visual = window.visualViewport;
    const visibleTop = visual?.offsetTop ?? 0;
    const visibleBottom = visibleTop + (visual?.height ?? window.innerHeight);
    const spaceBelow = visibleBottom - rect.bottom - 8;
    const spaceAbove = rect.top - visibleTop - 8;
    const openAbove = spaceBelow < 260 && spaceAbove > spaceBelow;
    const space = Math.max(160, openAbove ? spaceAbove : spaceBelow);

    panelStyle.value = {
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        maxHeight: `${space}px`,
        ...(openAbove
            ? {
                  bottom: `${document.documentElement.clientHeight - rect.top + 4}px`,
              }
            : { top: `${rect.bottom + 4}px` }),
    };
}

watch(open, (isOpen) => {
    if (isOpen) {
        searchQuery.value = '';
        activeIndex.value = Math.max(
            0,
            props.options.findIndex(
                (opt) => String(opt.value) === String(props.modelValue ?? ''),
            ),
        );
        positionPanel();
        // A focused search field would raise the keyboard on touch devices: there the user taps it when needed.
        if (!isCoarsePointer()) {
            nextTick(() => searchInputRef.value?.focus({ preventScroll: true }));
        }
    }
});

watch(searchQuery, () => (activeIndex.value = 0));

watch(activeIndex, async () => {
    await nextTick();
    document
        .getElementById(optionId(activeIndex.value))
        ?.scrollIntoView({ block: 'nearest' });
});

const reposition = () => open.value && positionPanel();

useEventListener(window, 'resize', reposition);
useEventListener(window.visualViewport, 'resize', reposition);
useEventListener(window.visualViewport, 'scroll', reposition);
useEventListener(
    window,
    'scroll',
    (e: Event) => {
        if (open.value && !panelRef.value?.contains(e.target as Node)) {
            positionPanel();
        }
    },
    { capture: true, passive: true },
);

onClickOutside(
    panelRef,
    () => {
        open.value = false;
    },
    { ignore: [triggerRef] },
);

/** Closes the panel; focus goes back to the trigger so the keyboard (and a parent dialog's Escape) keeps working. */
function closeDropdown() {
    open.value = false;
    triggerRef.value?.focus({ preventScroll: true });
}

function selectOption(option: SearchableSelectOption) {
    emit('update:modelValue', option.value);
    closeDropdown();
}

function moveActive(step: number) {
    const count = filteredOptions.value.length;
    if (count > 0) {
        activeIndex.value = (activeIndex.value + step + count) % count;
    }
}

function selectActive() {
    const option = filteredOptions.value[activeIndex.value];
    if (option) selectOption(option);
}

function handleTriggerClick(event: MouseEvent) {
    if (props.disabled) return;
    event.stopPropagation();
    open.value = !open.value;
}
</script>

<template>
    <div class="relative w-full">
        <button
            :id="id"
            ref="triggerRef"
            type="button"
            :class="triggerClasses"
            :disabled="disabled"
            :aria-expanded="open"
            :aria-required="required || undefined"
            :aria-label="ariaLabel"
            aria-haspopup="listbox"
            @click="handleTriggerClick"
        >
            <span
                class="truncate"
                :class="selectedOption ? 'text-foreground' : 'text-muted-foreground'"
            >
                {{ displayLabel }}
            </span>
            <ChevronDown
                class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 shrink-0 text-muted-foreground"
            />
        </button>
        <Teleport to="body">
            <div
                v-if="open"
                ref="panelRef"
                :style="panelStyle"
                class="fixed z-[60] flex flex-col rounded-xl border border-border bg-card p-1 shadow-lg animate-in fade-in-0 zoom-in-95 duration-150"
            >
                <div class="shrink-0 border-b border-border px-2 pb-2">
                    <input
                        ref="searchInputRef"
                        v-model="searchQuery"
                        type="text"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="true"
                        :aria-controls="listboxId"
                        :aria-activedescendant="activeOptionId"
                        :placeholder="searchPlaceholder"
                        :aria-label="searchPlaceholder"
                        class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-1.5 text-base placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                        @keydown.escape.stop="closeDropdown"
                        @keydown.down.prevent="moveActive(1)"
                        @keydown.up.prevent="moveActive(-1)"
                        @keydown.enter.prevent="selectActive"
                        @keydown.tab="closeDropdown"
                    />
                </div>
                <div
                    :id="listboxId"
                    role="listbox"
                    class="min-h-0 flex-1 overflow-auto py-1"
                >
                    <div
                        v-for="(option, index) in filteredOptions"
                        :id="optionId(index)"
                        :key="String(option.value)"
                        role="option"
                        :aria-selected="
                            String(option.value) === String(modelValue ?? '')
                        "
                        :class="
                            cn(
                                'relative flex w-full cursor-pointer items-center rounded-lg px-3 py-2 text-sm transition-colors select-none',
                                index === activeIndex &&
                                    'bg-accent text-accent-foreground',
                                String(option.value) ===
                                    String(modelValue ?? '') && 'font-medium',
                            )
                        "
                        @mousemove="activeIndex = index"
                        @click="selectOption(option)"
                    >
                        {{ option.label }}
                    </div>
                    <p
                        v-if="filteredOptions.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        {{ emptyMessage }}
                    </p>
                </div>
            </div>
        </Teleport>
        <input
            v-if="name || required"
            :type="required ? 'text' : 'hidden'"
            :name="name"
            :value="modelValue ?? ''"
            :required="required"
            :disabled="disabled"
            :class="
                required &&
                'pointer-events-none absolute inset-x-0 bottom-0 h-px w-full opacity-0'
            "
            :tabindex="required ? -1 : undefined"
            @focus="triggerRef?.focus()"
        />
        <p
            v-if="error"
            class="mt-1.5 text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
