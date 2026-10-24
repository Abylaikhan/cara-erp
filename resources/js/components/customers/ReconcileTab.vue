<script setup lang="ts">
// Tab «Сверки» of «Покупатели» — legacy cust-reconcile-counterparties (L3851–3885), renderCounterpartyReconcile/
// openRecDrillTx/printReconcile (L19698–19870). An act of reconciliation with an office customer, a dealer or a salon from the
// orders: shipment (total) as debit, payment by the paid share as credit, running balance, KPIs; a payment row opens the
// operations with the order's number. Rows go by the order date in calendar order (legacy compared «дд.мм.гггг» as text). The
// order form waits for ERP-029/030, Excel for ERP-067.
import { downloadXlsx, ruToday, tableCells } from '@/lib/xlsx';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import DrilldownModal from '@/components/DrilldownModal.vue';
import type { Dealer } from '@/types';

export interface ReconcileOrder {
    num: string;
    channel: string;
    customer: string | null;
    date: string | null;
    created: string | null;
    total: string;
    payment: number;
}
const props = defineProps<{ orders: ReconcileOrder[] | undefined; dealers: Dealer[]; active: boolean }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.orders === undefined) router.reload({ only: ['reconcileOrders'] });
    },
    { immediate: true },
);

const f = reactive({ type: 'counterparty' as 'counterparty' | 'dealer' | 'salon', party: '', from: '', to: '' });
const fmt = (n: number) => '₸' + Math.round(Math.abs(n)).toLocaleString('ru-RU');
const dmy = (d: string | null) => (d ? d.split('-').reverse().join('.') : '');
const list = computed(() => props.orders ?? []);
// Legacy: the office customers of the orders (sorted as strings), the dealers of «Дилеры», the two salons.
const parties = computed(() =>
    f.type === 'counterparty'
        ? [...new Set(list.value.filter((o) => o.channel === 'office').map((o) => o.customer ?? ''))].sort().map((n) => ({ v: n, l: n }))
        : f.type === 'dealer'
          ? props.dealers.map((d) => ({ v: d.code, l: d.name }))
          : [{ v: 'salon_armada', l: 'Салон Армада' }, { v: 'salon_nazarbayeva', l: 'Салон Назарбаева' }],
);
watch(() => f.type, () => (f.party = ''));

const rec = computed(() => {
    if (!f.party) return null;
    let orders = f.type === 'counterparty' ? list.value.filter((o) => o.customer === f.party && o.channel === 'office') : list.value.filter((o) => o.channel === f.party);
    if (f.from || f.to)
        orders = orders.filter((o) => {
            const d = o.date || o.created || '';
            return !(f.from && d && d < f.from) && !(f.to && d && d > f.to);
        });
    orders = [...orders].sort((a, b) => (a.date || '').localeCompare(b.date || ''));
    let balance = 0;
    const rows: { date: string; doc: string; op: string; debit: number; credit: number; balance: number }[] = [];
    orders.forEach((o) => {
        const total = Number(o.total);
        const paid = (total * Math.min(o.payment || 0, 100)) / 100;
        balance += total;
        rows.push({ date: dmy(o.date || o.created) || '—', doc: o.num, op: 'Отгрузка / Реализация', debit: total, credit: 0, balance });
        if (paid > 0) {
            balance -= paid;
            rows.push({ date: dmy(o.date) || '—', doc: o.num, op: 'Оплата', debit: 0, credit: paid, balance });
        }
    });
    const debit = rows.reduce((s, r) => s + r.debit, 0);
    const credit = rows.reduce((s, r) => s + r.credit, 0);
    return { rows, debit, credit, final: debit - credit, count: orders.length };
});

const dd = ref<InstanceType<typeof DrilldownModal> | null>(null);
const openOrder = () => window.alert('Форма заказа появится вместе с правкой заказов (ERP-029/030, решения D-02 и D-12).');
let lastDrill = '';
async function openRecDrillTx(num: string): Promise<void> {
    lastDrill = num; // two orders clicked quickly: only the last one opens
    const order = list.value.find((o) => o.num === num);
    const total = order ? Number(order.total) : 0;
    const res = await fetch(`/customers/reconcile/payments/${encodeURIComponent(num)}`, { headers: { Accept: 'application/json' } });
    const txs: { date: string; category: string | null; desc: string | null; amount: string }[] = res.ok ? await res.json() : [];
    if (lastDrill !== num) return;
    const rows = txs.map((t) => ({ date: t.date || '—', cat: t.category === 'order_payment' ? 'Оплата заказа' : t.category || '—', desc: t.desc || '—', amount: Number(t.amount) || 0 }));
    const paid = rows.reduce((s, r) => s + r.amount, 0);
    const f2 = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
    dd.value?.openDrilldown(`Платежи по ${num}`, `Сумма заказа: ${f2(total)} · Оплачено: ${f2(paid)} · Остаток: ${f2(Math.max(0, total - paid))}`, rows, [
        { key: 'date', label: 'Дата' },
        { key: 'cat', label: 'Вид' },
        { key: 'desc', label: 'Описание' },
        { key: 'amount', label: 'Сумма', num: true, color: 'var(--green)' },
    ]);
}
// Legacy exportReconcileExcel (L20111), ADR-0044: the act as it stands on the screen.
function exportReconcileExcel(): void {
    void downloadXlsx('customers', 'Сверка_' + ruToday() + '.xlsx', [{ name: 'Сверка', rows: [['Дата', 'Документ', 'Операция', 'Дебет', 'Кредит', 'Сальдо'], ...tableCells('#rec-tbody')] }]);
}
const printReconcile = () => window.print();
const balColor = (b: number) => (b > 0 ? 'var(--red)' : b < 0 ? 'var(--green)' : 'var(--text3)');
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center">
        <select id="rec-type" v-model="f.type" style="font-size: 13px">
            <option value="counterparty">🏢 С контрагентом</option>
            <option value="dealer">🚚 С дилером</option>
            <option value="salon">🏬 С салоном</option>
        </select>
        <select id="rec-party" v-model="f.party" style="font-size: 13px; min-width: 220px">
            <option value="">— выберите —</option>
            <option v-for="p in parties" :key="p.v" :value="p.v">{{ p.l }}</option>
        </select>
        <input id="rec-date-from" v-model="f.from" type="date" style="font-size: 13px" />
        <span style="color: var(--text3)">—</span>
        <input id="rec-date-to" v-model="f.to" type="date" style="font-size: 13px" />
        <button class="btn btn-ghost btn-sm" @click="printReconcile">🖨 Распечатать</button>
        <button class="btn btn-ghost btn-sm" @click="exportReconcileExcel">📥 Excel</button>
    </div>

    <div id="rec-kpi" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
        <template v-if="rec">
            <div
                v-for="k in [
                    { label: 'Отгружено', value: fmt(rec.debit), color: 'var(--accent)' },
                    { label: 'Оплачено', value: fmt(rec.credit), color: 'var(--green)' },
                    { label: 'Долг', value: fmt(Math.max(0, rec.final)), color: rec.final > 0 ? 'var(--red)' : 'var(--green)' },
                    { label: 'Заказов', value: rec.count, color: 'var(--text2)' },
                ]"
                :key="k.label"
                class="card"
                style="padding: 12px"
            >
                <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">{{ k.label }}</div>
                <div :style="{ fontSize: '18px', fontWeight: 700, color: k.color }">{{ k.value }}</div>
            </div>
        </template>
    </div>

    <div id="rec-table-wrap" class="card" style="padding: 0">
        <table id="rec-table" class="data-table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Документ / Заказ</th>
                    <th>Операция</th>
                    <th style="text-align: right">Дебет (долг)</th>
                    <th style="text-align: right">Кредит (оплата)</th>
                    <th style="text-align: right">Сальдо</th>
                </tr>
            </thead>
            <tbody id="rec-tbody">
                <tr v-if="!rec">
                    <td colspan="6" style="text-align: center; padding: 24px; color: var(--text3)">Выберите контрагента</td>
                </tr>
                <tr v-else-if="!rec.rows.length">
                    <td colspan="6" style="text-align: center; padding: 24px; color: var(--text3)">Нет операций за период</td>
                </tr>
                <template v-else>
                    <tr
                        v-for="(r, i) in rec.rows"
                        :key="i"
                        style="cursor: pointer"
                        :title="r.debit > 0 ? '🔍 Открыть заказ' : '🔍 Показать платежи'"
                        @click="r.debit > 0 ? openOrder() : openRecDrillTx(r.doc)"
                    >
                        <td style="font-size: 12px; white-space: nowrap">{{ r.date }}</td>
                        <td style="font-family: monospace; font-size: 12px; color: var(--accent)">{{ r.doc }} <span style="font-size: 10px; color: var(--text3)">🔍</span></td>
                        <td style="font-size: 13px">{{ r.op }}</td>
                        <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: r.debit > 0 ? 'var(--red)' : 'var(--text3)' }">{{ r.debit > 0 ? fmt(r.debit) : '—' }}</td>
                        <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: r.credit > 0 ? 'var(--green)' : 'var(--text3)' }">{{ r.credit > 0 ? fmt(r.credit) : '—' }}</td>
                        <td :style="{ textAlign: 'right', fontFamily: 'monospace', fontWeight: 600, color: balColor(r.balance) }">{{ r.balance === 0 ? '0' : fmt(r.balance) }}</td>
                    </tr>
                </template>
            </tbody>
            <tfoot id="rec-tfoot">
                <tr v-if="rec && rec.rows.length" style="background: var(--bg3); font-weight: 700">
                    <td colspan="3" style="padding: 8px 12px">Итого:</td>
                    <td style="text-align: right; font-family: monospace; color: var(--red)">{{ fmt(rec.debit) }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(rec.credit) }}</td>
                    <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: rec.final > 0 ? 'var(--red)' : 'var(--green)' }">
                        {{ fmt(rec.final) }} {{ rec.final > 0 ? '(долг)' : '(переплата)' }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <DrilldownModal ref="dd" />
</template>
