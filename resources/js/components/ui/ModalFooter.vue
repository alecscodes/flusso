<script setup lang="ts">
import { inject } from 'vue';
import { modalFooterId } from './modalFooter';

/**
 * Renders its buttons in the surrounding Modal's footer so Save stays visible
 * while the form scrolls. Only usable inside a Modal.
 */
const footerId = inject(modalFooterId);
</script>

<template>
    <Teleport :to="`#${footerId}`" defer>
        <slot />
    </Teleport>
</template>
