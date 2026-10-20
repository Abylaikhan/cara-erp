<script setup lang="ts">
// Legacy posBadge (c699dea): the ordinal number of the product in its order (1, 2, 3…) — production finds a line of an order
// of 30–40 products by it.
defineProps<{ n: number | null | undefined }>();
</script>

<template>
    <span
        v-if="n"
        title="Порядковый номер товара в заказе"
        style="
            display: inline-block;
            min-width: 26px;
            text-align: center;
            padding: 1px 6px;
            margin-right: 6px;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            font: 700 11px/1.5 'IBM Plex Mono', monospace;
            vertical-align: 1px;
        "
        >№{{ n }}</span
    >
</template>
