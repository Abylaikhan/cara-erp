<script setup lang="ts">
// Tab «Точка безубыточности» of «Финансы» — legacy finance-bep (L5761–5831): «📋 Постоянные затраты — план и факт» of a month
// (initFCMonthSelect/loadFixedCostsPeriod/getFCFact/renderFCPlanTable/fcAutoSave/saveAllFixedCosts/addFixedCostRow/addFCCategory),
// «📊 Структура постоянных затрат» and «📈 Разбивка по категориям» of the rows marked «✓ Проведено» (renderFixedCostsTable/
// renderFCBreakdown), the KPI and the chart of the break-even point for the period of the page from the P&L (renderBEPKpi/
// renderBEPChart, L21084–21530). The plan and the facts come from the server; a month is saved as a whole (ADR-0055).
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import type { PlData } from '@/components/finance/PlTab.vue';
import { fmtCurrency } from '@/lib/sales';
import { reloadLatest } from '@/lib/reload';

export interface FixedCostRow {
    id: number | null;
    name: string;
    category: string;
    plan: number;
    approved: boolean;
    pay_day: number | null;
}
export interface FixedCostsData {
    period: string;
    saved: boolean;
    version: string;
    rows: FixedCostRow[];
    categories: { code: string; name: string }[];
    names: Record<string, string>;
    facts: Record<string, number>;
}
const props = defineProps<{
    data: FixedCostsData | undefined;
    pl: PlData | undefined;
    from: string | null;
    to: string | null;
    mode: string;
    today: string;
    active: boolean;
}>();

// ── The month (legacy initFCMonthSelect: the current month and the eleven before it) ──
const months = computed(() => {
    const [y, m] = props.today.split('-').map(Number);
    return Array.from({ length: 12 }, (_, i) => {
        const d = new Date(Date.UTC(y, m - 1 - i, 1));
        return { value: d.toISOString().slice(0, 7), label: d.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric', timeZone: 'UTC' }) };
    });
});

// ── The plan of the month, edited here and saved a second after the last change (legacy fcAutoSave). The rows stay as they
// are after a save, in their order, till another month is chosen. ──
const rows = ref<FixedCostRow[]>([]);
const version = ref('');
const period = ref('');
const hint = ref('Факт берётся из журнала операций за выбранный месяц');
// While a month is loading the plan cannot be edited (legacy covered the page with «Загрузка постоянных затрат...»): rows added
// meanwhile would be replaced by the month that comes.
const loading = ref(false);
let loadSeq = 0;
function loadMonth(value?: string): void {
    const seq = ++loadSeq;
    loading.value = true;
    reloadLatest('fixedCosts', value ? { fc_period: value } : {}, { onFinish: () => seq === loadSeq && (loading.value = false) });
}
let timer: ReturnType<typeof setTimeout> | null = null;
let saving = false;
let after: (() => void) | null = null;
watch(
    () => props.data,
    (d) => {
        if (!d || d.period === period.value) return;
        rows.value = d.rows.map((r) => ({ ...r }));
        version.value = d.version;
        period.value = d.period;
    },
    { immediate: true },
);
watch(
    () => [props.active, props.from, props.to] as const,
    ([active, from, to]) => {
        if (!active) return;
        if (props.data === undefined) loadMonth();
        if (!props.pl || props.pl.from !== from || props.pl.to !== to) reloadLatest('pl', { pl_from: from ?? '', pl_to: to ?? '' });
    },
    { immediate: true },
);
// A change not saved yet is saved to its own month first (legacy saved it to the month chosen after it).
function loadFixedCostsPeriod(value: string): void {
    const load = () => loadMonth(value);
    if (timer || saving) save(load);
    else load();
}
function fcAutoSave(immediate = false): void {
    if (timer) clearTimeout(timer);
    timer = setTimeout(save, immediate ? 0 : 1000);
}
function save(then?: () => void): void {
    if (timer) clearTimeout(timer);
    timer = null;
    if (then) after = then;
    if (saving) {
        timer = setTimeout(save, 300);
        return;
    }
    saving = true;
    hint.value = '⏳ Сохранение...';
    const rowsNow = rows.value.map((r) => ({ name: r.name, category: r.category, plan: r.plan || 0, approved: r.approved, pay_day: r.pay_day || null }));
    router.put(
        `/finance/fixed-costs/${period.value}`,
        { version: version.value, rows: rowsNow },
        {
            preserveScroll: true,
            preserveState: true,
            async: true,
            only: ['fixedCosts'],
            onSuccess: (page) => {
                const t = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
                hint.value = `✅ Сохранено в ${t} · Факт берётся из журнала операций за выбранный месяц`;
                const d = page.props.fixedCosts as FixedCostsData | undefined;
                if (d && d.period === period.value) version.value = d.version;
            },
            onError: (errors) => (hint.value = '❌ Ошибка сохранения: ' + (Object.values(errors)[0] ?? '')),
            onFinish: () => {
                saving = false;
                if (!timer && after) {
                    const next = after;
                    after = null;
                    next();
                }
            },
        },
    );
}
function addFixedCostRow(): void {
    if (loading.value || !props.data) return;
    rows.value.push({ id: null, name: 'Новая статья', category: props.data?.categories[0]?.code ?? 'other', plan: 0, approved: false, pay_day: null });
    fcAutoSave();
    void nextTick(() => {
        const inputs = document.querySelectorAll<HTMLInputElement>('#fc-plan-tbody .fc-name-input');
        inputs[inputs.length - 1]?.focus();
    });
}
function removeRow(i: number): void {
    rows.value.splice(i, 1);
    fcAutoSave();
}
function addFCCategory(): void {
    window.alert('Категории статей расходов теперь управляются в общем справочнике — сейчас откроется раздел "Статьи расходов". Добавьте там новую статью с галочкой "Постоянная", и она появится здесь в списке.');
    router.visit('/expense_cats');
}

// ── Facts (legacy getFCFact) and totals ──
const fmt = (n: number) => (n > 0 ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const money = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const fact = (r: FixedCostRow) => props.data?.facts[r.category] ?? 0;
const diff = (r: FixedCostRow) => fact(r) - (r.plan || 0);
const totalPlan = computed(() => rows.value.reduce((s, r) => s + (r.plan || 0), 0));
const totalFact = computed(() => rows.value.reduce((s, r) => s + fact(r), 0));
const catName = (code: string) => props.data?.names[code] ?? (code || 'Прочее');

// ── «✓ Проведено» (legacy getApprovedFixedCosts) ──
const approved = computed(() => rows.value.filter((r) => r.approved && (r.plan || 0) > 0));
const approvedTotal = computed(() => approved.value.reduce((s, r) => s + (r.plan || 0), 0));
const breakdown = computed(() => {
    const by: Record<string, number> = {};
    approved.value.forEach((r) => (by[r.category] = (by[r.category] || 0) + (r.plan || 0)));
    return Object.entries(by)
        .sort((a, b) => b[1] - a[1])
        .map(([cat, sum]) => ({ label: catName(cat), sum, pct: approvedTotal.value > 0 ? Math.round((sum / approvedTotal.value) * 100) : 0 }));
});

// ── KPI and chart of the period of the page (the P&L) ──
const bep = computed(() => {
    const pl = props.pl;
    if (!pl) return null;
    const fc = pl.fixedTotal;
    const marginPct = pl.grossMarginPct;
    const bepRevenue = marginPct > 0 ? fc / marginPct : 0;
    const safety = pl.revenue - bepRevenue;
    return { fc, marginPct, bepRevenue, revenue: pl.revenue, safety, safetyPct: bepRevenue > 0 ? Math.round((safety / bepRevenue) * 100) : 0 };
});
const kpis = computed(() => {
    const b = bep.value;
    if (!b) return [];
    return [
        { label: 'Постоянные затраты (за период)', value: fmtCurrency(Math.round(b.fc)), color: 'var(--red)' },
        { label: 'Маржинальность', value: Math.round(b.marginPct * 100) + '%', color: b.marginPct >= 0.25 ? 'var(--green)' : 'var(--amber)' },
        { label: 'Точка безубыточности', value: fmtCurrency(Math.round(b.bepRevenue)), color: 'var(--amber)' },
        { label: 'Выручка (за период)', value: fmtCurrency(Math.round(b.revenue)), color: 'var(--accent)' },
        { label: 'Запас прочности', value: fmtCurrency(Math.round(b.safety)) + (b.safety >= 0 ? ' ✓' : ' ✗'), color: b.safety >= 0 ? 'var(--green)' : 'var(--red)' },
        {
            label: 'Запас прочности %',
            value: (b.safety >= 0 ? '+' : '') + b.safetyPct + '%',
            color: b.safetyPct >= 20 ? 'var(--green)' : b.safetyPct >= 0 ? 'var(--amber)' : 'var(--red)',
        },
    ];
});
const canvas = ref<HTMLCanvasElement | null>(null);
const showChart = computed(() => !!bep.value && !!bep.value.marginPct && !!bep.value.fc);
// Legacy computed the hint from a name it never defined, so the hint stayed empty; here it is shown (ADR-0002, item 127).
const chartHint = computed(() => {
    const b = bep.value;
    if (!showChart.value || !b) return '';
    const per = props.mode === 'month' ? 'месяц' : props.mode === 'quarter' ? 'квартал' : 'год';
    return `Нужно продать на ${fmtCurrency(Math.round(b.bepRevenue))} в ${per}, чтобы покрыть все постоянные затраты (${Math.round(b.marginPct * 100)}% средняя маржа)`;
});
const short = (v: number) => (v >= 1000000 ? (v / 1000000).toFixed(1) + 'М' : (v / 1000).toFixed(0) + 'К');
function renderBEPChart(): void {
    const el = canvas.value;
    const b = bep.value;
    if (!el || !b || !showChart.value || !el.getContext) return;
    const { fc, marginPct, bepRevenue } = b;
    const maxX = Math.max(bepRevenue * 2, fc * 3);
    const W = el.offsetWidth || 800;
    const H = 220;
    el.width = W;
    el.height = H;
    const ctx = el.getContext('2d');
    if (!ctx) return;
    ctx.clearRect(0, 0, W, H);
    const PAD = { l: 70, r: 30, t: 20, b: 40 };
    const chartW = W - PAD.l - PAD.r;
    const chartH = H - PAD.t - PAD.b;
    const maxY = bepRevenue * 1.4;
    const xc = (x: number) => PAD.l + (x / maxX) * chartW;
    const yc = (y: number) => PAD.t + chartH - (y / maxY) * chartH;
    // Legacy looked for a «dark» class the page never sets: the chart is always in the light colours.
    const gridColor = 'rgba(0,0,0,.07)';
    const textColor = '#64748b';
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, W, H);
    ctx.strokeStyle = gridColor;
    ctx.lineWidth = 1;
    for (let i = 0; i <= 5; i++) {
        const y = PAD.t + i * (chartH / 5);
        ctx.beginPath();
        ctx.moveTo(PAD.l, y);
        ctx.lineTo(W - PAD.r, y);
        ctx.stroke();
        ctx.fillStyle = textColor;
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(short(maxY * (1 - i / 5)), PAD.l - 6, y + 3);
    }
    const bepX = xc(bepRevenue);
    ctx.fillStyle = 'rgba(245,92,92,.07)';
    ctx.fillRect(PAD.l, PAD.t, bepX - PAD.l, chartH);
    ctx.fillStyle = 'rgba(61,214,140,.07)';
    ctx.fillRect(bepX, PAD.t, W - PAD.r - bepX, chartH);
    ctx.strokeStyle = '#f43f5e';
    ctx.lineWidth = 2;
    ctx.setLineDash([6, 4]);
    ctx.beginPath();
    ctx.moveTo(PAD.l, yc(fc));
    ctx.lineTo(W - PAD.r, yc(fc));
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = '#f43f5e';
    ctx.font = 'bold 11px sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('Постоянные затраты ' + fmtCurrency(Math.round(fc)), PAD.l + 6, yc(fc) - 5);
    ctx.strokeStyle = '#f5a623';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(xc(0), yc(fc));
    ctx.lineTo(xc(maxX), yc(fc + maxX * (1 - marginPct)));
    ctx.stroke();
    ctx.strokeStyle = '#4f8ef7';
    ctx.lineWidth = 2.5;
    ctx.beginPath();
    ctx.moveTo(xc(0), yc(0));
    ctx.lineTo(xc(maxX), yc(maxX));
    ctx.stroke();
    ctx.strokeStyle = 'rgba(167,139,250,.8)';
    ctx.lineWidth = 1.5;
    ctx.setLineDash([4, 3]);
    ctx.beginPath();
    ctx.moveTo(bepX, PAD.t);
    ctx.lineTo(bepX, PAD.t + chartH);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = '#a78bfa';
    ctx.beginPath();
    ctx.arc(bepX, yc(bepRevenue), 6, 0, Math.PI * 2);
    ctx.fill();
    ctx.font = 'bold 12px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillStyle = 'rgba(245,92,92,.7)';
    if (bepX > 60) ctx.fillText('Убыток', PAD.l + (bepX - PAD.l) / 2, PAD.t + 18);
    ctx.fillStyle = 'rgba(61,214,140,.9)';
    ctx.fillText('Прибыль', bepX + (W - PAD.r - bepX) / 2, PAD.t + 18);
    ctx.fillStyle = textColor;
    ctx.font = '10px sans-serif';
    ctx.textAlign = 'center';
    [0, 0.5, 1, 1.5, 2].forEach((f) => ctx.fillText(short(maxX * f), xc(maxX * f), H - PAD.b + 16));
    ctx.fillStyle = '#a78bfa';
    ctx.font = 'bold 11px sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('ТБ = ' + fmtCurrency(Math.round(bepRevenue)), Math.min(bepX + 8, W - PAD.r - 120), yc(bepRevenue) + 4);
    let lx = PAD.l;
    [
        { color: '#4f8ef7', label: 'Выручка' },
        { color: '#f5a623', label: 'Полные затраты' },
        { color: '#f43f5e', label: 'Постоянные затраты' },
        { color: '#a78bfa', label: 'Точка безубыточности' },
    ].forEach((lg) => {
        ctx.fillStyle = lg.color;
        ctx.fillRect(lx, H - 14, 14, 3);
        ctx.fillStyle = textColor;
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(lg.label, lx + 18, H - 10);
        lx += ctx.measureText(lg.label).width + 36;
    });
}
watch(
    () => [props.active, bep.value] as const,
    ([active]) => {
        if (active) void nextTick(renderBEPChart);
    },
    { immediate: true },
);
</script>

<template>
    <!-- Таблица 1: план/факт по месяцам -->
    <div class="card" style="margin-bottom: 16px">
        <div class="card-header">
            <span class="card-title">📋 Постоянные затраты — план и факт</span>
            <div style="display: flex; gap: 8px; align-items: center">
                <select id="fc-month-select" :value="props.data?.period ?? months[0].value" style="font-size: 13px" @change="loadFixedCostsPeriod(($event.target as HTMLSelectElement).value)">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <button class="btn btn-primary btn-sm" :disabled="loading || !props.data" @click="addFixedCostRow">+ Статья</button>
                <button class="btn btn-ghost btn-sm" @click="addFCCategory">+ Категория</button>
            </div>
        </div>
        <div id="fc-period-hint" style="font-size: 12px; color: var(--text3); margin-bottom: 10px">{{ loading || !props.data ? '⏳ Загрузка постоянных затрат...' : hint }}</div>
        <table id="fc-plan-table" class="data-table" :style="loading || !props.data ? 'pointer-events:none;opacity:.6' : ''">
            <thead>
                <tr>
                    <th style="min-width: 200px">Статья затрат</th>
                    <th>Категория</th>
                    <th style="text-align: right">План (₸)</th>
                    <th style="text-align: center">День оплаты</th>
                    <th style="text-align: right">Факт (₸)</th>
                    <th style="text-align: right">Откл. (₸)</th>
                    <th style="text-align: center">✓ Проведено</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="fc-plan-tbody">
                <tr v-for="(row, i) in rows" :key="i" :style="row.approved ? 'opacity:0.8' : ''">
                    <td>
                        <input
                            class="fc-name-input"
                            :value="row.name"
                            style="width: 100%; background: transparent; border: none; border-bottom: 1px solid var(--border); font-size: 13px; padding: 2px 4px"
                            @change="
                                row.name = ($event.target as HTMLInputElement).value;
                                fcAutoSave();
                            "
                        />
                    </td>
                    <td>
                        <select
                            :value="row.category"
                            style="font-size: 12px; width: 100%"
                            @change="
                                row.category = ($event.target as HTMLSelectElement).value;
                                fcAutoSave();
                            "
                        >
                            <template v-if="props.data?.categories.length">
                                <option v-for="c in props.data.categories" :key="c.code" :value="c.code">{{ c.name }}</option>
                            </template>
                            <option v-else :value="row.category">{{ row.category }}</option>
                        </select>
                    </td>
                    <td style="text-align: right">
                        <input
                            v-model.number="row.plan"
                            type="number"
                            style="width: 110px; text-align: right; font-family: monospace; font-size: 13px; background: transparent; border: none; border-bottom: 1px solid var(--border)"
                            @input="fcAutoSave()"
                        />
                    </td>
                    <td style="text-align: center">
                        <input
                            type="number"
                            min="1"
                            max="31"
                            :value="row.pay_day || ''"
                            placeholder="авто"
                            style="width: 60px; text-align: center; font-family: monospace; font-size: 13px; background: transparent; border: none; border-bottom: 1px solid var(--border)"
                            @change="
                                row.pay_day = parseInt(($event.target as HTMLInputElement).value) || null;
                                fcAutoSave();
                            "
                        />
                    </td>
                    <td style="text-align: right; font-family: monospace; color: var(--text2)">{{ fmt(fact(row)) }}</td>
                    <td
                        :style="`text-align:right;font-family:monospace;color:${diff(row) > 0 ? 'var(--red)' : diff(row) < 0 ? 'var(--green)' : 'var(--text3)'};font-weight:600`"
                    >
                        {{ diff(row) === 0 ? '—' : (diff(row) > 0 ? '+' : '') + fmt(Math.abs(diff(row))) }}
                    </td>
                    <td style="text-align: center">
                        <input
                            type="checkbox"
                            :checked="row.approved"
                            style="width: 16px; height: 16px; cursor: pointer"
                            @change="
                                row.approved = ($event.target as HTMLInputElement).checked;
                                fcAutoSave(true);
                            "
                        />
                    </td>
                    <td>
                        <button class="btn btn-ghost btn-sm" style="font-size: 11px; color: var(--red)" @click="removeRow(i)">✕</button>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td colspan="8" style="text-align: center; padding: 16px; color: var(--text3)">Нет статей. Нажмите «+ Статья»</td>
                </tr>
            </tbody>
            <tfoot id="fc-plan-tfoot">
                <tr style="background: var(--bg3); font-weight: 700">
                    <td colspan="2" style="padding: 8px 12px">Итого:</td>
                    <td style="text-align: right; font-family: monospace; color: var(--red)">{{ money(totalPlan) }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--text2)">{{ money(totalFact) }}</td>
                    <td :style="`text-align:right;font-family:monospace;color:${totalFact > totalPlan ? 'var(--red)' : 'var(--green)'}`">
                        {{ totalFact === totalPlan ? '₸0' : (totalFact > totalPlan ? '+' : '') + money(Math.abs(totalFact - totalPlan)) }}
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Таблица 2 + разбивка -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px">
        <div class="card">
            <div class="card-header">
                <span class="card-title">📊 Структура постоянных затрат</span>
                <span style="font-size: 11px; color: var(--text3)">☑ Проведённые</span>
            </div>
            <table id="fixed-costs-table" class="data-table">
                <thead>
                    <tr>
                        <th>Статья затрат</th>
                        <th>Категория</th>
                        <th style="text-align: right">В месяц</th>
                        <th style="text-align: right">В год</th>
                    </tr>
                </thead>
                <tbody id="fixed-costs-tbody">
                    <tr v-for="(fc, i) in approved" :key="i">
                        <td style="font-size: 13px">{{ fc.name }}</td>
                        <td><span style="font-size: 12px">{{ catName(fc.category) }}</span></td>
                        <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px">{{ fmtCurrency(Math.round(fc.plan)) }}</td>
                        <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--text3)">{{ fmtCurrency(Math.round(fc.plan * 12)) }}</td>
                        <td></td>
                    </tr>
                    <tr v-if="!approved.length">
                        <td colspan="5" style="text-align: center; padding: 16px; color: var(--text3)">Нет проведённых затрат. Отметьте строки галочкой в таблице выше.</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr style="background: var(--bg3); font-weight: 600">
                        <td colspan="2">Итого/мес</td>
                        <td id="fc-total-month" style="font-family: monospace; color: var(--red); text-align: right">{{ props.data ? fmtCurrency(Math.round(approvedTotal)) : '' }}</td>
                        <td id="fc-total-year" style="font-family: monospace; color: var(--red); text-align: right">{{ props.data ? fmtCurrency(Math.round(approvedTotal * 12)) : '' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">📈 Разбивка по категориям</span></div>
            <div id="fc-breakdown" style="display: flex; flex-direction: column; gap: 8px; padding: 4px 0">
                <div v-if="!approvedTotal" style="color: var(--text3); font-size: 13px">Нет проведённых затрат</div>
                <div v-for="b in breakdown" v-else :key="b.label" style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px">
                    <span style="font-size: 12px; min-width: 160px">{{ b.label }}</span>
                    <div class="progress" style="flex: 1"><div class="progress-fill" :style="`width:${b.pct}%;background:var(--accent)`"></div></div>
                    <span style="font-size: 12px; font-family: monospace; min-width: 80px; text-align: right">{{ fmtCurrency(Math.round(b.sum)) }}</span>
                    <span style="font-size: 11px; color: var(--text3); min-width: 35px">{{ b.pct }}%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI и график ТБУ -->
    <div id="bep-kpi" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px">
        <div v-for="k in kpis" :key="k.label" class="bep-kpi-card">
            <div class="bep-kpi-label">{{ k.label }}</div>
            <div class="bep-kpi-value" :style="`color:${k.color}`">{{ k.value }}</div>
        </div>
    </div>
    <div id="bep-chart-card" class="card">
        <div class="card-header">
            <span class="card-title">График точки безубыточности</span>
            <span style="font-size: 11px; color: var(--text3)">за период, выбранный в шапке страницы (📅 Месяц/Квартал/Год/Всё время)</span>
        </div>
        <canvas id="bep-canvas" ref="canvas" height="220" :style="`width:100%;display:${showChart ? 'block' : 'none'}`"></canvas>
        <div id="bep-chart-hint" style="font-size: 11.5px; color: var(--text3); margin-top: 8px; text-align: center">{{ chartHint }}</div>
    </div>
</template>
