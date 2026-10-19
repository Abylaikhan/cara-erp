<script setup lang="ts">
// M21 «Отчёт салонов», part 1 — legacy page-salon_report (L8118–8244), initSalonReport/renderSalonReport/showSalonReportTab
// (L16283–16610). Tab «Текущий период»: the salon orders of the month by their creation date (orders without a date are always
// shown), KPIs, the sellers, every order. The month ends on its last calendar day (legacy lost it through UTC). The seller's
// salary needs the commissions and salaries of «Сотрудники». Tabs «Платёжная ведомость» and «История ЗП» read the salon payroll
// (legacy salon_payroll), loaded with the first of them.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import HistoryTab from '@/components/salon/HistoryTab.vue';
import PaysheetTab from '@/components/salon/PaysheetTab.vue';
import type { PayrollLine } from '@/components/salon/payroll';
import { yearsFrom } from '@/components/salon/payroll';
import ErpLayout from '@/layouts/ErpLayout.vue';

interface SalonOrder {
    num: string;
    channel: string;
    customer: string | null;
    end_client: string | null;
    seller: string | null;
    created: string | null;
    date: string | null;
    payment: number;
    status: string;
    total: string;
}
const props = defineProps<{ today: string; salonFixed: string | null; orders: SalonOrder[]; payroll?: PayrollLine[] }>();

const tab = ref<'current' | 'paysheet' | 'history'>('current');
// The payroll comes with the first of its tabs, as legacy loads it (loadSalonPayroll).
watch(tab, (t) => {
    if (t !== 'current' && props.payroll === undefined) router.reload({ only: ['payroll'] });
});
const [ty, tm] = props.today.split('-').map(Number);
const years = yearsFrom(props.today);
const f = reactive({ month: String(tm), year: String(ty), salon: props.salonFixed ?? '' });
const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const total = (o: SalonOrder) => Number(o.total);
const paidOf = (o: SalonOrder) => (total(o) * (o.payment || 0)) / 100;

const report = computed(() => {
    const m = +f.month,
        y = +f.year;
    const from = `${y}-${String(m).padStart(2, '0')}-01`;
    const to = `${y}-${String(m).padStart(2, '0')}-${String(new Date(Date.UTC(y, m, 0)).getUTCDate()).padStart(2, '0')}`;
    const orders = props.orders.filter((o) => {
        if (f.salon && o.channel !== f.salon) return false;
        const d = o.created || o.date || '';
        return !d || (d >= from && d <= to);
    });
    const sum = orders.reduce((s, o) => s + total(o), 0);
    const paid = orders.reduce((s, o) => s + paidOf(o), 0);
    const sellers = new Map<string, { name: string; count: number; sum: number; paid: number }>();
    orders.forEach((o) => {
        const name = o.seller || '—';
        const s = sellers.get(name) ?? { name, count: 0, sum: 0, paid: 0 };
        s.count++;
        s.sum += total(o);
        s.paid += paidOf(o);
        sellers.set(name, s);
    });
    return {
        orders: [...orders].sort((a, b) => (b.created || b.date || '').localeCompare(a.created || a.date || '')),
        kpis: [
            { label: '📋 Заказов', val: String(orders.length), color: 'var(--accent)' },
            { label: '💰 Продажи', val: fmt(sum), color: 'var(--green)' },
            { label: '✅ Оплачено', val: fmt(paid), color: 'var(--green)' },
            { label: '⚠️ Долг', val: fmt(sum - paid), color: sum - paid > 0 ? 'var(--red)' : 'var(--text3)' },
        ],
        sellers: [...sellers.values()].sort((a, b) => b.sum - a.sum),
    };
});
// Legacy: the end client of the order, «—» when it is only the salon's name.
const CHANNEL_NAMES = ['Салон Армада', 'Салон Назарбаева', 'salon_armada', 'salon_nazarbayeva'];
const client = (o: SalonOrder) => {
    const raw = o.end_client || o.customer || '';
    return CHANNEL_NAMES.some((n) => raw === n || raw === n.replace('Салон ', '')) ? '—' : raw || '—';
};
// Legacy STATUS has no «progress» (the orders' status in work) and printed the code; here it is «В работе».
const STATUS: Record<string, string> = { queue: '⏳ Очередь', progress: '🔨 В работе', production: '🔨 В работе', ready: '📦 Готов', delivered: '🚚 Доставлен', done: '✅ Завершён', cancelled: '❌ Отменён' };
const dmy = (iso: string | null) => (iso ? iso.split('-').reverse().join('.') : '—');
const openOrder = () => window.alert('Форма заказа появится вместе с правкой заказов (ERP-029/030, решения D-02 и D-12).');
</script>

<template>
    <ErpLayout>
        <div id="page-salon_report" class="page active">
            <div class="page-header">
                <h2>📊 Отчёт по салонам</h2>
            </div>
            <div class="tabs" style="margin-bottom: 16px">
                <div class="tab" :class="{ active: tab === 'current' }" @click="tab = 'current'">📋 Текущий период</div>
                <div class="tab" :class="{ active: tab === 'paysheet' }" @click="tab = 'paysheet'">📄 Платёжная ведомость</div>
                <div class="tab" :class="{ active: tab === 'history' }" @click="tab = 'history'">📅 История ЗП</div>
            </div>

            <div id="sr-tab-current" :style="{ display: tab === 'current' ? '' : 'none' }">
                <div style="display: flex; gap: 8px; margin-bottom: 16px; align-items: center; flex-wrap: wrap">
                    <select id="sr-month" v-model="f.month" style="font-size: 13px">
                        <option v-for="(m, i) in MONTHS" :key="m" :value="String(i + 1)">{{ m }}</option>
                    </select>
                    <select id="sr-year" v-model="f.year" style="font-size: 13px">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <select id="sr-salon" v-model="f.salon" style="font-size: 13px" :style="props.salonFixed ? { display: 'none' } : undefined">
                        <option value="">Оба салона</option>
                        <option value="salon_armada">🏬 Армада</option>
                        <option value="salon_nazarbayeva">🏪 Назарбаева</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" @click="router.reload()">🔄 Обновить</button>
                </div>
                <div class="card" style="margin-bottom: 16px; border: 1px solid var(--accent)44">
                    <div style="font-weight: 700; font-size: 15px; margin-bottom: 14px">💰 Расчёт зарплаты продавца</div>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px">
                        <div id="sr-salary-inputs"><div style="font-size: 12px; color: var(--text3)">% и метод настраиваются в разделе Сотрудники → Комиссии</div></div>
                    </div>
                    <div id="sr-salary-result">
                        <div v-if="!report.sellers.some((s) => s.name !== '—')" style="color: var(--text3)">Нет продавцов за период</div>
                        <div v-else style="color: var(--text3); font-size: 13px">
                            Расчёт ЗП продавцов появится вместе с разделом «Сотрудники»: оклады, проценты и метод комиссии хранятся там (ERP-053, решение D-13).
                        </div>
                    </div>
                </div>
                <div id="sr-kpi" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px">
                    <div v-for="k in report.kpis" :key="k.label" class="pl-kpi">
                        <div class="pl-kpi-label">{{ k.label }}</div>
                        <div class="pl-kpi-value" :style="{ color: k.color }">{{ k.val }}</div>
                    </div>
                </div>
                <div class="card" style="margin-bottom: 16px">
                    <div style="font-weight: 700; font-size: 14px; margin-bottom: 12px">👤 По ответственным продавцам</div>
                    <table id="sr-sellers-table" class="data-table">
                        <thead>
                            <tr>
                                <th>Продавец</th>
                                <th style="text-align: right">Заказов</th>
                                <th style="text-align: right">Сумма</th>
                                <th style="text-align: right">Оплачено</th>
                                <th style="text-align: right">Долг</th>
                            </tr>
                        </thead>
                        <tbody id="sr-sellers-tbody">
                            <tr v-for="s in report.sellers" :key="s.name">
                                <td style="font-weight: 600">{{ s.name }}</td>
                                <td style="text-align: right">{{ s.count }}</td>
                                <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(s.sum) }}</td>
                                <td style="text-align: right; font-family: monospace">{{ fmt(s.paid) }}</td>
                                <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: s.sum - s.paid > 0 ? 'var(--red)' : 'var(--text3)' }">
                                    {{ s.sum - s.paid > 0 ? fmt(s.sum - s.paid) : '✅' }}
                                </td>
                            </tr>
                            <tr v-if="!report.sellers.length">
                                <td colspan="5" style="text-align: center; color: var(--text3)">Нет данных</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card" style="padding: 0">
                    <div style="padding: 12px 16px; font-weight: 700; font-size: 14px; border-bottom: 1px solid var(--border)">📋 Все заказы за период</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Заказ</th>
                                <th>Дата</th>
                                <th>Клиент</th>
                                <th>Продавец</th>
                                <th style="text-align: right">Сумма</th>
                                <th style="text-align: right">Оплачено</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody id="sr-orders-tbody">
                            <tr v-for="o in report.orders" :key="o.num" v-memo="[o]" style="cursor: pointer" @click="openOrder">
                                <td style="font-family: monospace; color: var(--accent); font-size: 12px">{{ o.num }}</td>
                                <td style="font-size: 12px">{{ dmy(o.created || o.date) }}</td>
                                <td style="font-size: 12px">{{ client(o) }}</td>
                                <td style="font-size: 11px; color: var(--text3)">{{ o.seller || '—' }}</td>
                                <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(total(o)) }}</td>
                                <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: (o.payment || 0) >= 100 ? 'var(--green)' : 'var(--amber)' }">{{ o.payment || 0 }}%</td>
                                <td style="font-size: 11px">{{ STATUS[o.status] || o.status }}</td>
                            </tr>
                            <tr v-if="!report.orders.length">
                                <td colspan="7" style="text-align: center; color: var(--text3)">Нет заказов за период</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="sr-tab-paysheet" :style="{ display: tab === 'paysheet' ? '' : 'none' }">
                <PaysheetTab :payroll="props.payroll" :today="props.today" />
            </div>

            <div id="sr-tab-history" :style="{ display: tab === 'history' ? '' : 'none' }">
                <HistoryTab :payroll="props.payroll" :active="tab === 'history'" :today="props.today" />
            </div>
        </div>
    </ErpLayout>
</template>
