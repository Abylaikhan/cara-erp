<script setup lang="ts">
// Tab «📊 P&L» of «Финансы» — legacy finance-pl (L5495–5575), renderPL/buildPL (L26064–26330, L27527–27606). The KPI row, the
// statement «Отчёт о прибылях и убытках» with the source of each line, and «Постоянные затраты / мес», for the period of the page;
// the server counts them (ADR-0052). The monthly statement, revenue by channel and category, expenses by article, the revenue
// tree and the cost estimate by category come next — their cards say so (ADR-0002, item 126).
import { router } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { fmtCurrency } from '@/lib/sales';

export interface PlData {
    from: string | null;
    to: string | null;
    revenue: number;
    materialCost: number;
    esfMaterialEstimate: number;
    wageCost: number;
    oprCost: number;
    grossMargin: number;
    grossMarginPct: number;
    aupSalary: number;
    prodTaxes: number;
    commercialCost: number;
    otherFixed: number;
    ebit: number;
    prodToMaterials: number;
    prodUnclassified: number;
    advCommercialCost: number;
    advOtherExpense: number;
    advUndetermined: number;
    payrollLedgerSdelnaya: number;
    payrollLedgerAup: number;
}
const props = defineProps<{ data: PlData | undefined; from: string | null; to: string | null; active: boolean }>();

// The statement of the period of the page: loaded when the tab is shown and again when the period changes.
watch(
    () => [props.active, props.from, props.to] as const,
    ([active, from, to]) => {
        if (!active) return;
        if (props.data && props.data.from === from && props.data.to === to) return;
        router.reload({ only: ['pl'], data: { pl_from: from ?? '', pl_to: to ?? '' } });
    },
    { immediate: true },
);

const fmt = fmtCurrency;
const pl = computed(() => props.data);
const pct = (v: number) => (pl.value && pl.value.revenue > 0 ? Math.round((v / pl.value.revenue) * 100) + '%' : '—');
const kpis = computed(() => {
    const p = pl.value;
    if (!p) return [];
    return [
        { label: 'Выручка', value: fmt(p.revenue), color: 'var(--accent)' },
        { label: 'Прямые материалы', value: fmt(p.materialCost), color: 'var(--text2)' },
        { label: 'Зарплата произв.', value: fmt(p.wageCost), color: 'var(--text2)' },
        { label: 'Маржинальная прибыль', value: fmt(p.grossMargin), color: p.grossMargin >= 0 ? 'var(--green)' : 'var(--red)' },
        { label: 'Маржинальность', value: Math.round(p.grossMarginPct * 100) + '%', color: p.grossMarginPct >= 0.3 ? 'var(--green)' : p.grossMarginPct >= 0.15 ? 'var(--amber)' : 'var(--red)' },
        { label: 'EBIT (опер. прибыль)', value: fmt(p.ebit), color: p.ebit >= 0 ? 'var(--green)' : 'var(--red)' },
    ];
});
interface Row { label: string; value: number; bold?: boolean; sep?: boolean; big?: boolean; note?: string }
const rows = computed<Row[]>(() => {
    const p = pl.value;
    if (!p) return [];
    return [
        { label: '📦 Выручка от продаж', value: p.revenue, bold: true, note: 'из реализации (Продажи)' },
        {
            label: '  − Прямые материалы',
            value: -p.materialCost,
            note:
                (p.esfMaterialEstimate > 0 ? '🟡 оценка по ЭСФ (нет техкарт)' : '⚠️ план (номенклатура)') +
                (p.prodToMaterials > 0 ? ` + ${fmt(p.prodToMaterials)} из статей "Производственные" (Бук/лес/дуб/карагач/сосна/ясень, Стекло, Краска и т.п.)` : '') +
                (p.advUndetermined > 0 ? ` · ⚠️ ${fmt(p.advUndetermined)} из авансовых отчётов не определены (нет ни ЭСФ, ни статьи у поставщика) — не учтены никуда` : ''),
        },
        { label: '  − Зарплата сдельная', value: -p.wageCost, note: p.payrollLedgerSdelnaya > 0 ? '🟡 временно: колонка "Сдельная" из Зарплатной ведомости' : '✅ факт (выработка)' },
        {
            label: '  − ОПР (Производственные расходы)',
            value: -p.oprCost,
            note:
                (p.oprCost > 0 ? '🟡 временно: только по статьям "Производственные" (Станок, Полировка, Ремонт и т.п.)' : '—') +
                (p.prodUnclassified > 0 ? ` · ⚠️ ${fmt(p.prodUnclassified)} статей "Производственные" не входят ни в этот список, ни в список материалов — не учтены никуда` : ''),
        },
        { label: '= Маржинальная прибыль', value: p.grossMargin, bold: true, sep: true },
        { label: '  − Оклады АУП (с налогами)', value: -p.aupSalary, note: p.payrollLedgerAup > 0 ? '🟡 временно: Оклад+Премия+Отпускные+Займ нач.+Больничные из Зарплатной ведомости' : '✅ факт (payroll)' },
        { label: '  − Налоги на сдельщиков (СО+СН)', value: -p.prodTaxes, note: '✅ факт (payroll)' },
        {
            label: '  − Коммерческие расходы',
            value: -p.commercialCost,
            note: p.commercialCost > 0 ? 'статья "Коммерческие"' + (p.advCommercialCost > 0 ? ` (+ ${fmt(p.advCommercialCost)} из авансовых отчётов)` : '') : '—',
        },
        { label: '  − Аренда, ком. услуги, прочее', value: -p.otherFixed, note: 'из постоянных затрат' + (p.advOtherExpense > 0 ? ` + ${fmt(p.advOtherExpense)} из авансовых отчётов` : '') },
        { label: '= EBIT (операционная прибыль)', value: p.ebit, bold: true, sep: true, big: true },
    ];
});
const cell = (r: Row) => (r.bold ? 'font-weight:700' : '') + (r.big ? ';font-size:14px' : '');
const LATER = 'Появится следующим шагом переноса P&L (ERP-060, ответ бухгалтера № 36).';
const later = () => window.alert(LATER);
const SUBREPORTS = [
    { title: 'Отчёт о прибылях и убытках — помесячно', select: 'pl-monthly-year', body: 'pl-monthly' },
    { title: 'Выручка по направлениям', select: 'pl-rev-channel-year', body: 'pl-rev-by-channel' },
    { title: 'Выручка по категориям продукции', select: 'pl-rev-category-year', body: 'pl-rev-by-category' },
    { title: 'Расходы по категориям и статьям', select: 'pl-exp-year', body: 'pl-exp-by-month' },
    { title: 'Выручка: направление → категория → товар', select: 'pl-rev-tree-year', body: 'pl-rev-tree' },
    { title: 'Оценка себестоимости по категориям (без техкарт)', select: 'pl-matcost-year', body: 'pl-matcost' },
];
</script>

<template>
    <div id="pl-kpi-row" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px">
        <div v-for="k in kpis" :key="k.label" class="pl-kpi">
            <div class="pl-kpi-label">{{ k.label }}</div>
            <div class="pl-kpi-value" :style="{ color: k.color }">{{ k.value }}</div>
        </div>
    </div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px">
        <div class="card" style="grid-column: 1/3">
            <div class="card-header">
                <span class="card-title">Отчёт о прибылях и убытках</span>
                <div id="pl-data-quality" style="font-size: 12px; color: var(--text3)"></div>
            </div>
            <div id="pl-statement">
                <div v-if="!pl" style="padding: 12px; color: var(--text3); font-size: 13px">Загрузка...</div>
                <table v-else style="width: 100%; border-collapse: collapse; font-size: 13px">
                    <thead>
                        <tr>
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid var(--border)">Статья</th>
                            <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid var(--border)">Сумма (₸)</th>
                            <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid var(--border)">% выручки</th>
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid var(--border); font-size: 11px; color: var(--text3)">Источник данных</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.label" :style="r.sep ? 'border-top:2px solid var(--border)' : ''">
                            <td :style="'padding:6px 8px;' + cell(r)">{{ r.label }}</td>
                            <td :style="`text-align:right;font-family:'IBM Plex Mono',monospace;padding:6px 8px;${cell(r)};color:${r.value >= 0 ? 'var(--green)' : 'var(--red)'}`">
                                {{ (r.value >= 0 ? '' : '-') + fmt(Math.abs(r.value)) }}
                            </td>
                            <td style="text-align: right; font-size: 11px; color: var(--accent); padding: 6px 4px">{{ pct(Math.abs(r.value)) }}</td>
                            <td style="font-size: 11px; color: var(--text3); padding: 6px 4px">{{ r.note || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div v-for="s in SUBREPORTS" :key="s.body" class="card" style="grid-column: 1/3">
            <div class="card-header">
                <span class="card-title">{{ s.title }}</span>
                <div style="display: flex; gap: 6px; align-items: center">
                    <select :id="s.select" style="font-size: 12px; width: auto"></select>
                    <button class="btn btn-ghost btn-sm" @click="later">📥 Excel</button>
                </div>
            </div>
            <div :id="s.body" style="overflow-x: auto">
                <div style="padding: 12px; color: var(--text3); font-size: 13px">{{ LATER }}</div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Постоянные затраты / мес</span>
                <span style="font-size: 12px; color: var(--text3)">из справочника</span>
            </div>
            <div id="pl-fc-summary">
                <div v-if="pl" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 8px">
                    <div class="card" style="padding: 12px">
                        <div style="font-size: 11px; color: var(--text3)">Оклады АУП (с налогами)</div>
                        <div style="font-weight: 600; margin-top: 4px">{{ fmt(pl.aupSalary) }}</div>
                    </div>
                    <div class="card" style="padding: 12px">
                        <div style="font-size: 11px; color: var(--text3)">Налоги на сдельщиков</div>
                        <div style="font-weight: 600; margin-top: 4px">{{ fmt(pl.prodTaxes) }}</div>
                    </div>
                    <div class="card" style="padding: 12px">
                        <div style="font-size: 11px; color: var(--text3)">Аренда, коммунальные, прочее</div>
                        <div style="font-weight: 600; margin-top: 4px">{{ fmt(pl.otherFixed) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
