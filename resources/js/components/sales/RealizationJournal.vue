<script setup lang="ts">
// Tab «Реализация» of «Продажи» — legacy orders-sales-journal (L4009–4061), renderSalesJournal/sortSJ/setSjPeriod/
// setSjMonthYear (L30830–31096). Same filters, totals, table and footer; the lines are loaded when the tab is first opened.
// «Все даты» shows every date (legacy put the current month back right away), the year list holds the years of the
// realizations (legacy read a field they do not have and listed only the current year).
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { localISODate, markOrderDone, ORDER_FORM_LATER, PL_MONTH_LABELS, SJ_CHANNELS, sjStatus, type OrderRow, type RealizationRow } from '@/lib/sales';

const props = defineProps<{ realizations: RealizationRow[] | undefined; orders: OrderRow[]; channels: Record<string, string>; active: boolean }>();

const now = new Date();
const f = reactive({
    month: String(now.getMonth() + 1),
    year: String(now.getFullYear()),
    from: localISODate(now.getFullYear(), now.getMonth(), 1),
    to: localISODate(now.getFullYear(), now.getMonth() + 1, 0),
    channel: '',
    payment: '',
    search: '',
});
const sort = reactive({ col: 'date', asc: true });

watch(
    () => props.active,
    (active) => {
        if (active && props.realizations === undefined) router.reload({ only: ['realizations'] });
    },
    { immediate: true },
);

const years = computed(() => [...new Set([...(props.realizations ?? []).map((r) => r.date.slice(0, 4)), String(now.getFullYear())])].sort().reverse());
function setSjMonthYear(): void {
    if (!f.month || !f.year) return;
    Object.assign(f, { from: localISODate(+f.year, +f.month - 1, 1), to: localISODate(+f.year, +f.month, 0) });
}
function sortSJ(col: string): void {
    if (sort.col === col) sort.asc = !sort.asc;
    else Object.assign(sort, { col, asc: col === 'date' || col === 'num' });
}
const icon = (col: string) => (col === sort.col ? (sort.asc ? '▲' : '▼') : '');

const ordersByNum = computed(() => new Map(props.orders.map((o) => [o.num, o])));
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const rows = computed(() => {
    const search = f.search.toLowerCase();
    const list = (props.realizations ?? []).flatMap((r) => {
        if (f.from && r.date < f.from) return [];
        if (f.to && r.date > f.to) return [];
        const order = r.order_num ? ordersByNum.value.get(r.order_num) : undefined;
        if (f.channel && order && order.channel !== f.channel) return [];
        const payPct = order?.payment || 0;
        if (f.payment === 'paid' && payPct < 100) return [];
        if (f.payment === 'unpaid' && payPct > 0) return [];
        if (f.payment === 'partial' && (payPct === 0 || payPct >= 100)) return [];
        if (search && !(r.order_num || '').toLowerCase().includes(search) && !(r.customer || '').toLowerCase().includes(search) && !(r.name || '').toLowerCase().includes(search)) return [];
        return [
            {
                id: r.id,
                date: r.date,
                num: r.order_num || '',
                customer: r.customer || '',
                endClient: order?.end_client || '',
                channel: order ? SJ_CHANNELS[order.channel] || props.channels[order.channel] || order.channel : '—',
                item: r.name,
                qty: r.qty || 0,
                price: r.qty > 0 ? Math.round((r.revenue || 0) / r.qty) : 0,
                total: r.revenue || 0,
                payPct,
                paid: ((r.revenue || 0) * payPct) / 100,
                status: sjStatus(order?.status),
                order,
            },
        ];
    });
    return list.sort((a, b) => {
        const va = (a as Record<string, unknown>)[sort.col];
        const vb = (b as Record<string, unknown>)[sort.col];
        if (typeof va === 'number' && typeof vb === 'number') return sort.asc ? va - vb : vb - va;
        return sort.asc ? String(va || '').localeCompare(String(vb || '')) : String(vb || '').localeCompare(String(va || ''));
    });
});
const totalSum = computed(() => rows.value.reduce((s, r) => s + r.total, 0));
const totalPaid = computed(() => rows.value.reduce((s, r) => s + r.paid, 0));

const exportLater = () => window.alert('Выгрузка в Excel появится вместе с остальными выгрузками (ERP-067): для .xlsx нужна библиотека, новые зависимости ставятся отдельной задачей.');
const openOrder = () => window.alert(ORDER_FORM_LATER);
const print = () => window.print();
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; align-items: center">
        <select id="sj-month" v-model="f.month" style="font-size: 13px" @change="setSjMonthYear">
            <option v-for="(m, i) in PL_MONTH_LABELS" :key="m" :value="String(i + 1)">{{ m }}</option>
        </select>
        <select id="sj-year" v-model="f.year" style="font-size: 13px" @change="setSjMonthYear">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="Object.assign(f, { from: '', to: '' })">Все даты</button>
        <input id="sj-date-from" v-model="f.from" type="date" style="font-size: 13px" />
        <span style="color: var(--text3)">—</span>
        <input id="sj-date-to" v-model="f.to" type="date" style="font-size: 13px" />
        <select id="sj-channel" v-model="f.channel" style="font-size: 13px">
            <option value="">Все каналы</option>
            <option value="office">🏢 Офис</option>
            <option value="salon_armada">🏬 Армада</option>
            <option value="salon_nazarbayeva">🏪 Назарбаева</option>
            <option value="dealer_astana">🚚 Астана</option>
            <option value="dealer_aktau">🚚 Актау</option>
            <option value="dealer_shymkent">🚚 Шымкент</option>
            <option value="dealer_atyrau">🚚 Атырау</option>
            <option value="dealer_aktobe">🚚 Актобе</option>
        </select>
        <select id="sj-payment-filter" v-model="f.payment" style="font-size: 13px">
            <option value="">Все оплаты</option>
            <option value="paid">✅ Оплачен 100%</option>
            <option value="partial">💳 Частично</option>
            <option value="unpaid">❌ Не оплачен</option>
        </select>
        <input id="sj-search" v-model="f.search" type="search" placeholder="🔍 Заказ, покупатель, товар..." style="font-size: 13px; flex: 1; min-width: 160px" />
        <button class="btn btn-ghost btn-sm" @click="exportLater">📥 Excel</button>
        <button class="btn btn-ghost btn-sm" @click="print">🖨 Печать</button>
    </div>
    <div id="sj-totals" style="font-size: 12px; color: var(--text3); margin-bottom: 8px">
        <template v-if="props.realizations !== undefined">
            {{ rows.length }} позиций · Выручка: <b>{{ fmt(totalSum) }}</b> · Оплачено: <b style="color: var(--green)">{{ fmt(totalPaid) }}</b
            ><template v-if="totalSum > totalPaid"> · Долг: <b style="color: var(--red)">{{ fmt(totalSum - totalPaid) }}</b></template>
        </template>
    </div>
    <div class="card" style="padding: 0">
        <table id="sj-table" class="data-table">
            <thead>
                <tr>
                    <th style="width: 36px">№</th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('date')">Дата <span>{{ icon('date') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('num')">Заказ <span>{{ icon('num') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('customer')">Покупатель <span>{{ icon('customer') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('endClient')">Клиент <span>{{ icon('endClient') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('channel')">Канал <span>{{ icon('channel') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('item')">Товар <span>{{ icon('item') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortSJ('qty')">Кол-во <span>{{ icon('qty') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortSJ('price')">Цена <span>{{ icon('price') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortSJ('total')">Сумма <span>{{ icon('total') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortSJ('payPct')">% опл. <span>{{ icon('payPct') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right" @click="sortSJ('paid')">Оплачено <span>{{ icon('paid') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortSJ('status')">Статус <span>{{ icon('status') }}</span></th>
                </tr>
            </thead>
            <tbody id="sj-tbody">
                <tr v-if="props.realizations === undefined">
                    <td colspan="13" style="text-align: center; padding: 20px; color: var(--text3)">⏳ Загрузка реализации...</td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td colspan="13" style="text-align: center; padding: 20px; color: var(--text3)">Нет данных за период</td>
                </tr>
                <tr v-for="(r, i) in rows" :key="r.id" style="cursor: pointer" @click="openOrder">
                    <td style="color: var(--text3); font-size: 11px">{{ i + 1 }}</td>
                    <td style="font-size: 12px; white-space: nowrap">{{ r.date }}</td>
                    <td style="font-family: monospace; font-size: 12px; color: var(--accent)">{{ r.num }}</td>
                    <td style="font-size: 12px">{{ r.customer }}</td>
                    <td style="font-size: 11px; color: var(--text3)">{{ r.endClient || '—' }}</td>
                    <td style="font-size: 11px">{{ r.channel }}</td>
                    <td style="font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" :title="r.item">{{ r.item }}</td>
                    <td style="text-align: right; font-size: 12px">{{ r.qty }}</td>
                    <td style="text-align: right; font-family: monospace; font-size: 12px">{{ r.price > 0 ? fmt(r.price) : '—' }}</td>
                    <td style="text-align: right; font-family: monospace; font-size: 12px; font-weight: 600">{{ fmt(r.total) }}</td>
                    <td style="text-align: right; font-size: 12px" :style="{ color: r.payPct >= 100 ? 'var(--green)' : r.payPct > 0 ? 'var(--amber)' : 'var(--red)' }">{{ r.payPct }}%</td>
                    <td style="text-align: right; font-family: monospace; font-size: 12px; color: var(--green)">{{ fmt(r.paid) }}</td>
                    <td style="font-size: 11px">
                        {{ r.status
                        }}<button
                            v-if="r.order?.can_complete"
                            class="btn btn-ghost btn-sm"
                            style="font-size: 10px; color: var(--green); padding: 1px 6px; margin-left: 4px"
                            @click.stop="markOrderDone(r.order)"
                        >
                            &#10003; Завершить
                        </button>
                    </td>
                </tr>
            </tbody>
            <tfoot v-if="props.realizations !== undefined" id="sj-tfoot">
                <tr style="background: var(--bg3); font-weight: 700">
                    <td colspan="9" style="padding: 8px 12px">Итого: {{ rows.length }} позиций</td>
                    <td style="text-align: right; font-family: monospace">{{ fmt(totalSum) }}</td>
                    <td></td>
                    <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(totalPaid) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
