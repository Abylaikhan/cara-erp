<script setup lang="ts">
// Tab «📈 Маржинальность» of «Финансы» — legacy finance-margin (L5617–5660): «Маржинальность по категориям (оценка — без
// техкарт)» — renderCategoryMargin/exportCategoryMarginExcel (L27132–27293), counted by the server (ADR-0054). The margin of
// each order line (renderMarginReport/openMarginDetail) needs tech cards: legacy has none and its labour norms never match a
// category, so every line there shows its revenue as margin, 100 % — it comes with the tech cards (ERP-028, D-12).
import { router } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { downloadXlsx } from '@/lib/xlsx';
import { reloadLatest } from '@/lib/reload';

export interface CatMargin {
    year: string;
    years: string[];
    rows: { label: string; revenue: number[]; materials: number[]; labor: number[]; margin: number[] }[];
}
const props = defineProps<{ data: CatMargin | undefined; active: boolean }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.data === undefined) router.reload({ only: ['catMargin'] });
    },
    { immediate: true },
);
const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const fmt2 = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const sum = (a: number[]) => a.reduce((s, v) => s + v, 0);
const pctClass = (p: number) => (p >= 30 ? 'margin-pct-good' : p >= 15 ? 'margin-pct-mid' : 'margin-pct-low');
const companyPct = computed(() => {
    const rows = props.data?.rows ?? [];
    const rev = rows.reduce((s, r) => s + sum(r.revenue), 0);
    return rev > 0 ? Math.round((rows.reduce((s, r) => s + sum(r.margin), 0) / rev) * 100) : 0;
});
const setYear = (year: string) => reloadLatest('catMargin', { cm_year: year });
function exportCategoryMarginExcel(): void {
    const d = props.data;
    if (!d || !d.rows.length) return void window.alert('Нет данных для выгрузки.');
    const header: string[] = ['Категория'];
    MONTHS.forEach((m) => header.push(m + ' маржа', m + ' %'));
    header.push('Маржа за год', 'Маржа % за год');
    const rows: (string | number)[][] = [
        ['Маржинальность по категориям (оценка) — ' + d.year],
        [],
        header,
        ...d.rows.map((r) => {
            const ym = sum(r.margin);
            const yr = sum(r.revenue);
            const cells: (string | number)[] = [];
            r.margin.forEach((v, m) => cells.push(Math.round(v), r.revenue[m] > 0 ? Math.round((v / r.revenue[m]) * 100) + '%' : ''));
            return [r.label, ...cells, Math.round(ym), (yr > 0 ? Math.round((ym / yr) * 100) : 0) + '%'];
        }),
    ];
    void downloadXlsx('finance', `PromTech_Marzha_kategorii_${d.year}.xlsx`, [{ name: String(d.year), rows, cols: [26, ...Array(24).fill(10), 14, 12] }]);
}
</script>

<template>
    <div class="card" style="margin-bottom: 20px">
        <div class="card-header">
            <span class="card-title">Маржинальность по категориям (оценка — без техкарт)</span>
            <div style="display: flex; gap: 6px; align-items: center">
                <select id="margin-category-year" :value="props.data?.year" style="font-size: 12px; width: auto" @change="setYear(($event.target as HTMLSelectElement).value)">
                    <option v-for="y in props.data?.years ?? []" :key="y" :value="y">{{ y }}</option>
                </select>
                <button class="btn btn-ghost btn-sm" @click="exportCategoryMarginExcel">📥 Excel</button>
            </div>
        </div>
        <div id="margin-category-table" style="overflow-x: auto">
            <div v-if="!props.data" style="padding: 16px; color: var(--text3); text-align: center">Загрузка данных для расчёта...</div>
            <template v-else>
                <div style="font-size: 11px; color: var(--text3); margin-bottom: 6px">
                    Оценочный расчёт (нет техкарт): материалы — по ЭСФ, труд+ОПР — из P&amp;L за месяц, распределены между категориями пропорционально их доле в выручке. Маржа по
                    компании за год: {{ companyPct }}%.
                </div>
                <table class="data-table" style="font-size: 11px; white-space: nowrap">
                    <thead>
                        <tr>
                            <th>Категория</th>
                            <th v-for="m in MONTHS" :key="m" style="text-align: right">{{ m }} маржа</th>
                            <th style="text-align: right; color: var(--accent)">Маржа за год</th>
                            <th style="text-align: right; color: var(--accent)">Маржа % за год</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in props.data.rows" :key="r.label">
                            <td>{{ r.label }}</td>
                            <td v-for="(v, m) in r.margin" :key="m" style="text-align: right">
                                <div :style="`font-family:'IBM Plex Mono',monospace;color:${v < 0 ? 'var(--red)' : 'inherit'}`">{{ fmt2(v) }}</div>
                                <div v-if="r.revenue[m] > 0" :class="pctClass(Math.round((v / r.revenue[m]) * 100))" style="font-size: 10px">{{ Math.round((v / r.revenue[m]) * 100) }}%</div>
                            </td>
                            <td :style="`text-align:right;font-family:'IBM Plex Mono',monospace;font-weight:700;color:${sum(r.margin) < 0 ? 'var(--red)' : 'var(--accent)'}`">
                                {{ fmt2(sum(r.margin)) }}
                            </td>
                            <td style="text-align: right; font-weight: 700">
                                <span :class="pctClass(sum(r.revenue) > 0 ? Math.round((sum(r.margin) / sum(r.revenue)) * 100) : 0)"
                                    >{{ sum(r.revenue) > 0 ? Math.round((sum(r.margin) / sum(r.revenue)) * 100) : 0 }}%</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </div>
    </div>
    <div class="card">
        <div style="font-size: 13px; color: var(--text3); line-height: 1.6">
            Маржинальность по позициям заказов появится вместе с технологическими картами (ERP-028, решение D-12): без техкарт и норм материалы и труд позиции не
            считаются.
        </div>
    </div>
</template>
