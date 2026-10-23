<script setup lang="ts">
// Tab «📊 P&L» of «Финансы» — legacy finance-pl (L5495–5575), renderPL/buildPL (L26064–26330, L27527–27606). The KPI row, the
// statement «Отчёт о прибылях и убытках» with the source of each line, «Постоянные затраты / мес» for the period of the page, and
// the statement month by month of a year (renderMonthlyPL/exportMonthlyPLExcel); the server counts them (ADR-0052). Revenue by
// channel and category, expenses by article, the revenue tree and the cost estimate by category come next — their cards say so
// (ADR-0002, item 126).
import { router } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { fmtCurrency } from '@/lib/sales';
import { downloadXlsx } from '@/lib/xlsx';
import PlExpensesTable, { type PlExpenseRow } from '@/components/finance/PlExpensesTable.vue';
import PlRevenueTable from '@/components/finance/PlRevenueTable.vue';

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
export interface PlRevenue {
    year: string;
    years: string[];
    rows: { label: string; data: number[] }[];
    matched: number;
    other: number;
    samples?: { orderNum: string; customer: string }[];
}
export interface PlExpenses {
    year: string;
    years: string[];
    rows: PlExpenseRow[];
    dividends: number[];
}
export interface PlMonthly {
    year: string;
    years: string[];
    months: (PlData | null)[];
}
const props = defineProps<{
    data: PlData | undefined;
    monthly: PlMonthly | undefined;
    revChannel: PlRevenue | undefined;
    revCategory: PlRevenue | undefined;
    expenses: PlExpenses | undefined;
    from: string | null;
    to: string | null;
    active: boolean;
}>();

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
watch(
    () => props.active,
    (active) => {
        if (!active) return;
        const loaded = { plMonthly: props.monthly, plRevChannel: props.revChannel, plRevCategory: props.revCategory, plExpenses: props.expenses };
        const only = (Object.keys(loaded) as (keyof typeof loaded)[]).filter((k) => loaded[k] === undefined);
        if (only.length) router.reload({ only: [...only] });
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
// ── Помесячно (legacy renderMonthlyPL): the lines of the statement for each month; a month to come is «—» ──
const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const LINES: { key: keyof PlData; label: string; sign: number; bold?: boolean; sep?: boolean }[] = [
    { key: 'revenue', label: '📦 Выручка от продаж', sign: 1, bold: true },
    { key: 'materialCost', label: '  − Прямые материалы', sign: -1 },
    { key: 'wageCost', label: '  − Зарплата сдельная', sign: -1 },
    { key: 'oprCost', label: '  − ОПР', sign: -1 },
    { key: 'grossMargin', label: '= Маржинальная прибыль', sign: 1, bold: true, sep: true },
    { key: 'aupSalary', label: '  − Оклады АУП (с налогами)', sign: -1 },
    { key: 'prodTaxes', label: '  − Налоги на сдельщиков', sign: -1 },
    { key: 'commercialCost', label: '  − Коммерческие расходы', sign: -1 },
    { key: 'otherFixed', label: '  − Аренда, ком. услуги, прочее', sign: -1 },
    { key: 'ebit', label: '= EBIT (операционная прибыль)', sign: 1, bold: true, sep: true },
];
const monthlyRows = computed(() =>
    LINES.map((ln) => {
        const monthly = (props.monthly?.months ?? []).map((m) => (m === null ? null : ln.sign * (Number(m[ln.key]) || 0)));
        return { ...ln, monthly, total: monthly.reduce<number>((s, v) => s + (v || 0), 0) };
    }),
);
const fmt2 = (n: number | null) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
function setMonthlyYear(year: string): void {
    router.reload({ only: ['plMonthly'], data: { pl_year: year } });
}
// Legacy exportMonthlyPLExcel: a month to come is 0 in the file
function exportMonthlyPLExcel(): void {
    const year = props.monthly?.year;
    if (!year) return void window.alert('Нет данных для выгрузки.');
    const rows: (string | number)[][] = [
        ['Отчёт о прибылях и убытках помесячно — ' + year],
        [],
        ['Статья', ...MONTHS, 'Итого за год'],
        ...monthlyRows.value.map((r) => [r.label, ...r.monthly.map((v) => Math.round(v ?? 0)), Math.round(r.monthly.reduce<number>((s, v) => s + (v ?? 0), 0))]),
    ];
    void downloadXlsx('finance', `PromTech_PnL_pomesyachno_${year}.xlsx`, [{ name: String(year), rows, cols: [32, ...Array(12).fill(12), 14] }]);
}
// ── Выручка по направлениям / по категориям (legacy renderRevenueByChannel/ByCategory, exportRevenueTableToExcel) ──
const setRevenueYear = (prop: 'plRevChannel' | 'plRevCategory', year: string) =>
    router.reload({ only: [prop], data: { [prop === 'plRevChannel' ? 'pl_rch_year' : 'pl_rcat_year']: year } });
function exportRevenueTableToExcel(rev: PlRevenue | undefined, title: string, filenamePrefix: string): void {
    if (!rev || !rev.rows.length) return void window.alert('Нет данных для выгрузки.');
    const totals = MONTHS.map((_, i) => rev.rows.reduce((s, r) => s + (r.data[i] || 0), 0));
    const rows: (string | number)[][] = [
        [title + ' — ' + rev.year],
        [],
        ['Направление', ...MONTHS, 'Итого за год'],
        ...rev.rows.map((r) => [r.label, ...r.data.map((v) => Math.round(v)), Math.round(r.data.reduce((s, v) => s + v, 0))]),
        ['Итого', ...totals.map((v) => Math.round(v)), Math.round(totals.reduce((s, v) => s + v, 0))],
    ];
    void downloadXlsx('finance', `${filenamePrefix}_${rev.year}.xlsx`, [{ name: rev.year, rows, cols: [26, ...Array(12).fill(12), 14] }]);
}
// ── Расходы по категориям и статьям (legacy renderExpensesByMonth, exportExpensesByMonthToExcel) ──
const setExpensesYear = (year: string) => router.reload({ only: ['plExpenses'], data: { pl_exp_year: year } });
function exportExpensesByMonthToExcel(): void {
    const e = props.expenses;
    if (!e || !e.rows.length) return void window.alert('Нет данных для выгрузки.');
    const totals = Array(12).fill(0) as number[];
    const rows: (string | number)[][] = [['Расходы по категориям и статьям — ' + e.year], [], ['Категория / статья', ...MONTHS, 'Итого за год']];
    e.rows.forEach((r) => {
        r.monthly.forEach((v, i) => (totals[i] += v));
        rows.push([r.label, ...r.monthly.map((v) => Math.round(v)), Math.round(r.monthly.reduce((s, v) => s + v, 0))]);
        r.subs.forEach((s) => rows.push(['   ' + s.label, ...s.monthly.map((v) => Math.round(v)), Math.round(s.monthly.reduce((sm, v) => sm + v, 0))]));
    });
    rows.push(['Итого', ...totals.map((v) => Math.round(v)), Math.round(totals.reduce((s, v) => s + v, 0))]);
    if (e.dividends.some((v) => v > 0)) {
        rows.push([]);
        rows.push(['Дивиденды (не входит в расходы выше)', ...e.dividends.map((v) => Math.round(v)), Math.round(e.dividends.reduce((s, v) => s + v, 0))]);
    }
    void downloadXlsx('finance', `PromTech_Raskhody_${e.year}.xlsx`, [{ name: String(e.year), rows, cols: [32, ...Array(12).fill(12), 14] }]);
}
const cell = (r: Row) => (r.bold ? 'font-weight:700' : '') + (r.big ? ';font-size:14px' : '');
const LATER = 'Появится следующим шагом переноса P&L (ERP-060, ответ бухгалтера № 36).';
const later = () => window.alert(LATER);
const SUBREPORTS = [
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
        <div class="card" style="grid-column: 1/3">
            <div class="card-header">
                <span class="card-title">Отчёт о прибылях и убытках — помесячно</span>
                <div style="display: flex; gap: 6px; align-items: center">
                    <select
                        id="pl-monthly-year"
                        :value="props.monthly?.year"
                        style="font-size: 12px; width: auto"
                        @change="setMonthlyYear(($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="y in props.monthly?.years ?? []" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="exportMonthlyPLExcel">📥 Excel</button>
                </div>
            </div>
            <div id="pl-monthly" style="overflow-x: auto">
                <table v-if="props.monthly" class="data-table" style="font-size: 11px; white-space: nowrap">
                    <thead>
                        <tr>
                            <th>Статья</th>
                            <th v-for="m in MONTHS" :key="m" style="text-align: right">{{ m }}</th>
                            <th style="text-align: right; color: var(--accent)">Итого за год</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in monthlyRows" :key="r.key" :style="(r.bold ? 'font-weight:700;' : '') + (r.sep ? 'border-top:2px solid var(--border);' : '')">
                            <td>{{ r.label }}</td>
                            <td
                                v-for="(v, i) in r.monthly"
                                :key="i"
                                :style="`text-align:right;font-family:'IBM Plex Mono',monospace;color:${v !== null && v < 0 ? 'var(--red)' : r.bold ? 'var(--green)' : 'inherit'}`"
                            >
                                {{ fmt2(v) }}
                            </td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-weight: 700; color: var(--accent)">{{ fmt2(r.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card" style="grid-column: 1/3">
            <div class="card-header">
                <span class="card-title">Выручка по направлениям</span>
                <div style="display: flex; gap: 6px; align-items: center">
                    <select
                        id="pl-rev-channel-year"
                        :value="props.revChannel?.year"
                        style="font-size: 12px; width: auto"
                        @change="setRevenueYear('plRevChannel', ($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="y in props.revChannel?.years ?? []" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="exportRevenueTableToExcel(props.revChannel, 'Выручка по направлениям', 'PromTech_Vyruchka_napravleniya')">📥 Excel</button>
                </div>
            </div>
            <div id="pl-rev-by-channel" style="overflow-x: auto">
                <template v-if="props.revChannel">
                    <div v-if="props.revChannel.other > 0" style="font-size: 11px; color: var(--text3); margin-bottom: 6px">
                        Не удалось определить канал для {{ props.revChannel.other }} из {{ props.revChannel.matched + props.revChannel.other }} строк реализации. Примеры (заказ → клиент):
                        {{ (props.revChannel.samples ?? []).map((s) => `«${s.orderNum}» → ${s.customer}`).join('; ') }}
                    </div>
                    <PlRevenueTable :rows="props.revChannel.rows" />
                </template>
            </div>
        </div>
        <div class="card" style="grid-column: 1/3">
            <div class="card-header">
                <span class="card-title">Выручка по категориям продукции</span>
                <div style="display: flex; gap: 6px; align-items: center">
                    <select
                        id="pl-rev-category-year"
                        :value="props.revCategory?.year"
                        style="font-size: 12px; width: auto"
                        @change="setRevenueYear('plRevCategory', ($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="y in props.revCategory?.years ?? []" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="exportRevenueTableToExcel(props.revCategory, 'Выручка по категориям продукции', 'PromTech_Vyruchka_kategorii')">
                        📥 Excel
                    </button>
                </div>
            </div>
            <div id="pl-rev-by-category" style="overflow-x: auto">
                <template v-if="props.revCategory">
                    <div v-if="props.revCategory.other > 0" style="font-size: 11px; color: var(--text3); margin-bottom: 6px">
                        Не удалось определить категорию для {{ props.revCategory.other }} из {{ props.revCategory.matched + props.revCategory.other }} строк реализации — попали в
                        «Без категории» (возможно, название товара записано иначе, чем в справочнике).
                    </div>
                    <PlRevenueTable :rows="props.revCategory.rows" />
                </template>
            </div>
        </div>
        <div class="card" style="grid-column: 1/3">
            <div class="card-header">
                <span class="card-title">Расходы по категориям и статьям</span>
                <div style="display: flex; gap: 6px; align-items: center">
                    <select id="pl-exp-year" :value="props.expenses?.year" style="font-size: 12px; width: auto" @change="setExpensesYear(($event.target as HTMLSelectElement).value)">
                        <option v-for="y in props.expenses?.years ?? []" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="exportExpensesByMonthToExcel">📥 Excel</button>
                </div>
            </div>
            <div id="pl-exp-by-month" style="overflow-x: auto">
                <PlExpensesTable v-if="props.expenses" :rows="props.expenses.rows" :dividends="props.expenses.dividends" />
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
