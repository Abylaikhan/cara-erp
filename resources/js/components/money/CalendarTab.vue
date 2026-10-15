<script setup lang="ts">
// Tab «Платёжный календарь» of «Деньги» — legacy money-tab-calendar (L6421–6460), renderPayCalendar (L10252–10405). Day by
// day for 2 weeks…90 days: expected payments of the unpaid orders (the whole rest for the office and salons, half of it for a
// dealer, on the delivery date or today), planned expenses, operations already dated ahead, the running balance, gaps. Days
// are calendar days of the business time zone (legacy counted them through UTC and started a day early); amounts are in tenge
// and the balance includes the opening balances. Planned fixed costs come from the plan of fixed costs, which is not migrated
// and is empty in legacy (ERP-060, D-11). The chart is drawn without Chart.js (no new dependency) in the same layout.
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { catLabel, type Categories, type MoneyAccount } from '@/lib/money';

export interface CalendarData {
    today: string;
    balances: Record<string, string>;
    orders: { num: string; channel: string; customer: string | null; status: string; payment: number; delivery: string | null; date: string | null; total: string }[];
    future: { date: string; account: number; flow: 'income' | 'expense'; category: string; ctr: string; desc: string; amount: string }[];
}
const props = defineProps<{ data: CalendarData | undefined; accounts: MoneyAccount[]; categories: Categories; active: boolean }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.data === undefined) router.reload({ only: ['calendar'] });
    },
    { immediate: true },
);

const f = reactive({ days: '30', account: '', orders: true, fixed: true, actual: true });
const fmt = (n: number) => '₸' + Math.round(Math.abs(n)).toLocaleString('ru-RU');
const DAYS_RU = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const MONTHS_RU = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
const CHANNELS: Record<string, string> = {
    salon_armada: 'Салон Армада', salon_nazarbayeva: 'Салон Назарбаева', dealer_astana: 'Дилер Астана', dealer_aktau: 'Дилер Актау',
    dealer_atyrau: 'Дилер Атырау', dealer_shymkent: 'Дилер Шымкент', dealer_aktobe: 'Дилер Актобе', office: 'Офис',
};
const addDays = (iso: string, n: number) => new Date(Date.parse(iso) + n * 86400000).toISOString().slice(0, 10);

interface Ev { emoji: string; label: string; amount: number; urgent?: boolean }
const cal = computed(() => {
    const d = props.data;
    if (!d) return null;
    const days = parseInt(f.days);
    const today = d.today;
    const maxISO = addDays(today, days);
    const accs = f.account ? [f.account] : props.accounts.map((a) => String(a.id));
    let balance = accs.reduce((s, id) => s + Number(d.balances[id] ?? 0), 0);
    const events: Record<string, Ev[]> = {};
    const add = (iso: string, ev: Ev) => {
        if (iso < today || iso > maxISO) return;
        (events[iso] ??= []).push(ev);
    };
    if (f.orders)
        d.orders.forEach((o) => {
            const total = Number(o.total);
            if (total < 1000) return;
            const debt = Math.max(0, total - (total * (o.payment || 0)) / 100);
            if (debt < 1000) return;
            const isDealer = o.channel.startsWith('dealer_');
            const dispatch = o.delivery || o.date;
            const exp = dispatch && dispatch > today ? dispatch : today;
            if (exp > maxISO) return;
            add(exp, {
                emoji: isDealer ? '💼' : '🏬',
                label: `${isDealer ? '50% остатка' : 'Остаток'} по ${o.num} (${o.customer ?? ''}, ${CHANNELS[o.channel] || o.channel || 'канал не определён'})`,
                amount: isDealer ? debt * 0.5 : debt,
            });
        });
    if (f.fixed) {
        // Materials for the orders in the queue and in work (legacy looked for the status «production», which orders do not have).
        const inProd = d.orders.filter((o) => o.status === 'queue' || o.status === 'progress');
        if (inProd.length) {
            const perWeek = inProd.reduce((s, o) => s + Number(o.total) * 0.4, 0) / 2;
            for (let w = 1; w <= 2; w++) add(addDays(today, w * 7), { emoji: '🏭', label: 'Материалы (~40%)', amount: -perWeek });
        }
    }
    if (f.actual)
        d.future
            .filter((t) => t.date > today && t.date <= maxISO && (!f.account || String(t.account) === f.account))
            .forEach((t) => add(t.date, { emoji: t.flow === 'income' ? '✅' : '💸', label: `${catLabel(props.categories, t.category)} ${t.ctr || t.desc || ''}`, amount: (t.flow === 'income' ? 1 : -1) * Number(t.amount) }));

    let totalInc = 0, totalExp = 0, minBal = balance, gapDays = 0;
    const series: { label: string; inc: number; exp: number; bal: number }[] = [];
    const rows: { iso: string; d: number; label: string; day: string; weekend: boolean; friday: boolean; inc: number; exp: number; bal: number; urgent: boolean; events: Ev[] }[] = [];
    for (let i = 0; i < days; i++) {
        const iso = addDays(today, i);
        const dt = new Date(Date.parse(iso));
        const wd = dt.getUTCDay();
        const evs = events[iso] ?? [];
        const inc = evs.filter((e) => e.amount > 0).reduce((s, e) => s + e.amount, 0);
        const exp = evs.filter((e) => e.amount < 0).reduce((s, e) => s + Math.abs(e.amount), 0);
        balance += inc - exp;
        if (balance < 0) gapDays++;
        if (balance < minBal) minBal = balance;
        totalInc += inc;
        totalExp += exp;
        const label = `${dt.getUTCDate()} ${MONTHS_RU[dt.getUTCMonth()]}`;
        series.push({ label, inc: Math.round(inc), exp: Math.round(exp), bal: Math.round(balance) });
        if (!evs.length && i > 0 && wd !== 5) continue;
        rows.push({ iso, d: i, label, day: DAYS_RU[wd], weekend: wd === 0 || wd === 6, friday: wd === 5, inc, exp, bal: balance, urgent: evs.some((e) => e.urgent), events: evs });
    }
    return {
        rows,
        series,
        kpi: [
            { label: '💰 Текущий остаток', val: fmt(series[0]?.bal || 0), color: (series[0]?.bal ?? 0) >= 0 ? 'var(--green)' : 'var(--red)' },
            { label: '📥 Ожидаем', val: '+' + fmt(totalInc), color: 'var(--green)' },
            { label: '📤 Расходы', val: '-' + fmt(totalExp), color: 'var(--red)' },
            { label: '📉 Мин. остаток', val: (minBal < 0 ? '−' : '+') + fmt(Math.abs(minBal)), color: minBal < 0 ? 'var(--red)' : 'var(--amber)' },
            { label: '⚠️ Дней с разрывом', val: gapDays > 0 ? gapDays + ' дн.' : 'Нет', color: gapDays > 0 ? 'var(--red)' : 'var(--green)' },
        ],
    };
});
const balColor = (b: number) => (b < 0 ? 'var(--red)' : b < 500000 ? 'var(--amber)' : 'var(--green)');
const chip = (e: Ev) => ({
    display: 'inline-flex', alignItems: 'center', gap: '4px', margin: '2px 4px 2px 0', padding: '3px 8px', borderRadius: '5px', fontSize: '11px',
    background: e.amount > 0 ? 'rgba(61,214,140,.15)' : e.urgent ? 'rgba(249,70,70,.2)' : 'rgba(249,70,70,.1)',
    color: e.amount > 0 ? 'var(--green)' : e.urgent ? 'var(--red)' : '#f97316',
});

// ── Chart (bars of income and expense, line of the balance; Chart.js layout: legend on top, y ticks in thousands) ──
const W = 1000, H = 400, L = 70, R = 10, T = 34, B = 30;
const chart = computed(() => {
    const s = cal.value?.series ?? [];
    if (!s.length) return null;
    const vals = s.flatMap((p) => [p.inc, -p.exp, p.bal]);
    let max = Math.max(0, ...vals), min = Math.min(0, ...vals);
    if (max === min) max = min + 1;
    const step = niceStep((max - min) / 6);
    max = Math.ceil(max / step) * step;
    min = Math.floor(min / step) * step;
    const y = (v: number) => T + ((max - v) / (max - min)) * (H - T - B);
    const slot = (W - L - R) / s.length;
    const x = (i: number) => L + slot * i + slot / 2;
    const ticks: number[] = [];
    for (let v = min; v <= max + step / 2; v += step) ticks.push(v);
    const every = Math.ceil(s.length / 20);
    const line = s.map((p, i) => `${x(i)},${y(p.bal)}`).join(' ');
    return {
        bars: s.flatMap((p, i) => [
            { x: x(i) - slot * 0.4, y: y(Math.max(p.inc, 0)), h: Math.abs(y(p.inc) - y(0)), w: slot * 0.4, fill: 'rgba(61,214,140,.5)' },
            { x: x(i), y: y(0), h: Math.abs(y(-p.exp) - y(0)), w: slot * 0.4, fill: 'rgba(249,70,70,.5)' },
        ]),
        line,
        area: `${x(0)},${y(0)} ${line} ${x(s.length - 1)},${y(0)}`,
        points: s.map((p, i) => ({ x: x(i), y: y(p.bal) })),
        ticks: ticks.map((v) => ({ y: y(v), label: (v < 0 ? '-' : '') + fmt(Math.abs(v / 1000)) + 'К' })),
        labels: s.map((p, i) => ({ x: x(i), label: p.label })).filter((_, i) => i % every === 0),
    };
});
function niceStep(raw: number): number {
    const p = Math.pow(10, Math.floor(Math.log10(raw || 1)));
    const n = raw / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
}
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap">
        <select id="pc-days" v-model="f.days" style="font-size: 13px">
            <option value="14">2 недели</option>
            <option value="30">30 дней</option>
            <option value="60">60 дней</option>
            <option value="90">90 дней</option>
        </select>
        <select id="pc-account" v-model="f.account" style="font-size: 13px">
            <option value="">Все счета</option>
            <option v-for="a in props.accounts" :key="a.id" :value="String(a.id)">{{ a.icon }} {{ a.name }}</option>
        </select>
        <label style="display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer"> <input id="pc-show-orders" v-model="f.orders" type="checkbox" /> 📦 Ожидаемые оплаты </label>
        <label style="display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer"> <input id="pc-show-fixed" v-model="f.fixed" type="checkbox" /> 📋 Плановые расходы </label>
        <label style="display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer"> <input id="pc-show-actual" v-model="f.actual" type="checkbox" /> ✅ Факт </label>
    </div>
    <div id="pc-kpi" :style="{ display: cal ? 'flex' : 'none', gap: '12px', flexWrap: 'wrap', marginBottom: '16px' }">
        <div v-for="k in cal?.kpi ?? []" :key="k.label" class="pl-kpi" style="min-width: 130px">
            <div class="pl-kpi-label">{{ k.label }}</div>
            <div class="pl-kpi-value" :style="{ color: k.color, fontSize: '15px' }">{{ k.val }}</div>
        </div>
    </div>
    <div id="pc-chart-card" class="card" :style="{ marginBottom: '16px', padding: '16px', display: cal ? '' : 'none' }">
        <svg id="pc-chart" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" style="width: 100%; aspect-ratio: 5 / 2; display: block; font-family: inherit">
            <template v-if="chart">
                <g font-size="12" fill="var(--text2)">
                    <rect x="330" y="8" width="30" height="12" fill="rgba(61,214,140,.5)" /><text x="366" y="18">Поступления</text>
                    <rect x="470" y="8" width="30" height="12" fill="rgba(249,70,70,.5)" /><text x="506" y="18">Расходы</text>
                    <rect x="590" y="8" width="30" height="12" fill="rgba(79,142,247,.08)" stroke="#4f8ef7" stroke-width="2" /><text x="626" y="18">Остаток</text>
                </g>
                <g font-size="10" fill="var(--text3)">
                    <template v-for="t in chart.ticks" :key="t.y">
                        <line :x1="L" :x2="W - R" :y1="t.y" :y2="t.y" stroke="var(--border)" stroke-width="1" />
                        <text :x="L - 6" :y="t.y + 3" text-anchor="end">{{ t.label }}</text>
                    </template>
                    <text v-for="l in chart.labels" :key="l.x" :x="l.x" :y="H - 10" text-anchor="middle">{{ l.label }}</text>
                </g>
                <rect v-for="(b, i) in chart.bars" :key="i" :x="b.x" :y="b.y" :width="b.w" :height="b.h" :fill="b.fill" />
                <polygon :points="chart.area" fill="rgba(79,142,247,.08)" />
                <polyline :points="chart.line" fill="none" stroke="#4f8ef7" stroke-width="2" />
                <circle v-for="(p, i) in chart.points" :key="i" :cx="p.x" :cy="p.y" r="2" fill="#4f8ef7" />
            </template>
        </svg>
    </div>
    <div id="pc-table-card" class="card" :style="{ padding: 0, display: cal ? '' : 'none' }">
        <table id="pc-table" class="data-table">
            <thead id="pc-thead">
                <tr>
                    <th style="min-width: 90px">Дата</th>
                    <th>День</th>
                    <th style="text-align: right; color: var(--green)">📥 Приход</th>
                    <th style="text-align: right; color: var(--red)">📤 Расход</th>
                    <th style="text-align: right">💰 Остаток</th>
                    <th>Детали</th>
                </tr>
            </thead>
            <tbody id="pc-tbody">
                <tr v-for="r in cal?.rows ?? []" :key="r.iso" :style="{ background: r.d === 0 ? 'var(--bg3)' : r.urgent ? 'rgba(249,70,70,.04)' : r.weekend ? 'var(--bg2)' : '' }">
                    <td :style="{ fontSize: '12px', fontWeight: r.d === 0 || r.urgent ? 700 : 400, whiteSpace: 'nowrap', color: r.urgent ? 'var(--red)' : 'inherit' }">
                        {{ r.d === 0 ? '📍 Сегодня' : r.label }}{{ r.urgent ? ' ⚠️' : '' }}
                    </td>
                    <td :style="{ fontSize: '11px', color: r.friday ? 'var(--amber)' : r.weekend ? 'var(--red)' : 'var(--text3)' }">{{ r.day }}{{ r.friday ? ' 💰' : '' }}</td>
                    <td style="text-align: right; font-family: monospace; font-size: 12px; color: var(--green)">{{ r.inc > 0 ? '+' + fmt(r.inc) : '—' }}</td>
                    <td style="text-align: right; font-family: monospace; font-size: 12px; color: var(--red)">{{ r.exp > 0 ? '-' + fmt(r.exp) : '—' }}</td>
                    <td :style="{ textAlign: 'right', fontFamily: 'monospace', fontSize: '13px', fontWeight: 700, color: balColor(r.bal) }">
                        {{ r.bal < 0 ? '−' : '+' }}{{ fmt(Math.abs(r.bal)) }}{{ r.bal < 0 ? ' 🔴' : '' }}
                    </td>
                    <td>
                        <span v-for="(e, i) in r.events" :key="i" :style="chip(e)"
                            >{{ e.emoji }} {{ e.label.slice(0, 45) }} <b>{{ e.amount > 0 ? '+' + fmt(e.amount) : '-' + fmt(Math.abs(e.amount)) }}</b></span
                        >
                        <span v-if="!r.events.length" style="color: var(--text3); font-size: 11px">—</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
