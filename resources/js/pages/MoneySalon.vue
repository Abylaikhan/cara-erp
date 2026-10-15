<script setup lang="ts">
// M15 «Касса салона», part 1 — legacy page-money_salon (L7064–7137), renderSalonMoney/reloadSalonMoney/smSort
// (L15576–15725). The operations of the salon's cash: period, flow and search filters, sorting, balance over all operations,
// totals of the period, money handed to the office, the running balance. Periods are calendar dates of the business time zone
// (legacy ended «Текущий месяц» and «Квартал» on the UTC date). «📥 Приход» posts money and says when it comes.
import { router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { catLabel, type Categories } from '@/lib/money';
import { fmtCurrency } from '@/lib/sales';

interface Tx {
    id: number;
    cash: number;
    date: string;
    flow: 'income' | 'expense';
    category: string;
    amount: string;
    desc: string;
    orders: string[];
    esf: string | null;
}
const props = defineProps<{ title: string; canCashIn: boolean; today: string; transactions: Tx[]; categories: Categories }>();

const f = reactive({ period: 'all', from: '', to: '', flow: '', search: '' });
const sort = reactive({ col: 'date' as 'date' | 'desc' | 'income' | 'expense', asc: false });
function smSort(col: typeof sort.col): void {
    if (sort.col === col) sort.asc = !sort.asc;
    else Object.assign(sort, { col, asc: col !== 'date' });
}
const arrow = (col: string) => (col === sort.col ? (sort.asc ? '▲' : '▼') : '');
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const fmtS = (n: number) => (n >= 1000000 ? '₸' + (n / 1000000).toFixed(1) + 'М' : n >= 1000 ? '₸' + Math.round(n / 1000) + 'К' : '₸' + Math.round(n));
const amount = (t: Tx) => Number(t.amount);

const range = computed(() => {
    const [y, m] = props.today.split('-').map(Number);
    const pad = (n: number) => String(n).padStart(2, '0');
    if (f.period === 'month') return { from: `${y}-${pad(m)}-01`, to: props.today };
    if (f.period === 'quarter') return { from: `${y}-${pad(Math.floor((m - 1) / 3) * 3 + 1)}-01`, to: props.today };
    if (f.period === 'year') return { from: `${y}-01-01`, to: `${y}-12-31` };
    if (f.period === 'custom') return { from: f.from, to: f.to };
    return { from: '', to: '' };
});
const filtered = computed(() => {
    const s = f.search.toLowerCase();
    const { from, to } = range.value;
    const list = props.transactions.filter(
        (t) =>
            (!from || t.date >= from) &&
            (!to || t.date <= to) &&
            (!f.flow || t.flow === f.flow) &&
            (!s || (t.desc || '').toLowerCase().includes(s) || t.orders.some((o) => o.toLowerCase().includes(s))),
    );
    const val = (t: Tx): string | number =>
        sort.col === 'date' ? t.date : sort.col === 'income' ? (t.flow === 'income' ? amount(t) : 0) : sort.col === 'expense' ? (t.flow === 'expense' ? amount(t) : 0) : t.desc || '';
    return [...list].sort((a, b) => {
        const va = val(a),
            vb = val(b);
        if (typeof va === 'number') return sort.asc ? va - (vb as number) : (vb as number) - va;
        return sort.asc ? va.localeCompare(vb as string) : (vb as string).localeCompare(va);
    });
});
// KPI over all operations; the period totals over the filtered ones.
const sum = (list: Tx[], flow: string) => list.filter((t) => t.flow === flow).reduce((s, t) => s + amount(t), 0);
const balance = computed(() => sum(props.transactions, 'income') - sum(props.transactions, 'expense'));
const sentToOffice = computed(() =>
    props.transactions.filter((t) => t.flow === 'expense' && ((t.desc || '').toLowerCase().includes('офис') || t.category === 'transfer')).reduce((s, t) => s + amount(t), 0),
);
// Running balance from the first operation (stable sort by date keeps the loaded order inside a day).
const running = computed(() => {
    const map = new Map<number, number>();
    let r = 0;
    [...props.transactions].sort((a, b) => a.date.localeCompare(b.date)).forEach((t) => map.set(t.id, (r += t.flow === 'income' ? amount(t) : -amount(t))));
    return map;
});
const cashIn = () => window.alert('Приход в кассу салона появится вместе с проведением денег (ERP-031…033, ERP-051, решения D-03 и D-11).');
</script>

<template>
    <ErpLayout>
        <div id="page-money_salon" class="page active">
            <div class="section-header">
                <div>
                    <div id="salon-money-title" class="section-title">{{ props.title }}</div>
                    <div class="section-sub">Движение денег · только своя касса</div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center">
                    <button class="btn btn-ghost btn-sm" @click="router.reload()">🔄</button>
                    <button id="salon-cash-in-btn" class="btn btn-primary btn-sm" :style="props.canCashIn ? undefined : { display: 'none' }" @click="cashIn">📥 Приход</button>
                </div>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center">
                <select id="sm-period" v-model="f.period" style="font-size: 13px">
                    <option value="all">Всё время</option>
                    <option value="month">Текущий месяц</option>
                    <option value="quarter">Квартал</option>
                    <option value="year">Текущий год</option>
                    <option value="custom">Период...</option>
                </select>
                <div id="sm-custom-dates" :style="{ display: f.period === 'custom' ? 'flex' : 'none', gap: '6px', alignItems: 'center' }">
                    <input id="sm-date-from" v-model="f.from" type="date" style="font-size: 13px" />
                    <span style="color: var(--text3)">—</span>
                    <input id="sm-date-to" v-model="f.to" type="date" style="font-size: 13px" />
                </div>
                <select id="sm-flow" v-model="f.flow" style="font-size: 13px">
                    <option value="">Все операции</option>
                    <option value="income">📥 Приход</option>
                    <option value="expense">📤 Расход</option>
                </select>
                <input id="sm-search" v-model="f.search" type="search" placeholder="🔍 Поиск..." style="font-size: 13px; width: 180px" />
                <span id="sm-count" style="font-size: 12px; color: var(--text3)"
                    >{{ filtered.length }} операций <span style="color: var(--green); margin-left: 8px">▲{{ fmtS(sum(filtered, 'income')) }}</span>{{ ' ' }}
                    <span style="color: var(--red); margin-left: 6px">▼{{ fmtS(sum(filtered, 'expense')) }}</span></span
                >
            </div>

            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Остаток в кассе</div>
                    <div id="salon-cash-balance" style="font-size: 22px; font-weight: 700; color: var(--green)">{{ fmt(balance) }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Поступления за период</div>
                    <div id="salon-income" style="font-size: 20px; font-weight: 700; color: var(--green)">{{ fmt(sum(filtered, 'income')) }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Расходы за период</div>
                    <div id="salon-expense" style="font-size: 20px; font-weight: 700; color: var(--red)">{{ fmt(sum(filtered, 'expense')) }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Передано в офис</div>
                    <div id="salon-sent-to-office" style="font-size: 20px; font-weight: 700; color: var(--accent)">{{ fmt(sentToOffice) }}</div>
                </div>
            </div>

            <div class="card" style="padding: 0; margin-bottom: 16px">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="cursor: pointer" @click="smSort('date')">Дата <span id="sm-sort-date">{{ arrow('date') }}</span></th>
                            <th>Тип</th>
                            <th>Категория</th>
                            <th style="cursor: pointer" @click="smSort('desc')">Описание <span id="sm-sort-desc">{{ arrow('desc') }}</span></th>
                            <th style="text-align: right; color: var(--green)" @click="smSort('income')">Приход <span id="sm-sort-income">{{ arrow('income') }}</span></th>
                            <th style="text-align: right; color: var(--red)" @click="smSort('expense')">Расход <span id="sm-sort-expense">{{ arrow('expense') }}</span></th>
                            <th style="text-align: right">Остаток</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="salon-cash-tbody">
                        <tr v-for="t in filtered" :key="t.id" v-memo="[t, running]">
                            <td style="font-size: 12px; font-family: monospace">{{ t.date }}</td>
                            <td>
                                <span class="badge" :class="t.flow === 'income' ? 'badge-green' : 'badge-red'">{{ t.flow === 'income' ? '📥' : '📤' }}</span>
                            </td>
                            <td style="font-size: 11px">{{ catLabel(props.categories, t.category) }}</td>
                            <td style="font-size: 12px">
                                {{ t.desc || '—'
                                }}<template v-for="o in t.orders" :key="o">{{ ' ' }}<span style="color: var(--accent); font-size: 11px">({{ o }})</span></template
                                ><template v-if="t.esf"
                                    >{{ ' ' }}<span class="badge badge-green" style="font-size: 10px" :title="'Привязано к ЭСФ на ' + fmtCurrency(Number(t.esf))">🔗 ЭСФ</span></template
                                >
                            </td>
                            <td style="text-align: right; font-family: monospace; color: var(--green)">{{ t.flow === 'income' ? fmt(amount(t)) : '—' }}</td>
                            <td style="text-align: right; font-family: monospace; color: var(--red)">{{ t.flow === 'expense' ? fmt(amount(t)) : '—' }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600">{{ fmt(running.get(t.id) ?? 0) }}</td>
                            <td></td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="8" style="text-align: center; padding: 20px; color: var(--text3)">Нет операций</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </ErpLayout>
</template>
