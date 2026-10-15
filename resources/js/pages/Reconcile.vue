<script setup lang="ts">
// M16 «Сверка с офисом», part 1 — legacy page-reconcile (L7138–7199), setReconcilePeriod/getReconcileDateFilter/
// renderReconcile (L15728–15962). The orders of the account's channel by their date (all, the month, a period): shipped orders
// and their main debt, orders in work and the 50 % prepayment debt, the money handed to the office; KPIs for a dealer or a
// salon. The month is the calendar month of the business time zone.
import { computed, reactive } from 'vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

interface Row {
    num: string;
    customer: string | null;
    status: string;
    date: string | null;
    total: string;
    paid: string;
}
const props = defineProps<{
    name: string;
    dealer: boolean;
    cash: boolean;
    today: string;
    orders: Row[];
    transfers: { date: string; desc: string; amount: string }[];
}>();

const fmt = (n: number) => '₸' + Math.round(Math.abs(n)).toLocaleString('ru-RU');
const dmy = (d: string | null) => (d ? d.split('-').reverse().join('.') : '');
const [ty, tm] = props.today.split('-').map(Number);
const pad = (n: number) => String(n).padStart(2, '0');
const lastDay = new Date(Date.UTC(ty, tm, 0)).getUTCDate();
const p = reactive({ mode: 'all' as 'all' | 'month' | 'custom', from: '2026-07-01', to: '2026-07-31' });
function setReconcilePeriod(mode: typeof p.mode): void {
    p.mode = mode;
    if (mode === 'month') Object.assign(p, { from: `${ty}-${pad(tm)}-01`, to: `${ty}-${pad(tm)}-${pad(lastDay)}` });
}

const total = (o: Row) => Number(o.total);
const paid = (o: Row) => Number(o.paid);
const r = computed(() => {
    const mine = props.orders.filter((o) => p.mode === 'all' || ((!p.from || (o.date ?? '') >= p.from) && (!p.to || (o.date ?? '') <= p.to)));
    const shipped = mine.filter((o) => o.status === 'done');
    const pending = mine.filter((o) => o.status !== 'done' && o.status !== 'cancelled');
    const monthStart = `${ty}-${pad(tm)}-01`;
    // Payments of every order of the channel (legacy dealerPayments), whatever the period.
    const totalPaidByOrders = props.orders.reduce((s, o) => s + paid(o), 0);
    const paidToOffice = props.transfers.reduce((s, t) => s + Number(t.amount), 0);
    const shippedTotal = shipped.reduce((s, o) => s + total(o), 0);
    const closingDebt = Math.max(0, shippedTotal - totalPaidByOrders);
    const openingDebt = shipped.filter((o) => (o.date ?? '') < monthStart).reduce((s, o) => s + Math.max(0, total(o) - paid(o)), 0);
    const shippedCurAmt = shipped.filter((o) => (o.date ?? '') >= monthStart).reduce((s, o) => s + total(o), 0);
    const prepayDebt = pending.reduce((s, o) => s + Math.max(0, total(o) * 0.5 - paid(o)), 0);
    const paidByClients = props.dealer ? totalPaidByOrders : mine.reduce((s, o) => s + paid(o), 0);
    const kpis = props.dealer
        ? [
              { label: 'Остаток долга на начало', value: openingDebt, color: 'var(--amber)' },
              { label: 'Отправлено за период', value: shippedCurAmt, color: 'var(--accent)' },
              { label: 'Передано в офис за период', value: paidToOffice, color: 'var(--green)' },
              { label: 'Остаток основного долга', value: closingDebt, color: closingDebt > 0 ? 'var(--red)' : 'var(--green)' },
              { label: 'Долг по предоплате 50%', value: prepayDebt, color: prepayDebt > 0 ? 'var(--amber)' : 'var(--green)', sub: 'неотправленные заказы' },
              { label: 'Итого долг (осн. + предоплата)', value: closingDebt + prepayDebt, color: 'var(--red)' },
          ]
        : [
              { label: 'Остаток долга на начало', value: openingDebt, color: 'var(--amber)' },
              { label: 'Отправлено за период', value: shippedCurAmt, color: 'var(--accent)' },
              { label: 'Оплачено клиентами', value: paidByClients, color: 'var(--green)' },
              { label: 'Передано в офис', value: paidToOffice, color: 'var(--green)' },
              { label: 'Остаток основного долга', value: closingDebt, color: closingDebt > 0 ? 'var(--red)' : 'var(--green)' },
              { label: 'Долг по предоплате 50%', value: prepayDebt, color: prepayDebt > 0 ? 'var(--amber)' : 'var(--green)', sub: 'неотправленные заказы' },
          ];
    const shippedRows = [...shipped]
        .sort((a, b) => (b.date ?? '').localeCompare(a.date ?? ''))
        .map((o) => {
            const t = total(o);
            const pd = props.dealer ? paid(o) : paidToOffice * (t / (shippedTotal || 1));
            return { o, total: t, paid: pd, debt: Math.max(0, t - pd) };
        });
    return { kpis, shippedRows, pending, prepayDebt };
});
const transfers = computed(() => [...props.transfers].sort((a, b) => b.date.localeCompare(a.date)));
</script>

<template>
    <ErpLayout>
        <div id="page-reconcile" class="page active">
            <div class="section-header">
                <div>
                    <div id="reconcile-title" class="section-title">Сверка с офисом — {{ props.name }}</div>
                    <div id="reconcile-sub" class="section-sub">Взаиморасчёты · {{ dmy(props.today) }}</div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap">
                    <div class="tabs" style="margin: 0; gap: 4px">
                        <div class="tab" :class="{ active: p.mode === 'all' }" @click="setReconcilePeriod('all')">Все заказы</div>
                        <div class="tab" :class="{ active: p.mode === 'month' }" @click="setReconcilePeriod('month')">Месяц</div>
                        <div class="tab" :class="{ active: p.mode === 'custom' }" @click="setReconcilePeriod('custom')">Период</div>
                    </div>
                    <div id="reconcile-custom-dates" :style="{ display: p.mode === 'custom' ? 'flex' : 'none', gap: '6px', alignItems: 'center' }">
                        <input id="reconcile-date-from" v-model="p.from" type="date" style="font-size: 12px" />
                        <span style="color: var(--text3)">—</span>
                        <input id="reconcile-date-to" v-model="p.to" type="date" style="font-size: 12px" />
                    </div>
                </div>
            </div>

            <div id="reconcile-balance-grid" :style="{ display: 'grid', gridTemplateColumns: props.dealer ? 'repeat(2,1fr)' : 'repeat(3,1fr)', gap: '12px', marginBottom: '20px' }">
                <div v-for="k in r.kpis" :key="k.label" class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 2px">{{ k.label }}</div>
                    <div v-if="k.sub" style="font-size: 10px; color: var(--text3); margin-bottom: 4px">{{ k.sub }}</div>
                    <div v-else style="margin-bottom: 4px"></div>
                    <div :style="{ fontSize: '20px', fontWeight: 700, color: k.color }">{{ fmt(k.value) }}</div>
                </div>
            </div>

            <div class="card" style="padding: 0; margin-bottom: 16px">
                <div style="padding: 12px 16px; font-weight: 600; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between">
                    <span>📦 Сверка по отправленным заказам — основной долг</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Заказ</th>
                            <th>Дата отгрузки</th>
                            <th>Клиент</th>
                            <th style="text-align: right">Сумма</th>
                            <th style="text-align: right; color: var(--green)">Передано в офис</th>
                            <th style="text-align: right; color: var(--red)">Остаток долга</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody id="reconcile-orders-tbody">
                        <tr v-for="x in r.shippedRows" :key="x.o.num">
                            <td style="font-family: monospace; color: var(--accent)">{{ x.o.num }}</td>
                            <td style="font-size: 12px">{{ dmy(x.o.date) || '—' }}</td>
                            <td style="font-weight: 500">{{ x.o.customer }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(x.total) }}</td>
                            <td style="text-align: right; font-family: monospace; color: var(--green)">{{ x.paid > 0.5 ? fmt(x.paid) : '—' }}</td>
                            <td :style="{ textAlign: 'right', fontFamily: 'monospace', fontWeight: 700, color: x.debt > 0 ? 'var(--red)' : 'var(--green)' }">{{ x.debt > 0 ? fmt(x.debt) : '✓' }}</td>
                            <td>
                                <span v-if="x.debt < 0.5" class="badge badge-green">✅ Оплачен</span>
                                <span v-else-if="x.paid > 0" class="badge badge-amber">⚠️ Частично</span>
                                <span v-else class="badge badge-red">🔴 Не оплачен</span>
                            </td>
                        </tr>
                        <tr v-if="!r.shippedRows.length">
                            <td colspan="7" style="text-align: center; padding: 20px; color: var(--text3)">Отправленных заказов нет</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="reconcile-prepay-block">
                <div v-if="r.pending.length" class="card" style="padding: 0; margin-top: 16px">
                    <div style="padding: 12px 16px; font-weight: 600; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center">
                        <span>⏳ Долг по предоплате 50% — заказы в работе/очереди</span>
                        <span style="font-size: 12px; color: var(--text3)">Не входят в сверку</span>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Заказ</th>
                                <th>Дата</th>
                                <th>Клиент</th>
                                <th>Статус</th>
                                <th style="text-align: right">Сумма заказа</th>
                                <th style="text-align: right; color: var(--amber)">Предоплата 50%</th>
                                <th>Статус оплаты</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in r.pending" :key="o.num">
                                <td style="font-family: monospace; color: var(--accent)">{{ o.num }}</td>
                                <td style="font-size: 12px">{{ dmy(o.date) || '—' }}</td>
                                <td style="font-weight: 500">{{ o.customer }}</td>
                                <td><span class="badge badge-amber">{{ o.status === 'queue' ? '🕐 В очереди' : '🔄 В работе' }}</span></td>
                                <td style="text-align: right; font-family: monospace">{{ fmt(total(o)) }}</td>
                                <td style="text-align: right; font-family: monospace; font-weight: 700; color: var(--amber)">{{ fmt(total(o) * 0.5) }}</td>
                                <td><span class="badge badge-red">⚠️ Ожидается</span></td>
                            </tr>
                            <tr style="font-weight: 700; background: var(--bg3)">
                                <td colspan="5" style="padding: 8px 12px">Итого долг по предоплате</td>
                                <td style="text-align: right; font-family: monospace; color: var(--amber); padding: 8px 12px">{{ fmt(r.prepayDebt) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" style="padding: 0">
                <div style="padding: 12px 16px; font-weight: 600; border-bottom: 1px solid var(--border)">💳 Переводы в офис (РС и касса)</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Описание</th>
                            <th>Тип</th>
                            <th style="text-align: right; color: var(--green)">Сумма</th>
                        </tr>
                    </thead>
                    <tbody id="reconcile-transfers-tbody">
                        <tr v-for="(t, i) in transfers" :key="i">
                            <td style="font-size: 12px">{{ t.date }}</td>
                            <td style="font-size: 12px">{{ t.desc }}</td>
                            <td><span class="badge badge-blue">{{ props.cash ? '💵 Касса' : '🏦 Банк' }}</span></td>
                            <td style="text-align: right; font-family: monospace; font-weight: 700; color: var(--green)">₸{{ Math.round(Number(t.amount)).toLocaleString('ru-RU') }}</td>
                        </tr>
                        <tr v-if="!transfers.length">
                            <td colspan="4" style="text-align: center; padding: 16px; color: var(--text3)">Переводов нет</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </ErpLayout>
</template>
