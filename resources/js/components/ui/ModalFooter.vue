<script setup lang="ts">
import { inject, onBeforeUnmount, onMounted } from 'vue';
import { modalFooterKey } from './modalFooter';

/**
 * Renders its buttons in the surrounding Modal's sticky footer so Save stays
 * visible while the form scrolls. Outside a Modal it renders inline.
 */
const modal = inject(modalFooterKey, null);

onMounted(() => modal?.claim(true));
onBeforeUnmount(() => modal?.claim(false));
</script>

<template>
    <template v-if="modal">
        <Teleport v-if="modal.target.value" :to="modal.target.value">
            <slot />
        </Teleport>
    </template>
    <div v-else class="flex justify-end gap-3">
        <slot />
    </div>
</template>
