<script setup lang="ts">
// Legacy modal (CSS .modal-overlay/.modal, showModal/closeModal L16572): a click on the dark backdrop closes it.
withDefaults(defineProps<{ id: string; open: boolean; maxWidth?: string; closeOnBackdrop?: boolean }>(), {
    maxWidth: undefined,
    closeOnBackdrop: true,
});
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <div :id="id" class="modal-overlay" :class="{ open }" @click.self="closeOnBackdrop && emit('close')">
        <div class="modal" :style="maxWidth ? { maxWidth } : undefined">
            <slot />
        </div>
    </div>
</template>
