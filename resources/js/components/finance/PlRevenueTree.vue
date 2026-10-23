<script setup lang="ts">
// Legacy plRevenueTreeHtml/togglePlRevTree (L26699–26740): direction → category → product, a click opens a level; the totals
// of the months under the table (ADR-0052).
import { computed, ref } from 'vue';

export interface PlTreeRow {
    key: string;
    label: string;
    monthly: number[];
    cats?: PlTreeRow[];
    items?: PlTreeRow[];
}
const props = defineProps<{ rows: PlTreeRow[] }>();
const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const COLORS = ['var(--accent)', 'var(--green)', 'var(--text2)'];
const fmt2 = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const sum = (a: number[]) => a.reduce((s, v) => s + v, 0);
const totals = computed(() => MONTHS.map((_, i) => props.rows.reduce((s, r) => s + (r.monthly[i] || 0), 0)));
const expanded = ref(new Set<string>());
function togglePlRevTree(key: string): void {
    const next = new Set(expanded.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    expanded.value = next;
}
// the rows as legacy draws them: a level under an open row
const flat = computed(() => {
    const out: { row: PlTreeRow; level: number; children: PlTreeRow[] }[] = [];
    const walk = (r: PlTreeRow, level: number) => {
        const children = (level === 0 ? r.cats : r.items) ?? [];
        out.push({ row: r, level, children });
        if (children.length && expanded.value.has(r.key)) children.forEach((c) => walk(c, level + 1));
    };
    props.rows.forEach((r) => walk(r, 0));
    return out;
});
const mono = "text-align:right;font-family:'IBM Plex Mono',monospace";
</script>

<template>
    <div v-if="!props.rows.length" style="padding: 16px; color: var(--text3); text-align: center">Нет данных за этот год</div>
    <table v-else class="data-table" style="font-size: 11px; white-space: nowrap">
        <thead>
            <tr>
                <th>Направление / категория / товар</th>
                <th v-for="m in MONTHS" :key="m" style="text-align: right">{{ m }}</th>
                <th style="text-align: right; color: var(--accent)">Итого за год</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="f in flat"
                :key="f.row.key"
                :style="(f.level === 0 ? 'background:var(--bg3);font-weight:600' : '') + (f.children.length ? ';cursor:pointer' : '')"
                @click="f.children.length ? togglePlRevTree(f.row.key) : undefined"
            >
                <td :style="`padding-left:${f.level * 24}px;color:${COLORS[f.level]}`">
                    <template v-if="f.children.length">{{ expanded.has(f.row.key) ? '▼ ' : '▶ ' }}</template
                    ><template v-else-if="f.level > 0">&nbsp;&nbsp;&nbsp;</template>{{ f.row.label }}
                </td>
                <td v-for="(v, i) in f.row.monthly" :key="i" :style="mono">{{ fmt2(v) }}</td>
                <td :style="mono + ';color:' + COLORS[f.level]">{{ fmt2(sum(f.row.monthly)) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid var(--border)">
                <td style="font-weight: 700">Итого</td>
                <td v-for="(v, i) in totals" :key="i" :style="mono + ';font-weight:700'">{{ fmt2(v) }}</td>
                <td :style="mono + ';font-weight:700;color:var(--accent)'">{{ fmt2(sum(totals)) }}</td>
            </tr>
        </tfoot>
    </table>
</template>
