<script setup lang="ts">
// M11 «Финансы», part 1 — legacy page-finance (L5447–5900): header with the order selection, the period filter
// (setFinancePeriod/fillFinanceMonths/initFinancePeriod, L25925–26035; the current year by default), the tabs (showFinanceTab
// L22610). «Дебиторка» (ArTab), «Закупки» (PurchasesTab), «ДДС» (CfsTab) and the statement of «P&L» (PlTab, ADR-0052) are
// migrated; margins, break-even, the trial balance and P&L by activity need technological cards and labour norms (ERP-061…063, D-12).
import { computed, ref } from 'vue';
import ArTab, { type ArRecord } from '@/components/finance/ArTab.vue';
import CfsTab, { type CfsTx } from '@/components/finance/CfsTab.vue';
import PlTab, { type PlData, type PlMonthly, type PlRevenue } from '@/components/finance/PlTab.vue';
import PurchasesTab, { type PurchasesData } from '@/components/finance/PurchasesTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

export interface FinanceOrder {
    num: string;
    channel: string;
    customer: string | null;
    seller: string | null;
    date: string | null;
    created: string | null;
    payment_due: string | null;
    payment: number;
    status: string;
    total: string;
}
const props = defineProps<{
    today: string;
    dataMonths: { lo: string | null; hi: string | null };
    channels: Record<string, string>;
    orders: FinanceOrder[];
    accounts: { id: number; name: string; icon: string | null; opening: string }[];
    categoryNames: Record<string, string>;
    txLabels: Record<string, string>;
    transactions?: CfsTx[];
    purchases?: PurchasesData;
    pl?: PlData;
    plMonthly?: PlMonthly;
    plRevChannel?: PlRevenue;
    plRevCategory?: PlRevenue;
}>();

type Tab = 'pl' | 'ar' | 'purchases' | 'margin' | 'bep' | 'cfs' | 'osv' | 'activity';
const tab = ref<Tab>('pl');
const LATER: Partial<Record<Tab, string>> = {
    margin: 'Маржинальность считается по технологическим картам и появится вместе с ними (ERP-028, решение D-12).',
    bep: 'Точка безубыточности строится по постоянным затратам и появится вместе с ними (ERP-063, решение D-11).',
    osv: 'Оборотно-сальдовая ведомость появится вместе с зарплатой и технологическими картами (ERP-061, решение D-11).',
    activity: 'P&L по видам деятельности появится вместе со сдельной оплатой и технологическими картами (ERP-063, решения D-11 и D-12).',
};
const excelLater = () => window.alert('Выгрузка P&L в Excel появится вместе с отчётом P&L (ERP-060, решения D-11 и D-12).');

// ── Period (legacy setFinancePeriod; dates are calendar dates, no time zone shift) ──
const MN = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const pad = (n: number) => String(n).padStart(2, '0');
const lastDay = (y: number, m: number) => new Date(Date.UTC(y, m, 0)).getUTCDate(); // m: 1..12
const [ty, tm] = props.today.split('-').map(Number);
type Mode = 'month' | 'quarter' | 'year' | 'all' | 'custom';
const period = ref<{ mode: Mode; from: string | null; to: string | null; ym: string }>({ mode: 'year', from: `${ty}-01-01`, to: `${ty}-12-31`, ym: '' });
const custom = ref({ from: `${ty}-01-01`, to: `${ty}-12-31` });
function setFinancePeriod(mode: Mode, ym = ''): void {
    let from: string | null = null,
        to: string | null = null;
    if (mode === 'month') {
        if (!ym) return;
        const [y, m] = ym.split('-').map(Number);
        from = `${ym}-01`;
        to = `${ym}-${pad(lastDay(y, m))}`;
    } else if (mode === 'quarter') {
        const q = Math.floor((tm - 1) / 3);
        from = `${ty}-${pad(q * 3 + 1)}-01`;
        to = `${ty}-${pad(q * 3 + 3)}-${pad(lastDay(ty, q * 3 + 3))}`;
    } else if (mode === 'year') {
        from = `${ty}-01-01`;
        to = `${ty}-12-31`;
    } else if (mode === 'custom') {
        if (!custom.value.from || !custom.value.to) return;
        from = custom.value.from;
        to = custom.value.to;
    }
    period.value = { mode, from, to, ym: mode === 'month' ? ym : '' };
    if (from && to) custom.value = { from, to };
}
// Legacy fillFinanceMonths: from the earliest month with data (not later than 2025-11) to the later of now and the last month
// with data (at most a year ahead), newest first.
const monthOptions = computed(() => {
    const v = (s: string) => +s.slice(0, 4) * 12 + (+s.slice(5, 7) - 1);
    const cur = ty * 12 + tm - 1;
    let lo = 2025 * 12 + 10,
        hi = cur;
    if (props.dataMonths.lo) lo = Math.min(lo, v(props.dataMonths.lo));
    if (props.dataMonths.hi) hi = Math.min(Math.max(hi, v(props.dataMonths.hi)), cur + 12);
    const list: { value: string; label: string }[] = [];
    for (let x = hi; x >= lo; x--) list.push({ value: `${Math.floor(x / 12)}-${pad((x % 12) + 1)}`, label: `${MN[x % 12]} ${Math.floor(x / 12)}` });
    return list;
});
const label = computed(() => {
    const p = period.value;
    return { month: p.ym ? MN[+p.ym.slice(5) - 1] + ' ' + p.ym.slice(0, 4) : '', quarter: 'Текущий квартал', year: 'Текущий год', all: 'Всё время', custom: 'Выбранный период' }[p.mode];
});
const dmy = (d: string) => d.split('-').reverse().join('.');
// Legacy periodLabel (subtitle of the details).
const periodLabel = computed(() => (period.value.from ? `${dmy(period.value.from)} — ${period.value.to ? dmy(period.value.to) : ''}` : 'Все время'));
const btnStyle = (m: Mode) => (period.value.mode === m ? { background: 'var(--accent)', color: '#fff' } : undefined);

// ── Orders of the report (legacy plFilteredOrders) and receivables (collectAR) ──
const plPeriod = ref<'all' | 'done' | 'progress'>('all');
const customerName = (o: FinanceOrder) => (o.channel !== 'office' && props.channels[o.channel]) || o.customer || '—';
const dayMs = 86400000;
const arRecords = computed<ArRecord[]>(() => {
    let list = props.orders;
    if (plPeriod.value === 'done') list = list.filter((o) => o.status === 'done');
    // «В работе + завершённые»: legacy took every order but the queue, cancelled ones too.
    if (plPeriod.value === 'progress') list = list.filter((o) => o.status === 'progress' || o.status === 'done');
    const { from, to } = period.value;
    if (from || to) list = list.filter((o) => {
        const d = o.date || o.created;
        return !d || ((!from || d >= from) && (!to || d <= to));
    });
    const today = Date.parse(props.today);
    return list
        .map((o) => {
            const total = Number(o.total);
            const paid = total * (Math.min(o.payment || 0, 100) / 100);
            const days = o.payment_due ? Math.floor((today - Date.parse(o.payment_due)) / dayMs) : null;
            const bucket = days === null ? 'Срок не задан' : days <= 0 ? 'Текущая' : days <= 15 ? '1–15 дней' : days <= 30 ? '16–30 дней' : days <= 60 ? '31–60 дней' : 'Более 60 дней';
            return { order: o, customer: customerName(o), total, paid, debt: total - paid, overdueDays: days, bucket };
        })
        .filter((r) => r.debt > 1);
});
</script>

<template>
    <ErpLayout>
        <div id="page-finance" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Финансы</div>
                    <div class="section-sub">P&amp;L · Дебиторская задолженность</div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center">
                    <select id="pl-period" v-model="plPeriod" style="width: auto; font-size: 13px">
                        <option value="all">Все заказы</option>
                        <option value="done">Только завершённые</option>
                        <option value="progress">В работе + завершённые</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="excelLater">📥 Excel</button>
                </div>
            </div>

            <!-- Фильтр периода — для всех финансовых отчётов -->
            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 12px; padding: 10px 14px; background: var(--bg3); border-radius: 8px; flex-wrap: wrap">
                <span style="font-size: 13px; color: var(--text2); font-weight: 600">📅 Период:</span>
                <select
                    id="fp-btn-month"
                    :value="period.ym"
                    style="font-size: 12px; padding: 4px 8px; width: auto"
                    title="Выбрать месяц"
                    @change="setFinancePeriod('month', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Месяц…</option>
                    <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <button id="fp-btn-quarter" class="btn btn-ghost btn-sm" :style="btnStyle('quarter')" @click="setFinancePeriod('quarter')">Квартал</button>
                <button id="fp-btn-year" class="btn btn-ghost btn-sm" :style="btnStyle('year')" @click="setFinancePeriod('year')">Год</button>
                <button id="fp-btn-all" class="btn btn-ghost btn-sm" :style="btnStyle('all')" @click="setFinancePeriod('all')">Всё время</button>
                <div style="display: flex; gap: 6px; align-items: center">
                    <input id="fp-date-from" v-model="custom.from" type="date" style="font-size: 12px; padding: 4px 8px" @change="setFinancePeriod('custom')" />
                    <span style="color: var(--text3)">—</span>
                    <input id="fp-date-to" v-model="custom.to" type="date" style="font-size: 12px; padding: 4px 8px" @change="setFinancePeriod('custom')" />
                </div>
                <span id="fp-label" style="font-size: 12px; color: var(--accent); font-weight: 600">{{ label }}</span>
            </div>

            <div id="finance-tabs" class="tabs">
                <div class="tab" :class="{ active: tab === 'pl' }" @click="tab = 'pl'">📊 P&amp;L</div>
                <div class="tab" :class="{ active: tab === 'ar' }" @click="tab = 'ar'">💳 Дебиторка <span id="ar-tab-count" class="kanban-count">{{ arRecords.length }}</span></div>
                <div class="tab" :class="{ active: tab === 'purchases' }" @click="tab = 'purchases'">🛒 Закупки <span id="pur-tab-count" class="kanban-count"></span></div>
                <div class="tab" :class="{ active: tab === 'margin' }" @click="tab = 'margin'">📈 Маржинальность</div>
                <div class="tab" :class="{ active: tab === 'bep' }" @click="tab = 'bep'">📉 Точка безубыточности</div>
                <div class="tab" :class="{ active: tab === 'cfs' }" @click="tab = 'cfs'">💵 ДДС</div>
                <div class="tab" :class="{ active: tab === 'osv' }" @click="tab = 'osv'">📋 ОСВ</div>
                <div class="tab" :class="{ active: tab === 'activity' }" @click="tab = 'activity'">📊 P&amp;L по видам</div>
            </div>

            <div id="finance-ar" :style="{ display: tab === 'ar' ? 'block' : 'none' }">
                <ArTab :records="arRecords" :today="props.today" />
            </div>
 <div id="finance-purchases" :style="{ display: tab === 'purchases' ? 'block' : 'none' }">
                <PurchasesTab :data="props.purchases" :today="props.today" :active="tab === 'purchases'" />
            </div>
            <div id="finance-pl" :style="{ display: tab === 'pl' ? 'block' : 'none' }">
                <PlTab
                    :data="props.pl"
                    :monthly="props.plMonthly"
                    :rev-channel="props.plRevChannel"
                    :rev-category="props.plRevCategory"
                    :from="period.from"
                    :to="period.to"
                    :active="tab === 'pl'"
                />
            </div>
            <div id="finance-cfs" :style="{ display: tab === 'cfs' ? 'block' : 'none' }">
                <CfsTab
                    :transactions="props.transactions"
                    :accounts="props.accounts"
                    :category-names="props.categoryNames"
                    :tx-labels="props.txLabels"
                    :from="period.from"
                    :to="period.to"
                    :period-label="periodLabel"
                    :active="tab === 'cfs'"
                />
            </div>
            <div v-for="(text, key) in LATER" :id="'finance-' + key" :key="key" :style="{ display: tab === key ? 'block' : 'none' }">
                <div class="card">
                    <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
