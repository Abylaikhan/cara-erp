<script setup lang="ts">
// Tabs «По производству» and «По оплате» of CRM — legacy orders-orders-kanban-prod/-pay (L4254–4319), setOKPeriod/
// setOKPeriodPay/renderOrdersKanban (L31174–31364). Same filters, columns and cards; the orders are loaded when a board is
// first opened. Fixed: the orders in work stand in «🔨 В производстве» (legacy looked for a status «production» they never
// have and lost them), a completed order that is not fully paid stays in «🚀 Отправлен», the month and quarter are counted
// in local dates (legacy shifted them by a day through UTC), «Период...» keeps its dates. «По оплате» uses the paid share
// stored in the order; legacy recomputes it from the money operations when they are loaded (D-03).
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { localISODate, ORDER_FORM_LATER } from '@/lib/sales';

interface BoardOrder {
    num: string;
    channel: string;
    customer: string | null;
    end_client: string | null;
    created: string | null;
    date: string | null;
    total: number;
    payment: number;
    status: string;
    progress: number;
}
const props = defineProps<{ orders: BoardOrder[] | undefined; kind: 'prod' | 'pay'; channelBound: boolean; today: string; active: boolean }>();

watch(
    () => props.active,
    (active) => {
        if (active && props.orders === undefined) router.reload({ only: ['boardOrders'] });
    },
    { immediate: true },
);

const now = new Date();
const f = reactive({ period: 'month', from: '', to: '', channel: '' });
const range = computed((): [string, string] => {
    const y = now.getFullYear();
    const m = now.getMonth();
    if (f.period === 'month') return [localISODate(y, m, 1), localISODate(y, m + 1, 0)];
    if (f.period === 'quarter') {
        const q = Math.floor(m / 3);
        return [localISODate(y, q * 3, 1), localISODate(y, q * 3 + 3, 0)];
    }
    if (f.period === 'year') return [`${y}-01-01`, `${y}-12-31`];
    if (f.period === 'custom') return [f.from, f.to];
    return ['', ''];
});

const toISO = (d: string | null) => {
    if (!d) return '';
    if (d.includes('-')) return d.slice(0, 10);
    const p = d.split('.');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : '';
};
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const orders = computed(() => {
    const [from, to] = range.value;
    return (props.orders ?? []).filter((o) => {
        // «По производству»: an order leaves the board when it is completed and fully paid.
        if (props.kind === 'prod' && o.status === 'done' && (o.payment || 0) >= 100) return false;
        const d = toISO(o.created || o.date);
        if (d && from && d < from) return false;
        if (d && to && d > to) return false;
        return !f.channel || o.channel === f.channel;
    });
});

const stages = computed(() =>
    props.kind === 'prod'
        ? [
              { label: '📋 В очереди', color: '#6b7280', filter: (o: BoardOrder) => o.status === 'queue' },
              { label: '🔨 В производстве', color: '#f59e0b', filter: (o: BoardOrder) => o.status === 'progress' || o.status === 'production' },
              { label: '📦 Готов', color: '#8b5cf6', filter: (o: BoardOrder) => o.status === 'ready' || o.status === 'shipping' },
              { label: '🚀 Отправлен', color: '#06b6d4', filter: (o: BoardOrder) => o.status === 'delivered' || o.status === 'done' },
          ]
        : [
              { label: '💰 Не оплачен', color: '#ef4444', filter: (o: BoardOrder) => (o.payment || 0) === 0 },
              { label: '💳 Частично оплачен', color: '#f59e0b', filter: (o: BoardOrder) => (o.payment || 0) > 0 && (o.payment || 0) < 100 },
              { label: '✅ Полностью оплачен', color: '#10b981', filter: (o: BoardOrder) => (o.payment || 0) >= 100 && o.status !== 'done' },
              { label: '🏁 Завершён', color: '#6b7280', filter: (o: BoardOrder) => (o.payment || 0) >= 100 && o.status === 'done' },
          ],
);
const columns = computed(() =>
    stages.value.map((s) => {
        const list = orders.value.filter(s.filter);
        return { ...s, list, total: list.reduce((a, o) => a + o.total, 0) };
    }),
);
const debt = (o: BoardOrder) => Math.max(0, o.total - (o.total * (o.payment || 0)) / 100);
const overdue = (o: BoardOrder) => {
    const d = toISO(o.date);
    return !!d && d < props.today;
};
const ids = computed(() =>
    props.kind === 'prod'
        ? { period: 'ok-period', dates: 'ok-period-dates', from: 'ok-date-from', to: 'ok-date-to', channel: 'ok-channel-filter', count: 'ok-count', board: 'ok-board-production' }
        : { period: 'ok-period-pay', dates: 'ok-period-dates-pay', from: 'ok-date-from-pay', to: 'ok-date-to-pay', channel: 'ok-channel-filter-pay', count: 'ok-count-pay', board: 'ok-board-payment' },
);
const openOrder = () => window.alert(ORDER_FORM_LATER);
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap">
        <select :id="ids.period" v-model="f.period" style="font-size: 13px">
            <option value="month">Текущий месяц</option>
            <option value="quarter">Квартал</option>
            <option value="year">Текущий год</option>
            <option value="active">Все активные</option>
            <option value="custom">Период...</option>
        </select>
        <div :id="ids.dates" style="gap: 4px; align-items: center" :style="{ display: f.period === 'custom' ? 'flex' : 'none' }">
            <input :id="ids.from" v-model="f.from" type="date" style="font-size: 12px" />
            <span style="color: var(--text3)">—</span>
            <input :id="ids.to" v-model="f.to" type="date" style="font-size: 12px" />
        </div>
        <select :id="ids.channel" v-model="f.channel" style="font-size: 13px" :style="{ display: props.channelBound ? 'none' : '' }">
            <option value="">Все каналы</option>
            <option value="office">🏢 Офис</option>
            <option value="salon_armada">🏬 Салон Армада</option>
            <option value="salon_nazarbayeva">🏪 Салон Назарбаева</option>
            <option value="dealer_astana">🚚 Дилер Астана</option>
            <option value="dealer_aktau">🚚 Дилер Актау</option>
            <option value="dealer_atyrau">🚚 Дилер Атырау</option>
            <option value="dealer_shymkent">🚚 Дилер Шымкент</option>
            <option value="dealer_aktobe">🚚 Дилер Актобе</option>
        </select>
        <span :id="ids.count" style="font-size: 12px; color: var(--text3)">{{ props.kind === 'prod' && props.orders ? `${orders.length} активных заказов` : '' }}</span>
    </div>
    <div v-if="props.kind === 'prod'" style="font-size: 11px; color: var(--text3); margin-bottom: 10px">Заказ исчезает когда отгружен <b>и</b> оплачен на 100%</div>
    <div :id="ids.board" style="display: flex; gap: 10px; overflow-x: auto; padding-bottom: 8px">
        <template v-if="props.orders">
            <div v-for="c in columns" :key="c.label" style="min-width: 190px; flex: 1; background: var(--bg3); border-radius: 10px; padding: 10px">
                <div style="padding: 7px 10px; border-radius: 7px; margin-bottom: 8px" :style="{ background: c.color + '22', border: '1px solid ' + c.color + '44' }">
                    <div style="font-weight: 700; font-size: 12px" :style="{ color: c.color }">{{ c.label }}</div>
                    <div style="font-size: 10px; color: var(--text3)">{{ c.list.length }} · {{ fmt(c.total) }}</div>
                </div>
                <div v-if="!c.list.length" style="text-align: center; padding: 12px; color: var(--text3); font-size: 11px">Пусто</div>
                <div
                    v-for="o in c.list"
                    :key="o.num"
                    style="background: var(--bg2); border: 1px solid var(--border2); border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer; min-width: 160px"
                    :style="{ borderLeft: '3px solid ' + (overdue(o) ? 'var(--red)' : c.color) }"
                    @click="openOrder"
                >
                    <div style="display: flex; justify-content: space-between; margin-bottom: 2px">
                        <span style="font-family: monospace; font-size: 11px; font-weight: 700" :style="{ color: c.color }">{{ o.num }}</span>
                        <span style="font-size: 10px" :style="{ color: overdue(o) ? 'var(--red)' : 'var(--text3)' }">{{ o.date || '' }}</span>
                    </div>
                    <div style="font-weight: 600; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px">{{ o.customer }}</div>
                    <div v-if="o.end_client" style="font-size: 11px; color: var(--accent)">👤 {{ o.end_client }}</div>
                    <div style="display: flex; justify-content: space-between; margin-top: 4px">
                        <span style="font-size: 12px; color: var(--green); font-weight: 600">{{ fmt(o.total) }}</span>
                        <span v-if="debt(o) > 0" style="font-size: 10px; color: var(--red)">−{{ fmt(debt(o)) }}</span>
                        <span v-else style="font-size: 10px; color: var(--green)">✅</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px; margin-top: 5px">
                        <div style="flex: 1; height: 3px; background: var(--border); border-radius: 2px">
                            <div style="height: 100%; border-radius: 2px" :style="{ width: o.progress + '%', background: c.color }"></div>
                        </div>
                        <span style="font-size: 9px; color: var(--text3)">{{ o.progress }}%</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
