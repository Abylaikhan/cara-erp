<script setup lang="ts">
// M09 «Продажи», part 1 — legacy page-sales (L3824–4133): showPage('sales') (L16390), showSalesTab, renderOrders,
// setOrderYear/setOrderMonth/clearOrderFilters/sortOrders, updateOrderTabCounts (L29921–30252). The journal is the same; the
// orders are those of the user's channels. Orders are not edited yet: the order form, «+ Новый заказ», realization, its
// worksheet and the report come with ERP-029/030/049/050 (decisions D-02, D-03, D-08, D-12).
import { computed, reactive, ref } from 'vue';
import OrdersTable from '@/components/sales/OrdersTable.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { fmtCurrency, paidOf, PL_MONTH_LABELS, sortValue, toISO, type OrderRow } from '@/lib/sales';

const props = defineProps<{ orders: OrderRow[]; channels: Record<string, string> }>();

type Tab = 'all-orders' | 'queue' | 'progress' | 'done' | 'sales-journal' | 'worksheet' | 'report';
const tab = ref<Tab>('all-orders');
const LATER: Partial<Record<Tab, string>> = {
    'sales-journal': 'Журнал реализации переносится следующей частью раздела «Продажи».',
    worksheet: 'Рабочий лист реализации появится вместе с проведением реализации (ERP-049/050, решение D-08).',
    report: 'Отчёт по заказам переносится следующей частью раздела «Продажи».',
};
const newOrder = () => window.alert('Создание заказа появится после решений по сохраняемой цене и составу заказа (D-02, D-12).');

// ── Filters (legacy ord-* controls) ──
const f = reactive({ search: '', from: '', to: '', month: '', year: '', channel: '', status: '', payment: '', seller: '', min: '', max: '' });
const years = computed(() =>
    [...new Set(props.orders.map((o) => toISO(o.date || o.created || '').slice(0, 4)).filter((y) => y.length === 4))].sort().reverse(),
);
const sellers = computed(() => [...new Set(props.orders.map((o) => o.seller).filter((s): s is string => !!s))].sort());

function setOrderYear(year: string): void {
    f.year = year;
    if (year && f.month) {
        const last = new Date(Number(year), Number(f.month), 0).getDate();
        Object.assign(f, { from: `${year}-${String(f.month).padStart(2, '0')}-01`, to: `${year}-${String(f.month).padStart(2, '0')}-${String(last).padStart(2, '0')}` });
    } else if (year) Object.assign(f, { from: `${year}-01-01`, to: `${year}-12-31` });
    else Object.assign(f, { from: '', to: '' });
}
function setOrderMonth(month: string): void {
    f.month = month;
    setOrderYear(f.year || String(new Date().getFullYear()));
}
function clearOrderFilters(): void {
    Object.assign(f, { search: '', from: '', to: '', min: '', max: '', year: '', status: '', channel: '', payment: '', seller: '' });
}

const visible = computed(() => {
    const search = f.search.toLowerCase();
    const min = parseFloat(f.min) || 0;
    const max = parseFloat(f.max) || 0;
    return props.orders.filter((o) => {
        if (f.channel && o.channel !== f.channel) return false;
        // Legacy compared «В производстве» (production) with a status orders never have; it means the orders in work.
        if (f.status && o.status !== (f.status === 'production' ? 'progress' : f.status)) return false;
        if (f.seller && (o.seller || '') !== f.seller) return false;
        if (f.payment === 'paid' && (o.payment || 0) < 100) return false;
        if (f.payment === 'unpaid' && (o.payment || 0) > 0) return false;
        if (f.payment === 'partial' && ((o.payment || 0) === 0 || (o.payment || 0) >= 100)) return false;
        if (search && !((o.customer || '').toLowerCase() + (o.num || '').toLowerCase() + (o.end_client || '').toLowerCase()).includes(search)) return false;
        const iso = toISO(o.date || o.created || '');
        if (f.from && iso && iso < f.from) return false;
        if (f.to && iso && iso > f.to) return false;
        if (min > 0 && o.total < min) return false;
        if (max > 0 && o.total > max) return false;
        return true;
    });
});
const hasFilter = computed(() => !!(f.search || f.from || f.to || f.status || f.channel || f.payment || f.seller || parseFloat(f.min) || parseFloat(f.max)));

// ── Sorting (legacy sortOrders: newest «Срок» first) ──
const sort = reactive({ col: 'date', asc: false });
function sortOrders(col: string): void {
    if (sort.col === col) sort.asc = !sort.asc;
    else Object.assign(sort, { col, asc: col === 'num' || col === 'customer' });
}
const sorted = computed(() =>
    [...visible.value].sort((a, b) => {
        const va = sortValue(a, sort.col);
        const vb = sortValue(b, sort.col);
        if (typeof va === 'number' && typeof vb === 'number') return sort.asc ? va - vb : vb - va;
        return sort.asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
    }),
);
const byStatus = (s: string) => sorted.value.filter((o) => o.status === s);

// ── Summary lines ──
const STATUS_SUMMARY: Record<string, string> = { queue: '⏳ Очередь', progress: '🔨 В работе', ready: '📦 Готов', delivered: '🚚 Доставлен', done: '✅ Завершён', cancelled: '❌ Отменён' };
function stats(orders: OrderRow[]) {
    const sum = orders.reduce((s, o) => s + o.total, 0);
    const paid = orders.reduce((s, o) => s + paidOf(o), 0);
    return { count: orders.length, sum, paid, debt: Math.max(0, sum - paid) };
}
const summary = computed(() => stats(visible.value));
// Legacy counted the statuses after printing them, so its breakdown was always empty.
const breakdown = computed(() => {
    const counts: Record<string, number> = {};
    visible.value.forEach((o) => (counts[o.status] = (counts[o.status] || 0) + 1));
    return Object.keys(STATUS_SUMMARY).filter((s) => counts[s] > 0).map((s) => `${STATUS_SUMMARY[s]}: ${counts[s]}`).join(' · ');
});
const tabStats = computed(() => ({
    queue: stats(visible.value.filter((o) => o.status === 'queue')),
    progress: stats(visible.value.filter((o) => ['progress', 'ready', 'delivered'].includes(o.status))),
    done: stats(visible.value.filter((o) => o.status === 'done')),
}));
const count = (s: string) => props.orders.filter((o) => o.status === s).length;
</script>

<template>
    <ErpLayout>
        <div id="page-sales" class="page active">
            <div class="section-header">
                <div><div class="section-title">Продажи</div><div class="section-sub">Заказы покупателей · журнал реализации · отчёты</div></div>
                <div style="display: flex; gap: 8px">
                    <button class="btn btn-primary btn-sm" @click="newOrder">+ Новый заказ</button>
                </div>
            </div>
            <div id="sales-tabs" class="tabs">
                <div class="tab" :class="{ active: tab === 'all-orders' }" @click="tab = 'all-orders'">📋 Все заказы (<span id="s-count-all">{{ props.orders.length }}</span>)</div>
                <div class="tab" :class="{ active: tab === 'queue' }" @click="tab = 'queue'">⏳ Очередь (<span id="s-count-queue">{{ count('queue') }}</span>)</div>
                <div class="tab" :class="{ active: tab === 'progress' }" @click="tab = 'progress'">🔧 В работе (<span id="s-count-progress">{{ count('progress') }}</span>)</div>
                <div class="tab" :class="{ active: tab === 'done' }" @click="tab = 'done'">✅ Завершено (<span id="s-count-done">{{ count('done') }}</span>)</div>
                <div class="tab" :class="{ active: tab === 'sales-journal' }" @click="tab = 'sales-journal'">📒 Реализация</div>
                <div id="sw-tab-btn" class="tab" :class="{ active: tab === 'worksheet' }" @click="tab = 'worksheet'">📝 Рабочий лист реализации</div>
                <div class="tab" :class="{ active: tab === 'report' }" @click="tab = 'report'">📈 Отчёт</div>
            </div>

            <!-- Status tabs: the same orders by status, with the filters of «Все заказы» -->
            <div v-for="s in ['queue', 'progress', 'done'] as const" :id="'orders-' + s" :key="s" class="orders-tab" :style="{ display: tab === s ? 'block' : 'none' }">
                <div :id="'ord-stats-' + s" style="font-size: 12px; color: var(--text3); padding: 8px 4px; display: flex; gap: 8px; flex-wrap: wrap">
                    <span v-if="!tabStats[s].count" style="color: var(--text3)">{{ { queue: 'Очередь', progress: 'В работе', done: 'Завершено' }[s] }}: нет заказов</span>
                    <template v-else>
                        <span style="color: var(--text2)">{{ tabStats[s].count }} заказов</span>
                        <span style="color: var(--text3)">·</span>
                        <span>Сумма: <b style="color: var(--text1)">{{ fmtCurrency(tabStats[s].sum) }}</b></span>
                        <span style="color: var(--text3)">·</span>
                        <span>Оплачено: <b style="color: var(--green)">{{ fmtCurrency(tabStats[s].paid) }}</b></span>
                        <template v-if="tabStats[s].debt > 0">
                            <span style="color: var(--text3)">·</span><span>Долг: <b style="color: var(--red)">{{ fmtCurrency(tabStats[s].debt) }}</b></span>
                        </template>
                    </template>
                </div>
                <div class="card">
                    <OrdersTable v-if="tab === s" :orders="byStatus(s)" :channels="props.channels" :sort-col="sort.col" :sort-asc="sort.asc" @sort="sortOrders" />
                </div>
            </div>

            <div v-for="(text, key) in LATER" :id="'orders-' + key" :key="key" class="orders-tab" :style="{ display: tab === key ? 'block' : 'none' }">
                <div class="card">
                    <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                </div>
            </div>

            <div id="orders-all-orders" class="orders-tab active" :style="{ display: tab === 'all-orders' ? 'block' : 'none' }">
                <!-- Фильтры -->
                <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center">
                    <input id="ord-search" v-model="f.search" type="search" placeholder="🔍 Покупатель / номер заказа..." style="font-size: 13px; flex: 1; min-width: 200px; max-width: 300px" />
                    <input id="ord-date-from" v-model="f.from" type="date" style="font-size: 13px" title="Дата с" />
                    <span style="color: var(--text3); font-size: 13px">—</span>
                    <input id="ord-date-to" v-model="f.to" type="date" style="font-size: 13px" title="Дата по" />
                    <select id="ord-month" :value="f.month" style="font-size: 13px" @change="setOrderMonth(($event.target as HTMLSelectElement).value)">
                        <option value="">Все месяцы</option>
                        <option v-for="(m, i) in PL_MONTH_LABELS" :key="m" :value="String(i + 1)">{{ m }}</option>
                    </select>
                    <select id="ord-year" :value="f.year" style="font-size: 13px" @change="setOrderYear(($event.target as HTMLSelectElement).value)">
                        <option value="">Все годы</option>
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <select id="ord-channel-filter" v-model="f.channel" style="font-size: 13px">
                        <option value="">Все покупатели</option>
                        <option value="salon_armada">🏬 Салон Армада</option>
                        <option value="salon_nazarbayeva">🏪 Салон Назарбаева</option>
                        <option value="dealer_astana">🚚 Дилер Астана</option>
                        <option value="dealer_aktau">🚚 Дилер Актау</option>
                        <option value="dealer_atyrau">🚚 Дилер Атырау</option>
                        <option value="dealer_shymkent">🚚 Дилер Шымкент</option>
                        <option value="dealer_aktobe">🚚 Дилер Актобе</option>
                        <option value="office">🏢 Офис / Прямые</option>
                    </select>
                    <select id="ord-status-filter" v-model="f.status" style="font-size: 13px">
                        <option value="">Все статусы</option>
                        <option value="queue">⏳ В очереди</option>
                        <option value="production">🔨 В производстве</option>
                        <option value="ready">📦 Готов</option>
                        <option value="delivered">🚚 Доставлен</option>
                        <option value="done">✅ Завершён</option>
                        <option value="cancelled">❌ Отменён</option>
                    </select>
                    <select id="ord-payment-filter" v-model="f.payment" style="font-size: 13px">
                        <option value="">Все оплаты</option>
                        <option value="paid">✅ Оплачен 100%</option>
                        <option value="partial">💳 Частично</option>
                        <option value="unpaid">❌ Не оплачен</option>
                    </select>
                    <select id="ord-seller-filter" v-model="f.seller" style="font-size: 13px">
                        <option value="">Все продавцы</option>
                        <option v-for="s in sellers" :key="s" :value="s">{{ s }}</option>
                    </select>
                    <input id="ord-amount-min" v-model="f.min" type="number" placeholder="Сумма от ₸" style="font-size: 13px; width: 110px" />
                    <input id="ord-amount-max" v-model="f.max" type="number" placeholder="до ₸" style="font-size: 13px; width: 100px" />
                    <button class="btn btn-ghost btn-sm" @click="clearOrderFilters">✕ Сбросить</button>
                    <span id="ord-filter-count" style="font-size: 12px; color: var(--text3)">{{ hasFilter ? `Найдено: ${visible.length} из ${props.orders.length}` : '' }}</span>
                </div>
                <div id="ord-summary-stats" style="font-size: 12px; color: var(--text3); padding: 8px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                    <template v-if="summary.count > 0">
                        <span style="color: var(--text2)">{{ summary.count }} заказов</span>
                        <span style="color: var(--text3)">·</span>
                        <span>{{ breakdown }}</span>
                        <span style="color: var(--text3)">·</span>
                        <span>Сумма: <b style="color: var(--text1)">{{ fmtCurrency(summary.sum) }}</b></span>
                        <span style="color: var(--text3)">·</span>
                        <span>Оплачено: <b style="color: var(--green)">{{ fmtCurrency(summary.paid) }}</b></span>
                        <template v-if="summary.debt > 0">
                            <span style="color: var(--text3)">·</span>
                            <span>Долг: <b style="color: var(--red)">{{ fmtCurrency(summary.debt) }}</b></span>
                        </template>
                    </template>
                </div>
                <div class="card">
                    <OrdersTable v-if="tab === 'all-orders'" :orders="sorted" :channels="props.channels" :sort-col="sort.col" :sort-asc="sort.asc" @sort="sortOrders" />
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
