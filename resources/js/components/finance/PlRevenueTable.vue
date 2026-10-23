<script setup lang="ts">
// Legacy plRevenueTableHtml (L26470): a row per direction or category, twelve months, the total of the year, the totals of the
// months; «—» for nothing. Used by «Выручка по направлениям» and «Выручка по категориям продукции» (ADR-0052).
import { computed } from 'vue';

const props = defineProps<{ rows: { label: string; data: number[] }[] }>();
const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const fmt2 = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const totals = computed(() => MONTHS.map((_, i) => props.rows.reduce((s, r) => s + (r.data[i] || 0), 0)));
const sum = (a: number[]) => a.reduce((s, v) => s + v, 0);
</script>

<template>
    <div v-if="!props.rows.length" style="padding: 16px; color: var(--text3); text-align: center">Нет данных за этот год</div>
    <table v-else class="data-table" style="font-size: 11px; white-space: nowrap">
        <thead>
            <tr>
                <th>Направление</th>
                <th v-for="m in MONTHS" :key="m" style="text-align: right">{{ m }}</th>
                <th style="text-align: right; color: var(--accent)">Итого за год</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="r in props.rows" :key="r.label">
                <td style="font-weight: 500">{{ r.label }}</td>
                <td v-for="(v, i) in r.data" :key="i" style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ fmt2(v) }}</td>
                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-weight: 700; color: var(--accent)">{{ fmt2(sum(r.data)) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid var(--border)">
                <td style="font-weight: 700">Итого</td>
                <td v-for="(v, i) in totals" :key="i" style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-weight: 700">{{ fmt2(v) }}</td>
                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-weight: 700; color: var(--accent)">{{ fmt2(sum(totals)) }}</td>
            </tr>
        </tfoot>
    </table>
</template>
