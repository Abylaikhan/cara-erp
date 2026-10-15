<script setup lang="ts">
// M01 «Дашборд» — legacy page-dashboard (L2226–2296), setDashPeriod/renderDashboard (L24546–24770). Same KPIs, charts and
// texts, built from the monthly sums the server counts (DashboardController). Periods are whole calendar months of the
// business time zone (legacy shifted them by a day through UTC). Salary accruals are not migrated yet: «ФОТ (месяц)» shows
// «—» and the accrued bars are empty until «Начисления ЗП» (M20) come.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

type Sums = Record<string, string>;
const props = defineProps<{
    today: string;
    revenue: Sums;
    ordersInWork: number;
    ordersQueue: number;
    stockCount: number;
    criticalCount: number;
    critical: { name: string; qty: string; unit: string | null }[];
    supplierPaid: Sums;
    supplierDebt: string;
    fotPaid: Sums;
    expenses: Record<string, Sums>;
    cashIn: Sums;
    cashOut: Sums;
}>();

const M_NAMES: Record<string, string> = {
    '01': 'Январь', '02': 'Февраль', '03': 'Март', '04': 'Апрель', '05': 'Май', '06': 'Июнь',
    '07': 'Июль', '08': 'Август', '09': 'Сентябрь', '10': 'Октябрь', '11': 'Ноябрь', '12': 'Декабрь',
};
const M_SHORT: Record<string, string> = {
    '01': 'Янв', '02': 'Фев', '03': 'Мар', '04': 'Апр', '05': 'Май', '06': 'Июн', '07': 'Июл', '08': 'Авг', '09': 'Сен', '10': 'Окт', '11': 'Ноя', '12': 'Дек',
};
const pad = (n: number) => String(n).padStart(2, '0');
const lastDay = (y: number, m: number) => new Date(Date.UTC(y, m, 0)).getUTCDate(); // m: 1..12
const ym = (y: number, m: number) => `${y}-${pad(m)}`; // m: 1..12
const shift = (month: string, by: number) => {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + by, 1));
    return ym(d.getUTCFullYear(), d.getUTCMonth() + 1);
};
const fmtK = (n: number) => (n >= 1000000 ? '₸' + (n / 1000000).toFixed(1) + 'М' : n >= 1000 ? '₸' + Math.round(n / 1000) + 'К' : '₸' + Math.round(n));
const pct = (a: number, b: number) => (b > 0 ? Math.round(((a - b) / b) * 100) : 0);
const num = (v: string | undefined) => Number(v ?? 0);

// ── Period (legacy setDashPeriod) ──
const [ty, tm] = props.today.split('-').map(Number);
const period = ref({ from: `${ym(ty, tm)}-01`, to: `${ym(ty, tm)}-${pad(lastDay(ty, tm))}` });
const monthSel = ref('');
const monthOptions = Array.from({ length: 18 }, (_, i) => shift(ym(ty, tm), -i));
function setDashPeriod(kind: 'month' | 'quarter' | 'year' | 'custom-month'): void {
    if (kind === 'month') period.value = { from: `${ym(ty, tm)}-01`, to: `${ym(ty, tm)}-${pad(lastDay(ty, tm))}` };
    else if (kind === 'quarter') {
        const q = Math.floor((tm - 1) / 3);
        period.value = { from: `${ym(ty, q * 3 + 1)}-01`, to: `${ym(ty, q * 3 + 3)}-${pad(lastDay(ty, q * 3 + 3))}` };
    } else if (kind === 'year') period.value = { from: `${ty}-01-01`, to: `${ty}-12-31` };
    else {
        const m = monthSel.value;
        if (!m) return;
        const [y, mo] = m.split('-').map(Number);
        period.value = { from: `${m}-01`, to: `${m}-${pad(lastDay(y, mo))}` };
    }
}
const curM = computed(() => period.value.from.slice(0, 7));
const prevM = computed(() => shift(curM.value, -1));
const inPeriod = (m: string) => m >= period.value.from.slice(0, 7) && m <= period.value.to.slice(0, 7);
const sumPeriod = (s: Sums) => Object.entries(s).reduce((t, [m, v]) => t + (inPeriod(m) ? Number(v) : 0), 0);
const periodLabel = computed(() =>
    period.value.from.slice(0, 7) === period.value.to.slice(0, 7) ? `${M_NAMES[curM.value.slice(5)]} ${curM.value.slice(0, 4)}` : `${period.value.from} — ${period.value.to}`,
);

// ── KPIs ──
const revCur = computed(() => sumPeriod(props.revenue));
const revPrev = computed(() => num(props.revenue[prevM.value]));
const suppPaid = computed(() => sumPeriod(props.supplierPaid));
const debt = computed(() => Number(props.supplierDebt));

// ── Charts ──
const label = (m: string) => `${M_SHORT[m.slice(5)]} ${m.slice(2, 4)}`;
const revMonths = computed(() => Object.keys(props.revenue).sort().slice(-8));
const maxRev = computed(() => Math.max(...revMonths.value.map((m) => num(props.revenue[m])), 1));
const fotMonths = computed(() => [...new Set(Object.keys(props.fotPaid))].sort().slice(-8));
const maxFot = computed(() => Math.max(...fotMonths.value.map((m) => num(props.fotPaid[m])), 1));
const cfMonths = computed(() => [...new Set([...Object.keys(props.cashIn), ...Object.keys(props.cashOut)])].sort().slice(-8));
const maxCf = computed(() => Math.max(...cfMonths.value.map((m) => Math.max(num(props.cashIn[m]), num(props.cashOut[m]))), 1));
const topExp = computed(() =>
    Object.entries(props.expenses[curM.value] ?? {})
        .map(([cat, v]) => [cat, Number(v)] as [string, number])
        .sort((a, b) => b[1] - a[1])
        .slice(0, 6),
);
const maxExp = computed(() => topExp.value[0]?.[1] || 1);
const bar = (h: number) => Math.max(4, Math.round(h));
</script>

<template>
    <ErpLayout>
        <div id="page-dashboard" class="page active">
            <!-- Фильтр периода -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px">
                <div style="display: flex; gap: 4px; flex-wrap: wrap">
                    <button id="dash-btn-month" class="btn btn-ghost btn-sm" style="font-size: 11px" @click="setDashPeriod('month')">📅 Месяц</button>
                    <button id="dash-btn-quarter" class="btn btn-ghost btn-sm" style="font-size: 11px" @click="setDashPeriod('quarter')">📅 Квартал</button>
                    <button id="dash-btn-year" class="btn btn-ghost btn-sm" style="font-size: 11px" @click="setDashPeriod('year')">📅 Год</button>
                    <select id="dash-month-sel" v-model="monthSel" style="font-size: 12px" @change="setDashPeriod('custom-month')">
                        <option value="">— выбрать месяц —</option>
                        <option v-for="m in monthOptions" :key="m" :value="m">{{ M_NAMES[m.slice(5)] }} {{ m.slice(0, 4) }}</option>
                    </select>
                </div>
                <div style="display: flex; align-items: center; gap: 8px">
                    <span id="dash-period-label" style="font-size: 13px; color: var(--text3); font-weight: 600">{{ periodLabel }}</span>
                    <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="router.reload()">🔄</button>
                </div>
            </div>
            <div id="dash-kpi-grid" class="kpi-grid">
                <div class="kpi">
                    <div class="kpi-label">Выручка (месяц)</div>
                    <div id="dash-revenue" class="kpi-value">{{ fmtK(revCur) }}</div>
                    <div id="dash-revenue-sub" class="kpi-change">
                        <template v-if="revPrev > 0">
                            <span v-if="pct(revCur, revPrev) > 0" style="color: var(--green)">↑ {{ pct(revCur, revPrev) }}%</span>
                            <span v-else style="color: var(--red)">↓ {{ Math.abs(pct(revCur, revPrev)) }}%</span> к прошлому
                        </template>
                    </div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Заказов в работе</div>
                    <div id="dash-orders" class="kpi-value">{{ props.ordersInWork }}</div>
                    <div id="dash-orders-sub" class="kpi-change">+ {{ props.ordersQueue }} в очереди</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Склад (позиций)</div>
                    <div id="dash-stock" class="kpi-value">{{ props.stockCount.toLocaleString('ru-RU') }}</div>
                    <div id="dash-stock-sub" class="kpi-change">
                        <span v-if="props.criticalCount > 0" style="color: var(--red)">⚠️ {{ props.criticalCount }} критично</span>
                        <template v-else>✅ норма</template>
                    </div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">ФОТ (месяц)</div>
                    <div id="dash-payroll" class="kpi-value">—</div>
                    <div id="dash-payroll-sub" class="kpi-change">с переносом начислений ЗП</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Оплачено поставщикам</div>
                    <div id="dash-suppliers" class="kpi-value">{{ fmtK(suppPaid) }}</div>
                    <div id="dash-suppliers-sub" class="kpi-change">{{ periodLabel }}</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Долг поставщикам</div>
                    <div id="dash-debt" class="kpi-value">{{ fmtK(Math.abs(debt)) }}</div>
                    <div id="dash-debt-sub" class="kpi-change">
                        <span v-if="debt > 0" style="color: var(--red)">долг</span>
                        <span v-else style="color: var(--green)">аванс</span>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-top: 16px">
                <!-- Выручка по месяцам -->
                <div class="card">
                    <div class="card-header"><span class="card-title">📈 Выручка по месяцам</span></div>
                    <div id="dash-monthly-chart" style="display: flex; align-items: flex-end; gap: 4px; height: 120px; padding: 8px 0">
                        <div v-for="m in revMonths" :key="m" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px" :title="fmtK(num(props.revenue[m]))">
                            <div style="font-size: 9px; color: var(--text3)">{{ fmtK(num(props.revenue[m])) }}</div>
                            <div
                                :style="{
                                    background: m === curM ? 'var(--accent)' : 'var(--bg3)',
                                    border: m === curM ? 'none' : '1px solid var(--border)',
                                    borderRadius: '3px 3px 0 0',
                                    width: '100%',
                                    height: bar((num(props.revenue[m]) / maxRev) * 110) + 'px',
                                }"
                            ></div>
                        </div>
                    </div>
                    <div id="dash-monthly-labels" style="display: flex; gap: 4px; font-size: 10px; color: var(--text3)">
                        <div v-for="m in revMonths" :key="m" style="flex: 1; text-align: center">{{ label(m) }}</div>
                    </div>
                </div>

                <!-- ФОТ по месяцам -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">👷 ФОТ по месяцам</span>
                        <span style="font-size: 11px; color: var(--text3)"
                            ><span style="color: var(--amber)">▮</span> Начислено &nbsp;<span style="color: var(--accent)">▮</span> Расход денег</span
                        >
                    </div>
                    <div id="dash-payroll-chart" style="display: flex; align-items: flex-end; gap: 6px; height: 140px; padding: 8px 0">
                        <div
                            v-for="m in fotMonths"
                            :key="m"
                            style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px"
                            :title="`Начислено: ${fmtK(0)} · Расход денег: ${fmtK(num(props.fotPaid[m]))}`"
                        >
                            <div style="display: flex; gap: 2px; align-items: flex-end; width: 100%">
                                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 1px; min-width: 0">
                                    <div style="font-size: 8px; color: var(--text3); white-space: nowrap"></div>
                                    <div style="background: var(--amber); opacity: 0.85; border-radius: 2px 2px 0 0; width: 100%; height: 4px"></div>
                                </div>
                                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 1px; min-width: 0">
                                    <div style="font-size: 8px; color: var(--text3); white-space: nowrap">{{ num(props.fotPaid[m]) ? fmtK(num(props.fotPaid[m])) : '' }}</div>
                                    <div
                                        :style="{ background: 'var(--accent)', opacity: 0.85, borderRadius: '2px 2px 0 0', width: '100%', height: bar((num(props.fotPaid[m]) / maxFot) * 100) + 'px' }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="dash-payroll-labels" style="display: flex; gap: 4px; font-size: 10px; color: var(--text3)">
                        <div v-for="m in fotMonths" :key="m" style="flex: 1; text-align: center">{{ label(m) }}</div>
                    </div>
                    <div style="font-size: 11px; color: var(--text3); margin-top: 8px; line-height: 1.5">
                        <b style="color: var(--amber)">Начислено</b> — по зарплатной ведомости. <b style="color: var(--accent)">Расход денег</b> — кассовые выплаты по статьям, которые идут в
                        ведомость: «Зарплата», «Оклады производства», «Сдельная производства», «Оклады офиса». Не входят: ЗП салонов, повара, прочее, рекламации и переводы на кассу ЗП.
                    </div>
                </div>
            </div>

            <!-- Приход/Расход -->
            <div class="card" style="margin-top: 16px">
                <div class="card-header">
                    <span class="card-title">💰 Приход и Расход по месяцам</span>
                    <span style="font-size: 11px; color: var(--text3)"> <span style="color: var(--green)">▮</span> Приход &nbsp; <span style="color: var(--red)">▮</span> Расход </span>
                </div>
                <div id="dash-cashflow-chart" style="display: flex; align-items: flex-end; gap: 6px; height: 130px; padding: 8px 0">
                    <div
                        v-for="m in cfMonths"
                        :key="m"
                        style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px"
                        :title="`▲${fmtK(num(props.cashIn[m]))} ▼${fmtK(num(props.cashOut[m]))}`"
                    >
                        <div style="font-size: 8px; color: var(--text3)">{{ fmtK(num(props.cashIn[m])) }}</div>
                        <div style="display: flex; gap: 1px; align-items: flex-end; width: 100%">
                            <div :style="{ flex: 1, background: 'var(--green)', opacity: 0.8, borderRadius: '2px 2px 0 0', height: bar((num(props.cashIn[m]) / maxCf) * 120) + 'px' }"></div>
                            <div :style="{ flex: 1, background: 'var(--red)', opacity: 0.7, borderRadius: '2px 2px 0 0', height: bar((num(props.cashOut[m]) / maxCf) * 120) + 'px' }"></div>
                        </div>
                    </div>
                </div>
                <div id="dash-cashflow-labels" style="display: flex; gap: 6px; font-size: 10px; color: var(--text3); margin-top: 4px">
                    <div v-for="m in cfMonths" :key="m" style="flex: 1; text-align: center">{{ label(m) }}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px">
                <!-- Топ категорий расходов -->
                <div class="card">
                    <div class="card-header"><span class="card-title">💸 Расходы по категориям (месяц)</span></div>
                    <div id="dash-expenses">
                        <div v-for="[cat, sum] in topExp" :key="cat" style="margin-bottom: 8px">
                            <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 2px">
                                <span>{{ cat }}</span><span style="font-weight: 600">{{ fmtK(sum) }}</span>
                            </div>
                            <div style="background: var(--bg3); border-radius: 3px; height: 5px">
                                <div :style="{ background: 'var(--red)', opacity: 0.7, borderRadius: '3px', height: '5px', width: Math.round((sum / maxExp) * 100) + '%' }"></div>
                            </div>
                        </div>
                        <div v-if="!topExp.length" style="color: var(--text3); font-size: 13px">Нет данных</div>
                    </div>
                </div>

                <!-- Критичные остатки склада -->
                <div class="card">
                    <div class="card-header"><span class="card-title">⚠️ Критичные остатки</span></div>
                    <div id="dash-critical">
                        <div
                            v-for="(m, i) in props.critical"
                            :key="i"
                            style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid var(--border); font-size: 12px"
                        >
                            <span style="color: var(--text2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px">{{ m.name }}</span>
                            <span style="color: var(--red); font-weight: 700; font-family: monospace">{{ Number(m.qty) }} {{ m.unit || 'шт' }}</span>
                        </div>
                        <div v-if="!props.critical.length" style="color: var(--green); font-size: 13px; padding: 8px 0">✅ Критичных позиций нет</div>
                    </div>
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
