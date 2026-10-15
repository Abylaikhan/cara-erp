<script setup lang="ts">
// Tab «Дебиторка» of «Финансы» — legacy finance-ar (L5575–5614), collectAR/renderAR/renderARKpi/renderARTable/
// renderARAgingAnalysis/renderARByCustomer (L25263–25450). Orders of the status choice and the period (by the order's date,
// otherwise its creation date) with a debt over ₸1 by the paid percentage. Overdue days count from the business date (legacy
// used a fixed demo date). «💳 Оплата» posts money and waits for ERP-031…033.
import { computed, ref } from 'vue';
import { fmtCurrency } from '@/lib/sales';
import type { FinanceOrder } from '@/pages/Finance.vue';

const props = defineProps<{ records: ArRecord[] }>();
export interface ArRecord {
    order: FinanceOrder;
    customer: string;
    total: number;
    paid: number;
    debt: number;
    overdueDays: number | null;
    bucket: string;
}
const filter = ref<'all' | 'overdue' | 'current'>('all');
const all = computed(() => props.records);
const records = computed(() =>
    filter.value === 'overdue'
        ? all.value.filter((r) => (r.overdueDays ?? 0) > 0)
        : filter.value === 'current'
          ? all.value.filter((r) => r.overdueDays === null || r.overdueDays <= 0)
          : all.value,
);
const kpis = computed(() => {
    const totalDebt = all.value.reduce((s, r) => s + r.debt, 0);
    const overdue = all.value.filter((r) => (r.overdueDays ?? 0) > 0);
    const overdueDebt = overdue.reduce((s, r) => s + r.debt, 0);
    const avgDays = all.value.length ? Math.round(overdue.reduce((s, r) => s + (r.overdueDays || 0), 0) / Math.max(1, overdue.length)) : 0;
    return [
        { label: 'Всего дебиторов', value: all.value.length, color: 'var(--accent)' },
        { label: 'Общая задолженность', value: fmtCurrency(totalDebt), color: 'var(--red)' },
        { label: 'Текущая (в срок)', value: fmtCurrency(totalDebt - overdueDebt), color: 'var(--green)' },
        { label: 'Просроченная', value: fmtCurrency(overdueDebt), color: overdueDebt > 0 ? 'var(--red)' : 'var(--green)' },
        { label: 'Просроченных заказов', value: overdue.length, color: overdue.length > 0 ? 'var(--red)' : 'var(--green)' },
        { label: 'Средн. просрочка', value: avgDays ? avgDays + ' дн' : '—', color: avgDays > 30 ? 'var(--red)' : avgDays > 0 ? 'var(--amber)' : 'var(--green)' },
    ];
});
// Overdue first (most overdue first), then by debt.
const sorted = computed(() =>
    [...records.value].sort((a, b) => {
        const ao = a.overdueDays ?? -999,
            bo = b.overdueDays ?? -999;
        return ao !== bo ? bo - ao : b.debt - a.debt;
    }),
);
const BUCKETS: [string, string][] = [
    ['Текущая', '#3dd68c'],
    ['1–15 дней', '#f5a623'],
    ['16–30 дней', '#fb923c'],
    ['31–60 дней', '#f43f5e'],
    ['Более 60 дней', '#991b1b'],
    ['Срок не задан', '#94a3b8'],
];
const totalDebt = computed(() => all.value.reduce((s, r) => s + r.debt, 0) || 1);
const aging = computed(() =>
    BUCKETS.map(([label, color]) => ({ label, color, total: all.value.filter((r) => r.bucket === label).reduce((s, r) => s + r.debt, 0) })).filter((b) => b.total > 0),
);
const byCustomer = computed(() => {
    const map = new Map<string, { debt: number; orders: string[]; maxOverdue: number | null }>();
    all.value.forEach((r) => {
        const c = map.get(r.customer) ?? { debt: 0, orders: [], maxOverdue: null };
        c.debt += r.debt;
        c.orders.push(r.order.num);
        if (r.overdueDays !== null && (c.maxOverdue === null || r.overdueDays > c.maxOverdue)) c.maxOverdue = r.overdueDays;
        map.set(r.customer, c);
    });
    return [...map.entries()].sort((a, b) => b[1].debt - a[1].debt);
});
const payLater = () => window.alert('Приём оплаты по заказу появится вместе с проведением денег (ERP-031…033, решения D-03 и D-11).');
const excelLater = () => window.alert('Выгрузка в Excel появится вместе с остальными выгрузками (ERP-067).');
const fillClass = (p: number) => (p >= 75 ? 'green' : p >= 30 ? 'amber' : 'red');
</script>

<template>
    <div id="ar-kpi" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px">
        <div v-for="k in kpis" :key="k.label" class="pl-kpi">
            <div class="pl-kpi-label">{{ k.label }}</div>
            <div class="pl-kpi-value" :style="{ color: k.color }">{{ k.value }}</div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px">
        <div class="card-header">
            <span class="card-title">Реестр дебиторской задолженности</span>
            <div style="display: flex; gap: 8px">
                <select id="ar-filter" v-model="filter" style="font-size: 13px; width: auto">
                    <option value="all">Все</option>
                    <option value="overdue">Просроченные</option>
                    <option value="current">Текущие</option>
                </select>
                <button class="btn btn-ghost btn-sm" @click="excelLater">📥 Excel</button>
            </div>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Заказ</th>
                    <th>Клиент</th>
                    <th>Ответственный</th>
                    <th>Сумма заказа</th>
                    <th>Оплачено</th>
                    <th>Долг</th>
                    <th>Срок оплаты</th>
                    <th>Просрочка</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="ar-tbody">
                <tr v-if="!sorted.length">
                    <td colspan="10" style="text-align: center; color: var(--text3); padding: 24px">Нет задолженностей по выбранному фильтру</td>
                </tr>
                <tr v-for="r in sorted" :key="r.order.num" v-memo="[r]">
                    <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--accent)">{{ r.order.num }}</td>
                    <td style="font-size: 13px">{{ r.customer }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ r.order.seller }}</td>
                    <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px">{{ fmtCurrency(r.total) }}</td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 6px">
                            <div class="progress" style="width: 50px">
                                <div class="progress-fill" :class="fillClass(r.order.payment)" :style="{ width: r.order.payment + '%' }"></div>
                            </div>
                            <span style="font-size: 11px; font-family: 'IBM Plex Mono', monospace">{{ fmtCurrency(r.paid) }} ({{ r.order.payment }}%)</span>
                        </div>
                    </td>
                    <td style="font-family: 'IBM Plex Mono', monospace; font-weight: 600; color: var(--red)">{{ fmtCurrency(Math.round(r.debt)) }}</td>
                    <td style="font-size: 12px">{{ r.order.payment_due || '—' }}</td>
                    <td>
                        <span v-if="r.overdueDays === null" style="color: var(--text3); font-size: 12px">Срок не задан</span>
                        <span v-else-if="r.overdueDays <= 0" class="ar-overdue-badge" style="background: rgba(61, 214, 140, 0.15); color: var(--green)">через {{ Math.abs(r.overdueDays) }} дн</span>
                        <span v-else-if="r.overdueDays <= 15" class="ar-overdue-badge" style="background: rgba(245, 166, 35, 0.15); color: var(--amber)">+{{ r.overdueDays }} дн</span>
                        <span v-else class="ar-overdue-badge" style="background: rgba(245, 92, 92, 0.15); color: var(--red)">+{{ r.overdueDays }} дн ⚠</span>
                    </td>
                    <td>
                        <span v-if="(r.overdueDays ?? 0) > 30" class="badge badge-red">Критично</span>
                        <span v-else-if="(r.overdueDays ?? 0) > 0" class="badge badge-amber">Просрочено</span>
                        <span v-else class="badge badge-green">В срок</span>
                    </td>
                    <td>
                        <button class="btn btn-primary btn-sm" style="font-size: 11px; padding: 4px 10px; white-space: nowrap" @click="payLater">💳 Оплата</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px">
        <div class="card">
            <div class="card-header"><span class="card-title">Анализ по срокам (Aging)</span></div>
            <div id="ar-aging">
                <div v-for="b in aging" :key="b.label" class="ar-aging-row">
                    <div class="ar-aging-label">{{ b.label }}</div>
                    <div class="ar-aging-bar-wrap">
                        <div class="ar-aging-bar" :style="{ width: Math.round((b.total / totalDebt) * 100) + '%', background: b.color }"></div>
                    </div>
                    <div style="font-family: 'IBM Plex Mono', monospace; font-size: 12px; min-width: 90px; text-align: right">{{ fmtCurrency(Math.round(b.total)) }}</div>
                    <div style="font-size: 11px; color: var(--text3); min-width: 34px; text-align: right">{{ Math.round((b.total / totalDebt) * 100) }}%</div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">По клиентам (топ должники)</span></div>
            <div id="ar-by-customer">
                <div v-for="[name, d] in byCustomer" :key="name" class="ar-aging-row">
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 12.5px; font-weight: 500">
                            {{ name }}<span v-if="(d.maxOverdue ?? 0) > 0" style="font-size: 10.5px; color: var(--red); margin-left: 4px">+{{ d.maxOverdue }} дн</span>
                        </div>
                        <div style="font-size: 11px; color: var(--text3)">{{ d.orders.join(', ') }}</div>
                    </div>
                    <div style="text-align: right; flex-shrink: 0">
                        <div
                            :style="{
                                fontFamily: `'IBM Plex Mono', monospace`,
                                fontSize: '12px',
                                fontWeight: 600,
                                color: (d.maxOverdue ?? 0) > 30 ? '#f43f5e' : (d.maxOverdue ?? 0) > 0 ? '#f5a623' : '#3dd68c',
                            }"
                        >
                            {{ fmtCurrency(Math.round(d.debt)) }}
                        </div>
                        <div style="font-size: 11px; color: var(--text3)">{{ Math.round((d.debt / totalDebt) * 100) }}%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
