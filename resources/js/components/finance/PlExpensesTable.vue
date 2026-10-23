<script setup lang="ts">
// Legacy plExpenseTreeHtml/togglePlExpenseCat (L27438–27494): a category with its months and total; a click opens its articles;
// the totals of the months; dividends under the total, not in it (ADR-0052).
import { computed, ref } from 'vue';

export interface PlExpenseRow {
    code: string;
    label: string;
    monthly: number[];
    subs: { code: string; label: string; monthly: number[] }[];
}
const props = defineProps<{ rows: PlExpenseRow[]; dividends: number[] }>();
const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const fmt2 = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const sum = (a: number[]) => a.reduce((s, v) => s + v, 0);
const totals = computed(() => MONTHS.map((_, i) => props.rows.reduce((s, r) => s + (r.monthly[i] || 0), 0)));
const hasDividends = computed(() => props.dividends.some((v) => v > 0));
// legacy keeps what is open between redraws (also when the year changes)
const expanded = ref(new Set<string>());
function togglePlExpenseCat(code: string): void {
    const next = new Set(expanded.value);
    if (next.has(code)) next.delete(code);
    else next.add(code);
    expanded.value = next;
}
const mono = "text-align:right;font-family:'IBM Plex Mono',monospace";
</script>

<template>
    <div v-if="!props.rows.length" style="padding: 16px; color: var(--text3); text-align: center">Нет данных за этот год</div>
    <table v-else class="data-table" style="font-size: 11px; white-space: nowrap">
        <thead>
            <tr>
                <th>Категория / статья</th>
                <th v-for="m in MONTHS" :key="m" style="text-align: right">{{ m }}</th>
                <th style="text-align: right; color: var(--accent)">Итого за год</th>
            </tr>
        </thead>
        <tbody>
            <template v-for="r in props.rows" :key="r.code">
                <tr
                    :style="'background:var(--bg3);font-weight:600;' + (r.subs.length ? 'cursor:pointer' : '')"
                    @click="r.subs.length ? togglePlExpenseCat(r.code) : undefined"
                >
                    <td style="color: var(--accent)">
                        <template v-if="r.subs.length">{{ expanded.has(r.code) ? '▼ ' : '▶ ' }}</template><template v-else>&nbsp;&nbsp;&nbsp;</template>{{ r.label }}
                    </td>
                    <td v-for="(v, i) in r.monthly" :key="i" :style="mono">{{ fmt2(v) }}</td>
                    <td :style="mono + ';color:var(--accent)'">{{ fmt2(sum(r.monthly)) }}</td>
                </tr>
                <template v-if="r.subs.length && expanded.has(r.code)">
                    <tr v-for="s in r.subs" :key="s.code" style="color: var(--green)">
                        <td style="padding-left: 28px">{{ s.label }}</td>
                        <td v-for="(v, i) in s.monthly" :key="i" :style="mono">{{ fmt2(v) }}</td>
                        <td :style="mono">{{ fmt2(sum(s.monthly)) }}</td>
                    </tr>
                </template>
            </template>
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid var(--border)">
                <td style="font-weight: 700">Итого</td>
                <td v-for="(v, i) in totals" :key="i" :style="mono + ';font-weight:700'">{{ fmt2(v) }}</td>
                <td :style="mono + ';font-weight:700;color:var(--accent)'">{{ fmt2(sum(totals)) }}</td>
            </tr>
            <tr v-if="hasDividends" style="border-top: 2px dashed var(--border)">
                <td style="font-weight: 600; color: var(--amber)">
                    💰 Дивиденды <span style="font-weight: 400; font-size: 10px; color: var(--text3)">(не входит в расходы выше)</span>
                </td>
                <td v-for="(v, i) in props.dividends" :key="i" :style="mono + ';color:var(--amber)'">{{ fmt2(v) }}</td>
                <td :style="mono + ';font-weight:700;color:var(--amber)'">{{ fmt2(sum(props.dividends)) }}</td>
            </tr>
        </tfoot>
    </table>
</template>
