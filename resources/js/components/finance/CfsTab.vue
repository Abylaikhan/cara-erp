<script setup lang="ts">
// Tab «ДДС» of «Финансы» — legacy finance-cfs (L5833–5862), buildCFS/renderCFS (L23444–23625), ddCFSSection (L33059).
// Sections by category, opening and closing balance, accounts of the period; a section opens its operations. Amounts are in
// tenge; the opening balance counts the opening balances of the accounts; «Всё время» is all the operations (legacy fell back
// to 2026). Transfers between own accounts are not movement of money.
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DrilldownModal from '@/components/DrilldownModal.vue';

export interface CfsTx {
    id: number;
    date: string;
    account: number;
    flow: 'income' | 'expense';
    category: string;
    amount: string;
    desc: string | null;
}
const props = defineProps<{
    transactions: CfsTx[] | undefined;
    accounts: { id: number; name: string; icon: string | null; opening: string }[];
    categoryNames: Record<string, string>;
    txLabels: Record<string, string>;
    from: string | null;
    to: string | null;
    periodLabel: string;
    active: boolean;
}>();
watch(
    () => props.active,
    (a) => {
        if (a && props.transactions === undefined) router.reload({ only: ['transactions'] });
    },
    { immediate: true },
);

const ALL_CATS: Record<string, string> = {
    order_payment: 'Поступления от клиентов', return: 'Возвраты от поставщиков', advance_return: 'Возврат авансов сотрудниками',
    supplier: 'Оплата поставщикам', salary: 'Выплата заработной платы', tax: 'Налоги и обязательные платежи', advance_out: 'Выдача авансов сотрудникам',
    loan_in: 'Получение займов', loan_out: 'Погашение займов', rent: 'Аренда', utility: 'Коммунальные услуги', transport: 'Транспорт и доставка',
    other_in: 'Прочие поступления', other_out: 'Прочие выплаты', dividend: 'Дивиденды',
};
const SECTIONS = [
    { key: 'operating', label: '🏭 Операционная деятельность', color: 'var(--accent)', inCats: ['order_payment', 'return', 'advance_return'], outCats: ['supplier', 'salary', 'tax', 'advance_out', 'other_out'] },
    { key: 'investing', label: '🏗 Инвестиционная деятельность', color: 'var(--purple)', inCats: ['asset_sale'], outCats: ['equipment', 'asset_buy'] },
    { key: 'financing', label: '💳 Финансовая деятельность', color: 'var(--amber)', inCats: ['loan_in'], outCats: ['loan_out', 'dividend'] },
    { key: 'other', label: '📋 Прочее', color: 'var(--text3)', inCats: ['other_in'], outCats: ['utility', 'rent', 'transport'] },
];
const TRANSFERS = ['transfer', 'transfer_in', 'transfer_out'];
const cfsLabel = (code: string) => ALL_CATS[code] || props.categoryNames[code] || code;
const inPeriod = (t: CfsTx) => (!props.from || t.date >= props.from) && (!props.to || t.date <= props.to);

interface Row { label: string; in: number; out: number }
const cfs = computed(() => {
    const txs = props.transactions ?? [];
    const period = txs.filter(inPeriod);
    const openBalance = props.accounts.reduce(
        (s, a) => s + Number(a.opening) + txs.filter((t) => t.account === a.id && props.from && t.date < props.from).reduce((s2, t) => s2 + (t.flow === 'income' ? 1 : -1) * Number(t.amount), 0),
        0,
    );
    let totalIn = 0, totalOut = 0;
    const used = new Set<number>();
    const sections = SECTIONS.map((sec) => {
        const rows = new Map<string, Row>();
        period.forEach((t) => {
            if (TRANSFERS.includes(t.category)) return;
            const isIn = t.flow === 'income' && sec.inCats.includes(t.category);
            const isOut = t.flow === 'expense' && sec.outCats.includes(t.category);
            if (!isIn && !isOut) return;
            used.add(t.id);
            const row = rows.get(t.category) ?? { label: cfsLabel(t.category), in: 0, out: 0 };
            if (isIn) row.in += Number(t.amount);
            if (isOut) row.out += Number(t.amount);
            rows.set(t.category, row);
        });
        const secIn = [...rows.values()].reduce((s, r) => s + r.in, 0);
        const secOut = [...rows.values()].reduce((s, r) => s + r.out, 0);
        totalIn += secIn;
        totalOut += secOut;
        return { ...sec, rows, secIn, secOut };
    });
    const other = sections[3];
    period
        .filter((t) => !used.has(t.id) && !TRANSFERS.includes(t.category))
        .forEach((t) => {
            const cat = t.category || 'other';
            const row = other.rows.get(cat) ?? { label: cfsLabel(cat), in: 0, out: 0 };
            if (t.flow === 'income') {
                row.in += Number(t.amount);
                other.secIn += Number(t.amount);
                totalIn += Number(t.amount);
            } else {
                row.out += Number(t.amount);
                other.secOut += Number(t.amount);
                totalOut += Number(t.amount);
            }
            other.rows.set(cat, row);
        });
    const byAccount = props.accounts
        .map((a) => {
            const p = period.filter((t) => t.account === a.id);
            return { ...a, in: p.filter((t) => t.flow === 'income').reduce((s, t) => s + Number(t.amount), 0), out: p.filter((t) => t.flow === 'expense').reduce((s, t) => s + Number(t.amount), 0) };
        })
        .filter((a) => a.in !== 0 || a.out !== 0);
    return { openBalance, totalIn, totalOut, netFlow: totalIn - totalOut, closeBalance: openBalance + totalIn - totalOut, sections, byAccount };
});
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');

const dd = ref<InstanceType<typeof DrilldownModal> | null>(null);
function ddCFSSection(inCats: string[], outCats: string[], label: string): void {
    const txs = (props.transactions ?? []).filter((t) => inPeriod(t) && (inCats.includes(t.category) || outCats.includes(t.category)));
    dd.value?.openDrilldown(
        'ДДС: ' + label,
        props.periodLabel + ' · ' + txs.length + ' транзакций',
        txs.map((t) => ({
            date: t.date || '—',
            desc: t.desc || '—',
            cat: props.txLabels[t.category] ?? t.category,
            flow: t.flow === 'income' ? 'Поступление' : 'Выплата',
            amount: t.flow === 'income' ? Number(t.amount) : -Number(t.amount),
        })),
        [
            { key: 'date', label: 'Дата' },
            { key: 'desc', label: 'Описание' },
            { key: 'cat', label: 'Категория' },
            { key: 'flow', label: 'Вид' },
            { key: 'amount', label: 'Сумма', num: true, color: 'var(--accent)' },
        ],
    );
}
const excelLater = () => window.alert('Выгрузка в Excel появится вместе с остальными выгрузками (ERP-067).');
const td = 'padding:7px 16px;text-align:right;font-family:monospace';
</script>

<template>
    <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap">
        <div style="font-size: 12px; color: var(--text3)">📅 Период задаётся глобальным фильтром выше</div>
        <div style="flex: 1"></div>
        <button class="btn btn-ghost btn-sm" @click="excelLater">📥 Excel</button>
    </div>
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
        <div class="card" style="padding: 14px 16px">
            <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Остаток на начало</div>
            <div id="cfs-kpi-open" style="font-size: 20px; font-weight: 700">{{ props.transactions ? fmt(cfs.openBalance) : '₸0' }}</div>
        </div>
        <div class="card" style="padding: 14px 16px">
            <div style="font-size: 11px; color: var(--green); margin-bottom: 4px">Всего поступлений</div>
            <div id="cfs-kpi-in" style="font-size: 20px; font-weight: 700; color: var(--green)">{{ fmt(cfs.totalIn) }}</div>
        </div>
        <div class="card" style="padding: 14px 16px">
            <div style="font-size: 11px; color: var(--red); margin-bottom: 4px">Всего выплат</div>
            <div id="cfs-kpi-out" style="font-size: 20px; font-weight: 700; color: var(--red)">{{ fmt(cfs.totalOut) }}</div>
        </div>
        <div class="card" style="padding: 14px 16px">
            <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Остаток на конец</div>
            <div id="cfs-kpi-close" style="font-size: 20px; font-weight: 700">{{ props.transactions ? fmt(cfs.closeBalance) : '₸0' }}</div>
        </div>
    </div>
    <div id="cfs-body">
        <template v-if="props.transactions">
            <div class="card" style="padding: 0; overflow: hidden">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px">
                    <thead>
                        <tr style="background: var(--bg3)">
                            <th style="padding: 10px 16px; text-align: left; font-size: 11px; color: var(--text3)">Статья</th>
                            <th style="padding: 10px 16px; text-align: right; font-size: 11px; color: var(--green)">Поступления</th>
                            <th style="padding: 10px 16px; text-align: right; font-size: 11px; color: var(--red)">Выплаты</th>
                            <th style="padding: 10px 16px; text-align: right; font-size: 11px">Нетто</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: var(--bg3); font-weight: 700">
                            <td style="padding: 10px 16px">💰 Остаток на начало периода</td>
                            <td colspan="2"></td>
                            <td style="padding: 10px 16px; text-align: right; font-family: monospace; font-size: 14px">{{ fmt(cfs.openBalance) }}</td>
                        </tr>
                        <template v-for="sec in cfs.sections" :key="sec.key">
                            <template v-if="sec.secIn !== 0 || sec.secOut !== 0">
                                <tr style="background: var(--bg2); border-top: 2px solid var(--border); cursor: pointer" title="Нажмите для детализации" @click="ddCFSSection(sec.inCats, sec.outCats, sec.label)">
                                    <td :style="{ padding: '10px 16px', fontWeight: 700, color: sec.color }">{{ sec.label }} <span style="font-size: 10px; color: var(--accent)">🔍</span></td>
                                    <td style="padding: 10px 16px; text-align: right; font-family: monospace; color: var(--green); font-weight: 600">{{ sec.secIn ? fmt(sec.secIn) : '' }}</td>
                                    <td style="padding: 10px 16px; text-align: right; font-family: monospace; color: var(--red); font-weight: 600">{{ sec.secOut ? '−' + fmt(sec.secOut) : '' }}</td>
                                    <td :style="{ padding: '10px 16px', textAlign: 'right', fontFamily: 'monospace', fontWeight: 700, color: sec.secIn - sec.secOut >= 0 ? 'var(--green)' : 'var(--red)' }">
                                        {{ sec.secIn - sec.secOut >= 0 ? '+' : '' }}{{ fmt(sec.secIn - sec.secOut) }}
                                    </td>
                                </tr>
                                <template v-for="[cat, row] in sec.rows" :key="cat">
                                    <tr v-if="row.in !== 0 || row.out !== 0" style="border-top: 1px solid var(--border)">
                                        <td style="padding: 7px 16px 7px 32px; color: var(--text2)">{{ row.label }}</td>
                                        <td :style="td + ';color:var(--green)'">{{ row.in ? fmt(row.in) : '—' }}</td>
                                        <td :style="td + ';color:var(--red)'">{{ row.out ? '−' + fmt(row.out) : '—' }}</td>
                                        <td :style="td + ';color:var(--text3)'">{{ fmt(row.in - row.out) }}</td>
                                    </tr>
                                </template>
                            </template>
                        </template>
                        <tr style="background: var(--bg3); border-top: 2px solid var(--border); font-weight: 700">
                            <td style="padding: 10px 16px">📊 Чистый денежный поток</td>
                            <td style="padding: 10px 16px; text-align: right; font-family: monospace; color: var(--green)">{{ fmt(cfs.totalIn) }}</td>
                            <td style="padding: 10px 16px; text-align: right; font-family: monospace; color: var(--red)">−{{ fmt(cfs.totalOut) }}</td>
                            <td :style="{ padding: '10px 16px', textAlign: 'right', fontFamily: 'monospace', fontSize: '14px', color: cfs.netFlow >= 0 ? 'var(--green)' : 'var(--red)' }">
                                {{ cfs.netFlow >= 0 ? '+' : '' }}{{ fmt(cfs.netFlow) }}
                            </td>
                        </tr>
                        <tr style="background: var(--accent); color: #fff; font-weight: 700">
                            <td style="padding: 12px 16px">💰 Остаток на конец периода</td>
                            <td colspan="2"></td>
                            <td style="padding: 12px 16px; text-align: right; font-family: monospace; font-size: 16px">{{ fmt(cfs.closeBalance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 14px">
                <div style="font-weight: 600; font-size: 13px; margin-bottom: 8px">По счетам и кассам:</div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px">
                    <div v-for="a in cfs.byAccount" :key="a.id" class="card" style="padding: 12px">
                        <div style="font-weight: 600; font-size: 12px; margin-bottom: 6px">{{ a.icon || '' }} {{ a.name }}</div>
                        <div style="display: flex; justify-content: space-between; font-size: 12px">
                            <span style="color: var(--green)">↑ {{ fmt(a.in) }}</span>
                            <span style="color: var(--red)">↓ {{ fmt(a.out) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
    <DrilldownModal ref="dd" />
</template>
