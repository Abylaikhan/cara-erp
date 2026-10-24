<script setup lang="ts">
// Tab «Отчёт» of «Продажи» — legacy orders-report (L3880–3940), renderOrderReport/getReportOrders/fillRepMonths/
// toggleRepPeriod/sortReport/_updateSortIcons (L32124–32315): one row per order line with its characteristics, the order's
// number, date and customer on its first line. The lines are loaded when the tab is first opened. A «Период» includes its
// first day (legacy read the start as UTC midnight, which in Almaty is 05:00, and lost that day's orders); the months are
// those of the user's orders.
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { localISODate, type OrderRow } from '@/lib/sales';
import { downloadXlsx } from '@/lib/xlsx';

interface ReportLine {
    order_id: number;
    name: string;
    qty: number;
    attrs: Record<string, string> | string[];
}
const props = defineProps<{
    report: { lines: ReportLine[]; notes: Record<string, { comment: string | null; delivery: string | null }> } | undefined;
    orders: OrderRow[];
    channels: Record<string, string>;
    active: boolean;
}>();

watch(
    () => props.active,
    (active) => {
        if (active && props.report === undefined) router.reload({ only: ['report'] });
    },
    { immediate: true },
);

const MN = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const now = new Date();
// Months of the orders (by order date, else by due date) and the current one, newest first; the newest is chosen.
const months = computed(() => {
    const set = new Set([`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`]);
    props.orders.forEach((o) => {
        const p = (o.created || o.date || '').split('.');
        if (p.length < 3) return;
        const y = p[2].length === 2 ? 2000 + parseInt(p[2]) : parseInt(p[2]);
        const m = parseInt(p[1]);
        if (y > 2000 && y < 2100 && m >= 1 && m <= 12) set.add(`${y}-${String(m).padStart(2, '0')}`);
    });
    return [...set].sort().reverse();
});
const f = reactive({
    period: 'month',
    month: '',
    from: localISODate(now.getFullYear(), now.getMonth(), 1),
    to: localISODate(now.getFullYear(), now.getMonth() + 1, 0),
    status: 'all',
});
const month = computed(() => (months.value.includes(f.month) ? f.month : months.value[0]));
const sort = reactive({ field: 'date', asc: false });
function sortReport(field: string): void {
    if (sort.field === field) sort.asc = !sort.asc;
    else Object.assign(sort, { field, asc: true });
}
const icon = (field: string) => (field === sort.field ? (sort.asc ? ' ▲' : ' ▼') : ' ⬍');

const customer = (o: OrderRow) => (o.channel !== 'office' && props.channels[o.channel]) || o.customer || '—';
const parseRu = (s: string | null): Date | null => {
    const p = (s || '').split('.');
    if (p.length < 3) return null;
    return new Date(p[2].length === 2 ? 2000 + parseInt(p[2]) : parseInt(p[2]), parseInt(p[1]) - 1, parseInt(p[0]));
};
const numOf = (o: OrderRow) => parseInt((o.num || '').replace(/\D/g, '')) || 0;
const dateKey = (o: OrderRow) => new Date((o.created || o.date || '').split('.').reverse().join('-')).getTime();

const orders = computed(() => {
    let from: Date | null = null;
    let to: Date | null = null;
    if (f.period === 'month') {
        const [y, m] = month.value.split('-').map(Number);
        from = new Date(y, m - 1, 1);
        to = new Date(y, m, 0, 23, 59, 59);
    } else if (f.period === 'range') {
        from = f.from ? new Date(f.from + 'T00:00:00') : null;
        to = f.to ? new Date(f.to + 'T23:59:59') : null;
    }
    return props.orders
        .filter((o) => {
            if (f.status !== 'all' && o.status !== f.status) return false;
            if (from || to) {
                const d = parseRu(o.created || o.date);
                if (!d) return false;
                if (from && d < from) return false;
                if (to && d > to) return false;
            }
            return true;
        })
        .sort((a, b) => {
            let va: number | string;
            let vb: number | string;
            if (sort.field === 'num') [va, vb] = [numOf(a), numOf(b)];
            else if (sort.field === 'customer') [va, vb] = [customer(a).toLowerCase(), customer(b).toLowerCase()];
            else [va, vb] = [dateKey(a), dateKey(b)];
            if (va < vb) return sort.asc ? -1 : 1;
            if (va > vb) return sort.asc ? 1 : -1;
            // Equal values (one date) — by order number in the same direction.
            return sort.asc ? numOf(a) - numOf(b) : numOf(b) - numOf(a);
        });
});

const ATTRS = ['Основа', 'Цвет', 'Патина', 'Блеск', 'Ткань'];
const linesByOrder = computed(() => {
    const map = new Map<number, ReportLine[]>();
    (props.report?.lines ?? []).forEach((l) => map.set(l.order_id, [...(map.get(l.order_id) ?? []), l]));
    return map;
});
const rows = computed(() =>
    orders.value.flatMap((o) => {
        const lines = linesByOrder.value.get(o.id) ?? [];
        const note = props.report?.notes[o.id];
        return lines.map((l, idx) => {
            const attrs = (l.attrs || {}) as Record<string, string>;
            const get = (k: string) => (Array.isArray(l.attrs) ? '' : attrs[k] || '');
            return {
                key: `${o.id}-${idx}`,
                first: idx === 0,
                orderNum: idx === 0 ? o.num : '',
                orderDate: idx === 0 ? o.created || o.date || '' : '',
                customer: idx === 0 ? customer(o) : '',
                lineNo: idx + 1,
                name: l.name,
                qty: l.qty,
                osnova: get('Основа'),
                color: get('Цвет'),
                patina: get('Патина'),
                blesk: get('Блеск'),
                tkan: get('Ткань'),
                prochee: Object.entries(attrs)
                    .filter(([k]) => !ATTRS.includes(k))
                    .map(([k, v]) => k + ': ' + v)
                    .join('; '),
                comment: idx === 0 ? note?.comment || '' : '',
                delivery: idx === 0 ? note?.delivery || '' : '',
            };
        });
    }),
);

// «📥 Выгрузить в Excel» (legacy exportOrderReport, L36689; ADR-0044): the orders of the filters and the sort of the page, a line
// per order line, the order's number, date, customer, comment and delivery on its first line, an empty line after an order of
// several lines. Legacy titled the file with the period of a window nobody opens — always «Все заказы»; here it is the period
// of the page (ADR-0002, item 129). The unit is «шт»: the lines of legacy have none.
function exportOrderReport(): void {
    if (!orders.value.length) return void window.alert('Нет заказов за выбранный период.');
    const header = ['№ заказа', 'Дата заказа', 'Заказчик', '№ строки', 'Номенклатура', 'Кол-во', 'Ед.', ...ATTRS, 'Прочее', 'Комментарий', 'Дата доставки'];
    const byOrder = new Map<string, typeof rows.value>();
    rows.value.forEach((r) => {
        const id = r.key.split('-')[0];
        byOrder.set(id, [...(byOrder.get(id) ?? []), r]);
    });
    const lines: (string | number)[][] = [];
    orders.value.forEach((o) => {
        const own = byOrder.get(String(o.id)) ?? [];
        // the file, unlike the page, falls back to the due date when there is no delivery date (legacy deliveryDate || date)
        own.forEach((r) => lines.push([r.orderNum, r.orderDate, r.customer, r.lineNo, r.name, r.qty, 'шт', r.osnova, r.color, r.patina, r.blesk, r.tkan, r.prochee, r.comment, r.first ? r.delivery || o.date || '' : '']));
        if (own.length > 1) lines.push(Array(header.length).fill(''));
    });
    const label = f.period === 'range' ? `${f.from} — ${f.to}` : f.period === 'month' ? `${MN[+month.value.split('-')[1] - 1]} ${month.value.split('-')[0]}` : 'Все заказы';
    void downloadXlsx('sales', `PromTech_Zakazy_${label.replace(/[^а-яёА-ЯЁa-zA-Z0-9\-_]/g, '_')}.xlsx`, [
        {
            name: 'Заказы',
            rows: [[`ОТЧЁТ ПО ЗАКАЗАМ — PromTech · ${label} · Заказов: ${orders.value.length}`], [], header, ...lines],
            cols: [12, 12, 26, 8, 28, 8, 6, 14, 16, 12, 12, 16, 20, 28, 14],
        },
    ]);
}
</script>

<template>
    <!-- Фильтры -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px">
        <div>
            <label class="label">Период</label>
            <select id="rep-period-type" v-model="f.period" style="font-size: 13px; width: auto">
                <option value="all">Все заказы</option>
                <option value="month">Месяц</option>
                <option value="range">Период</option>
            </select>
        </div>
        <div id="rep-month-wrap" :style="{ display: f.period === 'month' ? '' : 'none' }">
            <label class="label">Месяц</label>
            <select id="rep-month-select" :value="month" style="font-size: 13px; width: auto" @change="Object.assign(f, { month: ($event.target as HTMLSelectElement).value, period: 'month' })">
                <option v-for="v in months" :key="v" :value="v">{{ MN[+v.split('-')[1] - 1] }} {{ v.split('-')[0] }}</option>
            </select>
        </div>
        <div id="rep-range-wrap" style="gap: 8px; align-items: flex-end" :style="{ display: f.period === 'range' ? 'flex' : 'none' }">
            <div><label class="label">С</label><input id="rep-date-from" v-model="f.from" type="date" style="width: 140px" @change="f.period = 'range'" /></div>
            <div><label class="label">По</label><input id="rep-date-to" v-model="f.to" type="date" style="width: 140px" @change="f.period = 'range'" /></div>
        </div>
        <div>
            <label class="label">Статус</label>
            <select id="rep-status-filter" v-model="f.status" style="font-size: 13px; width: auto">
                <option value="all">Все</option>
                <option value="queue">В очереди</option>
                <option value="progress">В работе</option>
                <option value="done">Завершённые</option>
            </select>
        </div>
        <div style="margin-left: auto; display: flex; gap: 8px; align-items: flex-end">
            <div id="rep-count" style="font-size: 12px; color: var(--text3); padding-bottom: 6px">{{ props.report ? `${orders.length} заказов · ${rows.length} позиций` : '' }}</div>
            <button class="btn btn-primary btn-sm" @click="exportOrderReport">📥 Выгрузить в Excel</button>
        </div>
    </div>

    <!-- Таблица отчёта -->
    <div style="overflow: auto; max-height: calc(100vh - 280px)">
        <table id="rep-table" class="data-table">
            <thead>
                <tr>
                    <th style="cursor: pointer; user-select: none" @click="sortReport('num')">№ заказа <span>{{ icon('num') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortReport('date')">Дата заказа <span>{{ icon('date') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortReport('customer')">Заказчик <span>{{ icon('customer') }}</span></th>
                    <th>№</th>
                    <th>Номенклатура</th>
                    <th>Кол-во</th>
                    <th>Ед.</th>
                    <th>Основа</th>
                    <th>Цвет</th>
                    <th>Патина</th>
                    <th>Блеск</th>
                    <th>Ткань</th>
                    <th>Прочее</th>
                    <th>Комментарий</th>
                    <th>Дата доставки</th>
                </tr>
            </thead>
            <tbody id="rep-tbody">
                <tr v-if="props.report && !rows.length">
                    <td colspan="15" style="text-align: center; color: var(--text3); padding: 24px">Нет заказов за выбранный период</td>
                </tr>
                <tr v-for="r in rows" :key="r.key" :style="r.first ? 'border-top:2px solid var(--border);background:var(--bg3)' : ''">
                    <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--accent); white-space: nowrap">{{ r.orderNum }}</td>
                    <td style="font-size: 12px; white-space: nowrap">{{ r.orderDate }}</td>
                    <td style="font-size: 12px" :style="{ fontWeight: r.first ? 600 : 400 }">{{ r.customer }}</td>
                    <td style="text-align: center; color: var(--text3); font-size: 12px">{{ r.lineNo }}</td>
                    <td style="font-size: 13px; font-weight: 500">{{ r.name }}</td>
                    <td style="text-align: center; font-family: 'IBM Plex Mono', monospace; font-size: 12px">{{ r.qty }}</td>
                    <td style="text-align: center; font-size: 12px; color: var(--text3)">шт</td>
                    <td style="font-size: 12px">{{ r.osnova }}</td>
                    <td style="font-size: 12px">{{ r.color }}</td>
                    <td style="font-size: 12px">{{ r.patina }}</td>
                    <td style="font-size: 12px">{{ r.blesk }}</td>
                    <td style="font-size: 12px">{{ r.tkan }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ r.prochee }}</td>
                    <td style="font-size: 12px; font-style: italic; color: var(--text2)">{{ r.comment }}</td>
                    <td style="font-size: 12px; white-space: nowrap; color: var(--accent)">{{ r.delivery }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
