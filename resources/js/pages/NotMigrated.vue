<script setup lang="ts">
import ErpLayout from '@/layouts/ErpLayout.vue';

defineProps<{ module: string; label: string }>();
</script>

<template>
    <ErpLayout>
        <div class="page active">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">{{ module }} · {{ label }}</div>
                </div>
                <div style="font-size: 13px; color: var(--text3); line-height: 1.6">
                    Раздел ещё не перенесён в новую систему. Перенос идёт по плану в трекере; данные этого раздела здесь пока не
                    показываются.
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
