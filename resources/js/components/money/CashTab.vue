<script setup lang="ts">
// Tab «Касса» of «Деньги» — legacy money-tab-cash (L6071–6140), renderCashTx/sortCashTx (L9592, L9677–9805) and «Выдано по
// сотрудникам» cashCpRows/showCashByCounterparty/cashCpPick (L9629–9672). Same cards, totals, filters, table and running
// balance; the categories of the filter are those of the chosen cash desk (legacy kept the list of the first one). Reference
// c54cf25: the balance over the whole history, «👥 Кому выдано» for salary expenses of the salary cash, «🔁 Сверка переводов».
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ZpReconModal from '@/components/money/ZpReconModal.vue';
import { downloadXlsx } from '@/lib/xlsx';
import { accountStats, catLabel, fmt, fmtShort, hasPeriod, inPeriod, LATER, openingOf, type Categories, type MoneyAccount, type MoneyTx } from '@/lib/money';

const props = defineProps<{ accounts: MoneyAccount[]; txs: MoneyTx[]; categories: Categories }>();

const cash = computed(() => props.accounts.filter((a) => a.type === 'cash'));
const cashId = ref<number>(cash.value[0]?.id ?? 0);
const f = reactive({ search: '', flow: 'all', cat: 'all' });
const sort = reactive({ col: 'date', asc: false });
watch(cashId, () => (f.cat = 'all'));

function sortCashTx(col: string): void {
    if (sort.col === col) sort.asc = !sort.asc;
    else Object.assign(sort, { col, asc: col !== 'date' });
}
const icon = (col: string) => (col === sort.col ? (sort.asc ? '▲' : '▼') : '');

const stats = computed(() => accountStats(props.txs, cashId.value));
const openBal = computed(() => openingOf(props.txs, cashId.value));
const balance = computed(() => (hasPeriod() ? openBal.value + stats.value.income - stats.value.expense : stats.value.income - stats.value.expense));
const cats = computed(() => [...new Set(props.txs.filter((t) => t.account === cashId.value).map((t) => t.category))].sort());

const rows = computed(() => {
    const search = f.search.toLowerCase();
    const list = props.txs.filter(
        (t) =>
            t.account === cashId.value &&
            inPeriod(t) &&
            (f.flow === 'all' || t.flow === f.flow) &&
            (f.cat === 'all' || t.category === f.cat) &&
            (!search || t.ctr.toLowerCase().includes(search) || t.desc.toLowerCase().includes(search) || t.order.toLowerCase().includes(search)),
    );
    const key = (t: MoneyTx): string | number => {
        if (sort.col === 'cat') return catLabel(props.categories, t.category);
        if (sort.col === 'ctr') return t.ctr;
        if (sort.col === 'income') return t.flow === 'income' ? t.amount : 0;
        if (sort.col === 'expense') return t.flow === 'expense' ? t.amount : 0;
        return t.date;
    };
    return [...list].sort((a, b) => {
        const va = key(a);
        const vb = key(b);
        if (typeof va === 'number' && typeof vb === 'number') return sort.asc ? va - vb : vb - va;
        return sort.asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
    });
});
// Running balance (legacy renderCashTx, c699dea): the balance of the cash desk after each operation over its whole history —
// by date, within a day income before expense, then later-loaded first — so it does not depend on the sort, the filters or
// the period.
const balanceAfter = computed(() => {
    const map = new Map<MoneyTx, number>();
    let acc = 0;
    props.txs
        .map((t, i) => ({ t, i }))
        .filter((x) => x.t.account === cashId.value)
        .sort((a, b) => a.t.date.slice(0, 10).localeCompare(b.t.date.slice(0, 10)) || (a.t.flow === 'income' ? 0 : 1) - (b.t.flow === 'income' ? 0 : 1) || b.i - a.i)
        .forEach((x) => {
            acc += x.t.flow === 'income' ? x.t.amount : -x.t.amount;
            map.set(x.t, acc);
        });
    return map;
});
const sums = computed(() => ({
    in: rows.value.filter((t) => t.flow === 'income').reduce((s, t) => s + t.amount, 0),
    out: rows.value.filter((t) => t.flow === 'expense').reduce((s, t) => s + t.amount, 0),
}));

// ── «Выдано по сотрудникам» ──
const cp = ref(false);
const cpRows = computed(() => {
    const by: Record<string, { name: string; out: number; inc: number; n: number; last: string }> = {};
    props.txs
        .filter((t) => t.account === cashId.value && inPeriod(t) && (f.cat === 'all' || t.category === f.cat))
        .forEach((t) => {
            const k = t.ctr.trim() || '— без контрагента —';
            const r = (by[k] ??= { name: k, out: 0, inc: 0, n: 0, last: '' });
            if (t.flow === 'expense') r.out += t.amount;
            else r.inc += t.amount;
            r.n++;
            if (t.date > r.last) r.last = t.date;
        });
    return Object.values(by).sort((a, b) => b.out - a.out || a.name.localeCompare(b.name, 'ru'));
});
// Legacy exportCashCp (L9820), ADR-0044.
function exportCashCp(): void {
    void downloadXlsx('money', 'Касса_свод_по_контрагентам.xlsx', [
        { name: 'Свод', rows: [['Контрагент', 'Операций', 'Последняя', 'Выдано', 'Возврат/приход'], ...cpRows.value.map((r) => [r.name, r.n, r.last, r.out, r.inc])], cols: [38, 10, 12, 14, 16] },
    ]);
}
const cashName = computed(() => {
    const a = cash.value.find((x) => x.id === cashId.value);
    return a ? `${a.icon || '💵'} ${a.name}` : 'касса';
});
function cashCpPick(name: string): void {
    f.search = name.startsWith('—') ? '' : name;
    cp.value = false;
}
const later = (text: string) => () => window.alert(text);
const zr = ref(false);
// ── «👥 Кому выдано» (legacy txHasPayoutBreakdown/txPayoutsFor/showTxPayouts, c54cf25): only for salary expenses of the salary
// cash desk (not the salons'); the server finds the payroll payments linked to the expense ──
function hasPayouts(t: MoneyTx): boolean {
    if (t.flow !== 'expense') return false;
    const acc = props.accounts.find((a) => a.id === t.account);
    if (!acc || acc.type !== 'cash') return false;
    const nm = (acc.name || '') + ' ' + (acc.bank || '');
    if (!/зп|зарплат/i.test(nm) || /салон/i.test(nm)) return false;
    return /^salary/i.test(t.category || '') && t.category !== 'salary_transfer';
}
interface Payouts {
    how: 'id' | 'num' | 'sum' | 'date';
    total: number;
    rows: { emp_name: string; comment: string; amount: number }[];
}
const pay = reactive<{ open: boolean; tx: MoneyTx | null; res: Payouts | null; error: string }>({ open: false, tx: null, res: null, error: '' });
async function showTxPayouts(t: MoneyTx): Promise<void> {
    Object.assign(pay, { open: true, tx: t, res: null, error: '' });
    try {
        const r = await fetch(`/money/payouts/${t.id}`, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error(`${r.status} ${r.statusText}`);
        if (pay.tx === t) pay.res = (await r.json()) as Payouts;
    } catch (e) {
        if (pay.tx === t) pay.error = (e as Error).message;
    }
}
const payRows = computed(() => (pay.res ? [...pay.res.rows].sort((a, b) => String(a.emp_name).localeCompare(String(b.emp_name), 'ru')) : []));
const payDiff = computed(() => (pay.tx && pay.res ? (pay.tx.amount || 0) - pay.res.total : 0));

</script>

<template>
    <!-- Сводка по всем кассам -->
    <div id="cash-summary-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin-bottom: 14px">
        <div v-for="acc in cash" :key="acc.id" class="card" style="padding: 12px 14px; cursor: pointer; border-left: 3px solid var(--accent)" @click="cashId = acc.id">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px">
                <div style="font-size: 12px; font-weight: 600">{{ acc.icon || '💵' }} {{ acc.name }}</div>
            </div>
            <div style="font-size: 18px; font-weight: 700; color: var(--accent)">{{ fmt(accountStats(props.txs, acc.id).balance) }}</div>
            <div style="font-size: 11px; color: var(--text3); margin-top: 4px">
                <span style="color: var(--green)">↑{{ fmt(accountStats(props.txs, acc.id).income) }}</span>
                &nbsp;
                <span style="color: var(--red)">↓{{ fmt(accountStats(props.txs, acc.id).expense) }}</span>
            </div>
        </div>
    </div>

    <!-- Выбор кассы + кнопки -->
    <div style="display: flex; gap: 10px; margin-bottom: 12px; align-items: center; flex-wrap: wrap">
        <select id="cash-account-select" v-model="cashId" style="font-size: 13px; width: auto">
            <option v-for="a in cash" :key="a.id" :value="a.id">{{ a.icon || '💵' }} {{ a.name }}</option>
        </select>
        <div class="card" style="padding: 10px 16px; display: flex; gap: 14px; align-items: center; flex: 1">
            <div>
                <div style="font-size: 10px; color: var(--text3)">Нач. остаток</div>
                <div id="cash-open-balance" style="font-weight: 700">{{ fmt(openBal) }}</div>
            </div>
            <div style="color: var(--text3)">+</div>
            <div>
                <div style="font-size: 10px; color: var(--green)">Приход</div>
                <div id="cash-income" style="font-weight: 700; color: var(--green)">{{ fmt(stats.income) }}</div>
            </div>
            <div style="color: var(--text3)">−</div>
            <div>
                <div style="font-size: 10px; color: var(--red)">Расход</div>
                <div id="cash-expense" style="font-weight: 700; color: var(--red)">{{ fmt(stats.expense) }}</div>
            </div>
            <div style="color: var(--text3)">=</div>
            <div>
                <div style="font-size: 10px; color: var(--text3)">Остаток</div>
                <div id="cash-balance" style="font-weight: 700; font-size: 18px; color: var(--accent)">{{ fmt(balance) }}</div>
            </div>
        </div>
        <button class="btn btn-primary btn-sm" @click="later(LATER.add)()">📥 Приход</button>
        <button class="btn btn-ghost btn-sm" @click="later(LATER.add)()">📤 Расход</button>
        <button class="btn btn-ghost btn-sm" @click="later(LATER.add)()">↔️ Перевод</button>
        <button class="btn btn-ghost btn-sm" @click="later(LATER.account)()">+ Касса</button>
    </div>

    <!-- Фильтры журнала кассы -->
    <div style="display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center">
        <input id="cash-search" v-model="f.search" type="search" placeholder="🔍 Контрагент, описание..." style="font-size: 13px; flex: 1; min-width: 160px" />
        <select id="cash-flow-filter" v-model="f.flow" style="font-size: 13px">
            <option value="all">Все операции</option>
            <option value="income">📥 Только приход</option>
            <option value="expense">📤 Только расход</option>
        </select>
        <select id="cash-cat-filter" v-model="f.cat" style="font-size: 13px">
            <option value="all">Все категории</option>
            <option v-for="c in cats" :key="c" :value="c">{{ catLabel(props.categories, c) }}</option>
        </select>
        <button class="btn btn-ghost btn-sm" title="Сверить переводы из банков в кассу ЗП с поступлениями в кассе по датам" @click="zr = true">🔁 Сверка переводов</button>
        <button class="btn btn-ghost btn-sm" title="Собрать разрозненные выплаты с одной датой и номером в одну платёжку" @click="later(LATER.merge)()">🧩 Объединить платежи</button>
        <button class="btn btn-ghost btn-sm" title="Сколько выдано каждому сотруднику / контрагенту за выбранный период" @click="cp = true">👥 Выдано по сотрудникам</button>
        <span id="cash-filter-count" style="font-size: 12px; color: var(--text3)"
            >{{ rows.length }} операций <span style="color: var(--green); margin-left: 8px">▲{{ fmtShort(sums.in) }}</span>{{ ' ' }}
            <span style="color: var(--red); margin-left: 6px">▼{{ fmtShort(sums.out) }}</span></span
        >
    </div>

    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="cursor: pointer; user-select: none" @click="sortCashTx('date')">Дата <span>{{ icon('date') }}</span></th>
                    <th>Тип</th>
                    <th style="cursor: pointer; user-select: none" @click="sortCashTx('cat')">Категория <span>{{ icon('cat') }}</span></th>
                    <th>Статья</th>
                    <th>Описание</th>
                    <th style="cursor: pointer; user-select: none" @click="sortCashTx('ctr')">Контрагент <span>{{ icon('ctr') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right; color: var(--green)" @click="sortCashTx('income')">Приход <span>{{ icon('income') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right; color: var(--red)" @click="sortCashTx('expense')">Расход <span>{{ icon('expense') }}</span></th>
                    <th
                        style="text-align: right; cursor: help"
                        title="Остаток кассы после этой операции. Считается по всей истории кассы: приходы − расходы по порядку дат (внутри дня сначала приходы, затем расходы). Не зависит от сортировки и фильтров. У последней операции дня — остаток на конец дня."
                    >
                        Остаток после ⓘ
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cash-tbody">
                <tr v-if="!rows.length">
                    <td colspan="10" style="text-align: center; padding: 16px; color: var(--text3)">Операций нет за период</td>
                </tr>
                <tr v-for="t in rows" :key="t.id">
                    <td style="font-size: 12px; white-space: nowrap">{{ t.date }}</td>
                    <td>
                        <span class="badge" :class="t.flow === 'income' ? 'badge-green' : 'badge-red'">{{ t.flow === 'income' ? '📥' : '📤' }}</span>
                    </td>
                    <td style="font-size: 12px">{{ catLabel(props.categories, t.category) }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ t.item || '—' }}</td>
                    <td style="font-size: 12px">
                        {{ t.desc || '—' }}<template v-for="n in t.orders" :key="n">{{ ' ' }}<span style="color: var(--accent); font-size: 11px">({{ n }})</span></template
                        ><template v-if="t.esf > 0">{{ ' ' }}<span class="badge badge-green" style="font-size: 10px" :title="`Привязано к ЭСФ на ${fmt(t.esf)}`">🔗 ЭСФ</span></template>
                    </td>
                    <td style="font-size: 12px; color: var(--text3)">{{ t.ctr || '—' }}</td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; color: var(--green)">{{ t.flow === 'income' ? fmt(t.amount) : '—' }}</td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; color: var(--red)">{{ t.flow === 'expense' ? fmt(t.amount) : '—' }}</td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-weight: 600; color: var(--text3)">{{ balanceAfter.has(t) ? fmt(balanceAfter.get(t)!) : '—' }}</td>
                    <td style="white-space: nowrap">
                        <button v-if="hasPayouts(t)" class="btn btn-ghost btn-sm" style="font-size: 11px" title="Кому выдано (разбивка по сотрудникам)" @click="showTxPayouts(t)">👥</button
                        >{{ ' ' }}<button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later(LATER.edit)()">✏️</button>{{ ' ' }}<button
                            class="btn btn-ghost btn-sm"
                            style="font-size: 11px"
                            title="Копировать"
                            @click="later(LATER.edit)()"
                        >
                            📋
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <ErpModal id="cashCpModal" :open="cp" :modal-style="{ maxWidth: '760px', width: '96vw', maxHeight: '90vh', overflow: 'auto' }" @close="cp = false">
        <div class="modal-header">
            <div id="ccp-title" class="modal-title">Выдано по сотрудникам · {{ cashName }}</div>
            <button class="modal-close" @click="cp = false">✕</button>
        </div>
        <div style="font-size: 12px; color: var(--text3); margin-bottom: 8px">
            Учитывается выбранная касса, период сверху страницы и фильтр категории. Нажмите на строку — журнал отфильтруется по этому контрагенту.
        </div>
        <div style="overflow: auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Контрагент / сотрудник</th>
                        <th style="text-align: right">Операций</th>
                        <th>Последняя</th>
                        <th style="text-align: right; color: var(--red)">Выдано</th>
                        <th style="text-align: right; color: var(--green)">Возврат/приход</th>
                    </tr>
                </thead>
                <tbody id="ccp-tbody">
                    <tr v-if="!cpRows.length">
                        <td colspan="5" style="text-align: center; padding: 20px; color: var(--text3)">Операций за период нет</td>
                    </tr>
                    <tr v-for="r in cpRows" :key="r.name" style="cursor: pointer" @click="cashCpPick(r.name)">
                        <td>{{ r.name }}</td>
                        <td style="text-align: right">{{ r.n }}</td>
                        <td style="font-size: 12px">{{ r.last }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--red)">{{ r.out ? fmt(r.out) : '—' }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--green)">{{ r.inc ? fmt(r.inc) : '—' }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="cpRows.length" id="ccp-tfoot">
                    <tr style="font-weight: 700; background: var(--bg3)">
                        <td colspan="3" style="text-align: right">Итого:</td>
                        <td style="text-align: right; font-family: monospace; color: var(--red)">{{ fmt(cpRows.reduce((t, r) => t + r.out, 0)) }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(cpRows.reduce((t, r) => t + r.inc, 0)) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="margin-top: 10px; text-align: right">
            <button class="btn btn-ghost btn-sm" @click="exportCashCp">📥 Excel</button>
        </div>
    </ErpModal>

    <ZpReconModal :open="zr" :accounts="props.accounts" :txs="props.txs" :cash-id="cashId" @close="zr = false" />

    <ErpModal id="txPayModal" :open="pay.open" :modal-style="{ maxWidth: '700px', width: '96vw', maxHeight: '90vh', overflow: 'auto' }" @close="pay.open = false">
        <div class="modal-header">
            <div id="txpay-title" class="modal-title">Кому выдано · {{ pay.tx?.date }} · ₸{{ Math.round(pay.tx?.amount ?? 0).toLocaleString('ru-RU') }}</div>
            <button class="modal-close" @click="pay.open = false">✕</button>
        </div>
        <div id="txpay-body">
            <div v-if="pay.error" style="color: var(--red)">Не удалось загрузить: {{ pay.error }}</div>
            <div v-else-if="!pay.res" style="padding: 12px; color: var(--text3)">⏳ Загрузка...</div>
            <template v-else>
                <div style="font-size: 12px; color: var(--text3); margin-bottom: 8px">{{ pay.tx?.desc || '' }}</div>
                <div v-if="!pay.res.rows.length || pay.res.how === 'date'" style="padding: 12px; color: var(--text3); font-size: 13px">
                    К этому расходу выплаты по сотрудникам не привязаны (в ведомости нет выплат с номером или суммой этого документа).
                </div>
                <template v-else>
                    <div v-if="Math.abs(payDiff) >= 1" style="font-size: 12px; color: var(--amber); margin-bottom: 6px">
                        ⚠️ Выплаты по документу: {{ fmt(pay.res.total) }}, сумма документа: {{ fmt(pay.tx?.amount ?? 0) }} (разница {{ fmt(payDiff) }}).
                    </div>
                    <div v-else style="font-size: 12px; color: var(--green); margin-bottom: 6px">✓ Сумма выплат совпадает с документом.</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <th>Комментарий</th>
                                <th style="text-align: right">Выдано</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(p, i) in payRows" :key="i">
                                <td>{{ p.emp_name }}</td>
                                <td style="font-size: 12px; color: var(--text3)">{{ p.comment || '' }}</td>
                                <td style="text-align: right; font-family: monospace">{{ fmt(p.amount || 0) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight: 700; background: var(--bg3)">
                                <td colspan="2" style="text-align: right">Итого ({{ payRows.length }} чел.):</td>
                                <td style="text-align: right; font-family: monospace">{{ fmt(pay.res.total || 0) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </template>
            </template>
        </div>
    </ErpModal>
</template>
