<script setup lang="ts">
// Tab «Клиенты» of CRM — legacy orders-clients (L4219–4253), renderClientsTab/sortClients (L31366–31460) and the client card
// openClientCard (L38476). The clients of the salons are the end clients of their orders: orders, turnover, debt by the
// paid share, the last order, sellers and products. The orders are loaded when the tab is first opened.
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { ORDER_FORM_LATER, STATUS_BADGE, STATUS_LABELS } from '@/lib/sales';

interface ClientOrder {
    num: string;
    channel: string;
    end_client: string;
    seller: string | null;
    created: string | null;
    date: string | null;
    total: number;
    payment: number;
    status: string;
    products: string[];
}
const props = defineProps<{ orders: ClientOrder[] | undefined; active: boolean }>();

watch(
    () => props.active,
    (active) => {
        if (active && props.orders === undefined) router.reload({ only: ['clientOrders'] });
    },
    { immediate: true },
);

const SALON: Record<string, string> = { salon_armada: '🏬 Армада', salon_nazarbayeva: '🏪 Назарбаева' };
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const toISO = (d: string | null) => {
    if (!d) return '';
    if (d.includes('-')) return d.slice(0, 10);
    const p = d.split('.');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : '';
};
const paid = (o: ClientOrder) => (o.total * (o.payment || 0)) / 100;

const f = reactive({ search: '', salon: '', from: '', to: '' });
const sort = reactive({ col: 'orders', asc: false });
function sortClients(col: string): void {
    if (sort.col === col) sort.asc = !sort.asc;
    else Object.assign(sort, { col, asc: col === 'name' });
}
const icon = (col: string) => (col === sort.col ? (sort.asc ? '▲' : '▼') : '');

interface Client {
    name: string;
    channel: string;
    orders: number;
    turnover: number;
    paid: number;
    lastOrder: string;
    lastNum: string;
    sellers: Set<string>;
    products: string[];
}
const clients = computed(() => {
    const map = new Map<string, Client>();
    (props.orders ?? [])
        .filter((o) => o.channel.startsWith('salon_') && (!f.salon || o.channel === f.salon))
        .forEach((o) => {
            const name = (o.end_client || '').trim();
            if (!name || name === 'NaT') return;
            const iso = toISO(o.created || o.date || '');
            if (f.from && iso && iso < f.from) return;
            if (f.to && iso && iso > f.to) return;
            let c = map.get(name);
            if (!c) map.set(name, (c = { name, channel: o.channel, orders: 0, turnover: 0, paid: 0, lastOrder: '', lastNum: '', sellers: new Set(), products: [] }));
            c.orders++;
            c.turnover += o.total;
            c.paid += paid(o);
            if (!c.lastOrder || iso > c.lastOrder) Object.assign(c, { lastOrder: iso, lastNum: o.num });
            if (o.seller) c.sellers.add(o.seller);
            o.products.forEach((p) => {
                if (p && !c!.products.includes(p)) c!.products.push(p);
            });
        });
    const search = f.search.toLowerCase();
    const debt = (c: Client) => Math.max(0, c.turnover - c.paid);
    return [...map.values()]
        .filter((c) => !search || c.name.toLowerCase().includes(search))
        .sort((a, b) => {
            const key = (c: Client) => (sort.col === 'debt' ? debt(c) : sort.col === 'lastOrder' ? c.lastOrder || '' : (c as unknown as Record<string, string | number>)[sort.col]);
            const va = key(a);
            const vb = key(b);
            if (typeof va === 'number' || typeof vb === 'number') return sort.asc ? (Number(va) || 0) - (Number(vb) || 0) : (Number(vb) || 0) - (Number(va) || 0);
            return sort.asc ? String(va || '').localeCompare(String(vb || '')) : String(vb || '').localeCompare(String(va || ''));
        });
});
const totals = computed(() => ({
    turnover: clients.value.reduce((s, c) => s + c.turnover, 0),
    debt: clients.value.reduce((s, c) => s + Math.max(0, c.turnover - c.paid), 0),
}));

// ── Client card (legacy openClientCard): every order of that end client ──
const card = reactive({ open: false, name: '' });
const cardOrders = computed(() => (props.orders ?? []).filter((o) => o.end_client === card.name));
const cardTotal = computed(() => cardOrders.value.reduce((s, o) => s + o.total, 0));
const cardDebt = computed(() => Math.max(0, cardTotal.value - cardOrders.value.reduce((s, o) => s + paid(o), 0)));
const cardProducts = computed(() => [...new Set(cardOrders.value.flatMap((o) => o.products))]);
const openOrder = () => window.alert(ORDER_FORM_LATER);

defineExpose({ count: computed(() => clients.value.length) });
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center">
        <input id="clients-search" v-model="f.search" type="search" placeholder="🔍 Имя, телефон..." style="font-size: 13px; flex: 1; min-width: 180px; max-width: 280px" />
        <select id="clients-salon-filter" v-model="f.salon" style="font-size: 13px">
            <option value="">Все салоны</option>
            <option value="salon_armada">🏬 Армада</option>
            <option value="salon_nazarbayeva">🏪 Назарбаева</option>
        </select>
        <input id="clients-date-from" v-model="f.from" type="date" style="font-size: 13px" title="Дата первого заказа от" />
        <span style="color: var(--text3)">—</span>
        <input id="clients-date-to" v-model="f.to" type="date" style="font-size: 13px" title="до" />
        <span id="clients-count" style="font-size: 12px; color: var(--text3)">{{ props.orders ? `${clients.length} клиентов` : '' }}</span>
    </div>
    <!-- KPI -->
    <div id="clients-kpi" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px">
        <template v-if="props.orders">
            <div class="pl-kpi">
                <div class="pl-kpi-label">👥 Клиентов</div>
                <div class="pl-kpi-value" style="color: var(--accent)">{{ clients.length }}</div>
            </div>
            <div class="pl-kpi">
                <div class="pl-kpi-label">💰 Оборот</div>
                <div class="pl-kpi-value" style="color: var(--green)">{{ fmt(totals.turnover) }}</div>
            </div>
            <div class="pl-kpi">
                <div class="pl-kpi-label">⚠️ Долг</div>
                <div class="pl-kpi-value" style="color: var(--red)">{{ fmt(totals.debt) }}</div>
            </div>
        </template>
    </div>
    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="cursor: pointer; user-select: none" @click="sortClients('name')">Клиент <span>{{ icon('name') }}</span></th>
                    <th>Салон</th>
                    <th>Продавец</th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortClients('orders')">Заказов <span>{{ icon('orders') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortClients('turnover')">Оборот <span>{{ icon('turnover') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortClients('debt')">Долг <span>{{ icon('debt') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortClients('lastOrder')">Последний заказ <span>{{ icon('lastOrder') }}</span></th>
                    <th>Товары</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="clients-tbody">
                <tr v-if="props.orders && !clients.length">
                    <td colspan="9" style="text-align: center; padding: 20px; color: var(--text3)">Нет клиентов</td>
                </tr>
                <tr v-for="c in clients" :key="c.name" v-memo="[c]">
                    <td style="font-weight: 600">{{ c.name }}</td>
                    <td style="font-size: 11px">{{ SALON[c.channel] || c.channel || '—' }}</td>
                    <td style="font-size: 11px; color: var(--text3)">{{ [...c.sellers].join(', ') || '—' }}</td>
                    <td style="text-align: right; font-family: monospace">{{ c.orders }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(c.turnover) }}</td>
                    <td style="text-align: right; font-family: monospace" :style="{ color: c.turnover - c.paid > 0 ? 'var(--red)' : 'var(--text3)' }">
                        {{ Math.max(0, c.turnover - c.paid) > 0 ? fmt(Math.max(0, c.turnover - c.paid)) : '✅' }}
                    </td>
                    <td style="font-size: 11px; color: var(--text3)">{{ c.lastOrder ? c.lastOrder + ' · ' + c.lastNum : '—' }}</td>
                    <td style="font-size: 11px; color: var(--text3); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" :title="c.products.join(', ')">
                        {{ c.products.slice(0, 2).join(', ') + (c.products.length > 2 ? ` +${c.products.length - 2}` : '') || '—' }}
                    </td>
                    <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="Object.assign(card, { open: true, name: c.name })">👤</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <ErpModal id="client-card-modal" :open="card.open" max-width="640px" :modal-style="{ maxHeight: '80vh', overflowY: 'auto' }" @close="card.open = false">
        <div class="modal-header">
            <span id="client-card-title"></span>
            <button class="modal-close" @click="card.open = false">✕</button>
        </div>
        <div id="client-card-body">
            <div style="padding: 16px; max-width: 600px">
                <h3 style="margin: 0 0 12px">👤 {{ card.name }}</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px">
                    <div class="pl-kpi">
                        <div class="pl-kpi-label">Заказов</div>
                        <div class="pl-kpi-value">{{ cardOrders.length }}</div>
                    </div>
                    <div class="pl-kpi">
                        <div class="pl-kpi-label">Оборот</div>
                        <div class="pl-kpi-value" style="color: var(--green)">{{ fmt(cardTotal) }}</div>
                    </div>
                    <div class="pl-kpi">
                        <div class="pl-kpi-label">Долг</div>
                        <div class="pl-kpi-value" :style="{ color: cardDebt > 0 ? 'var(--red)' : 'var(--green)' }">{{ cardDebt > 0 ? fmt(cardDebt) : 'Нет' }}</div>
                    </div>
                </div>
                <div style="margin-bottom: 12px">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 13px">📦 Заказанные товары</div>
                    <div style="font-size: 12px; color: var(--text2)">{{ cardProducts.join(', ') || '—' }}</div>
                </div>
                <div>
                    <div style="font-weight: 600; margin-bottom: 8px; font-size: 13px">📋 История заказов</div>
                    <div v-if="!cardOrders.length" style="color: var(--text3)">Нет заказов</div>
                    <div
                        v-for="o in cardOrders"
                        :key="o.num"
                        style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 12px"
                    >
                        <span style="color: var(--accent); cursor: pointer" @click="openOrder">{{ o.num }}</span>
                        <span>{{ toISO(o.date) || '—' }}</span>
                        <span style="color: var(--green)">{{ fmt(o.total) }}</span>
                        <span class="badge" :class="STATUS_BADGE[o.status]">{{ STATUS_LABELS[o.status] }}</span>
                        <span :style="{ color: (o.payment || 0) >= 100 ? 'var(--green)' : 'var(--red)' }">{{ o.payment || 0 }}%</span>
                    </div>
                </div>
            </div>
        </div>
    </ErpModal>
</template>
