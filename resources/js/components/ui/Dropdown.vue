<script setup lang="ts">
import { cn } from '@/lib/utils';
import { onClickOutside } from '@vueuse/core';
import { computed, ref } from 'vue';

interface Props {
    align?: 'left' | 'right';
    width?: 'auto' | 'sm' | 'md' | 'lg';
    placement?: 'above' | 'below';
    class?: string;
    wrapperClass?: string;
}

const props = withDefaults(defineProps<Props>(), {
    align: 'right',
    width: 'auto',
    placement: 'above',
});

const openModel = defineModel<boolean>('open', { default: undefined });
const internalOpen = ref(false);
const isControlled = computed(() => openModel.value !== undefined);
const open = computed({
    get: () =>
        isControlled.value ? openModel.value! : internalOpen.value,
    set: (v: boolean) => {
        if (isControlled.value) {
            openModel.value = v;
        } else {
            internalOpen.value = v;
        }
    },
});
const dropdownRef = ref<HTMLElement | null>(null);

const widthClasses = {
    auto: 'w-auto',
    sm: 'w-40',
    md: 'w-56',
    lg: 'w-72',
};

const contentClasses = computed(() =>
    cn(
        'absolute z-50 rounded-xl border border-border bg-card p-1 shadow-lg',
        'animate-in fade-in-0 zoom-in-95 duration-150',
        props.placement === 'above'
            ? 'bottom-full mb-2'
            : 'top-full mt-2',
        props.align === 'right' ? 'right-0' : 'left-0',
        widthClasses[props.width],
        props.class
    )
);

onClickOutside(dropdownRef, () => {
    open.value = false;
});

function toggle() {
    open.value = !open.value;
}

function close() {
    open.value = false;
}
</script>

<template>
    <div
        ref="dropdownRef"
        :class="cn('relative inline-block', props.wrapperClass)"
    >
        <div @click.stop="toggle">
            <slot name="trigger" />
        </div>
        <Transition
            enter-active-class="duration-150 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="duration-100 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="open"
                :class="contentClasses"
            >
                <slot :close="close" />
            </div>
        </Transition>
    </div>
</template>
