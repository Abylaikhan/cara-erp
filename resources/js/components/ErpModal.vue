<script setup lang="ts">
// Legacy modal (CSS .modal-overlay/.modal, showModal/closeModal L16572): a click on the dark backdrop closes it.
// `modalStyle` carries the inline style legacy puts on a particular .modal (e.g. max-height with scrolling).
import { computed } from 'vue';

const props = withDefaults(defineProps<{ id: string; open: boolean; maxWidth?: string; closeOnBackdrop?: boolean; modalStyle?: Record<string, string> }>(), {
    maxWidth: undefined,
    closeOnBackdrop: true,
    modalStyle: undefined,
});
const emit = defineEmits<{ close: [] }>();
const style = computed(() => (props.maxWidth || props.modalStyle ? { ...(props.maxWidth ? { maxWidth: props.maxWidth } : {}), ...props.modalStyle } : undefined));
</script>

<template>
    <div :id="id" class="modal-overlay" :class="{ open }" @click.self="closeOnBackdrop && emit('close')">
        <div class="modal" :style="style">
            <slot />
        </div>
    </div>
</template>
