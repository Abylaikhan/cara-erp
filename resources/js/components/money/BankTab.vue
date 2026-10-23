<script setup lang="ts">
// Tab «Расчётные счета» of «Деньги» — legacy money-tab-bank (L6143–6190), renderBankAccounts/sortBankTx/fmtTxAmt/
// toggleBankCurrency/renderBankTx (L9809–10050). Same cards, filters, table and running balance: a USD account is shown in
// tenge (its equivalent) or in dollars. The account number is not shown on the card (it is not stored).
// As in legacy, the table always ends up by date (newest first, income before expense inside a day); another sort column
// only orders the operations inside such a group.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { accountStats, catLabel, fmt, fmtShort, hasPeriod, inPeriod, LATER, period, type Categories, type MoneyAccount, type MoneyTx } from '@/lib/money';

const props = defineProps<{ accounts: MoneyAccount[]; txs: MoneyTx[]; categories: Categories }>();
// «+ Счёт» and a card open the window «Расчётный счёт» of the page (legacy openBankAccountModal; ADR-0047).
const emit = defineEmits<{ account: [acc: MoneyAccount | null] }>();

const banks = computed(() => props.accounts.filter((a) => a.type === 'bank'));
const bankIds = computed(() => new Set(banks.value.map((a) => a.id)));
const accountOf = (id: number) => props.accounts.find((a) => a.id === id);
const f = reactive({ account: 'all' as number | 'all', search: '', flow: 'all', cat: 'all' });
const sort = reactive({ col: 'date', asc: false });
const showUsd = ref(false);
function sortBankTx(col: string): void {
    if (sort.col === col) sort.asc = !sort.asc;
    else Object.assign(sort, { col, asc: col !== 'date' });
}
const icon = (col: string) => (col === sort.col ? (sort.asc ? '▲' : '▼') : '');

// ── Cards ──
function card(acc: MoneyAccount) {
    const stats = accountStats(props.txs, acc.id);
    const all = props.txs.filter((t) => t.account === acc.id).reduce((s, t) => s + (t.flow === 'income' ? t.amount : -t.amount), 0);
    const opening = period.from
        ? props.txs.filter((t) => t.account === acc.id && t.date < period.from).reduce((s, t) => s + (t.flow === 'income' ? t.amount : -t.amount), 0) + acc.opening
        : acc.opening;
    const balance = hasPeriod() ? opening + stats.income - stats.expense : all + acc.opening;
    const money = (n: number) => (acc.currency === 'USD' ? '$' + Math.round(n).toLocaleString('en-US') : '₸' + Math.round(n).toLocaleString('ru-RU'));
    return { stats, balance, money };
}
const cards = computed(() => new Map(banks.value.map((a) => [a.id, card(a)])));

// ── Table ──
const isUsd = computed(() => f.account !== 'all' && accountOf(f.account)?.currency === 'USD');
const cats = computed(() => [...new Set(props.txs.filter((t) => bankIds.value.has(t.account)).map((t) => t.category))].sort());
const usdAcc = (t: MoneyTx) => accountOf(t.account)?.currency === 'USD';
/** Legacy fmtTxAmt: a USD account in tenge (the equivalent) unless dollars are chosen. */
const txAmount = (t: MoneyTx) => (usdAcc(t) ? (showUsd.value && isUsd.value ? '$' + Math.round(t.amount).toLocaleString('en-US') : fmt(t.fx || t.amount)) : fmt(t.amount));
const byDay = (a: MoneyTx, b: MoneyTx) => {
    const dc = b.date.localeCompare(a.date);
    if (dc !== 0) return dc;
    return a.flow !== b.flow ? (a.flow === 'income' ? -1 : 1) : 0;
};
const rows = computed(() => {
    const search = f.search.toLowerCase();
    const list = props.txs.filter(
        (t) =>
            bankIds.value.has(t.account) &&
            inPeriod(t) &&
            (f.account === 'all' || t.account === f.account) &&
            (f.flow === 'all' || t.flow === f.flow) &&
            (f.cat === 'all' || t.category === f.cat) &&
            (!search || t.ctr.toLowerCase().includes(search) || t.desc.toLowerCase().includes(search) || t.order.toLowerCase().includes(search)),
    );
    const key = (t: MoneyTx): string | number => {
        if (sort.col === 'acc') return accountOf(t.account)?.name ?? '';
        if (sort.col === 'cat') return catLabel(props.categories, t.category);
        if (sort.col === 'ctr') return t.ctr;
        if (sort.col === 'income') return t.flow === 'income' ? t.amount : 0;
        if (sort.col === 'expense') return t.flow === 'expense' ? t.amount : 0;
        return t.date;
    };
    const first = [...list].sort((a, b) => {
        if (sort.col === 'date') {
            const dc = sort.asc ? a.date.localeCompare(b.date) : b.date.localeCompare(a.date);
            if (dc !== 0) return dc;
            if (a.flow !== b.flow) return sort.asc ? (a.flow === 'income' ? -1 : 1) : a.flow === 'expense' ? -1 : 1;
            return b.amount - a.amount;
        }
        const va = key(a);
        const vb = key(b);
        if (typeof va === 'number' && typeof vb === 'number') return sort.asc ? va - vb : vb - va;
        return sort.asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
    });
    return first.sort(byDay);
});
// Running balance of each account over all its operations: oldest first, income before expense, larger first.
const balances = computed(() => {
    const map = new Map<number, number>();
    [...new Set(rows.value.map((t) => t.account))].forEach((accId) => {
        const acc = accountOf(accId);
        const usd = acc?.currency === 'USD';
        let run = showUsd.value && isUsd.value && usd ? 0 : (acc?.opening ?? 0);
        props.txs
            .filter((t) => t.account === accId)
            .sort((a, b) => a.date.localeCompare(b.date) || (a.flow !== b.flow ? (a.flow === 'income' ? -1 : 1) : b.amount - a.amount))
            .forEach((t) => {
                const val = usd ? (showUsd.value && isUsd.value ? t.amount : t.fx || t.amount) : t.amount;
                run += t.flow === 'income' ? val : -val;
                map.set(t.id, run);
            });
    });
    return map;
});
const sums = computed(() => {
    const val = (t: MoneyTx) => (usdAcc(t) ? t.fx || t.amount : t.amount);
    return { in: rows.value.filter((t) => t.flow === 'income').reduce((s, t) => s + val(t), 0), out: rows.value.filter((t) => t.flow === 'expense').reduce((s, t) => s + val(t), 0) };
});
const balText = (t: MoneyTx) => {
    const b = balances.value.get(t.id) ?? 0;
    return showUsd.value && isUsd.value ? '$' + Math.round(b).toLocaleString('en-US') : fmt(b);
};
const later = (text: string) => () => window.alert(text);
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 14px; align-items: center; flex-wrap: wrap">
        <div style="flex: 1"></div>
        <button class="btn btn-ghost btn-sm" @click="emit('account', null)">+ Счёт</button>
        <button class="btn btn-ghost btn-sm" @click="later(LATER.statement)()">📑 Импорт выписки</button>
        <button class="btn btn-primary btn-sm" @click="later(LATER.add)()">📥 Поступление</button>
        <button class="btn btn-ghost btn-sm" @click="later(LATER.add)()">📤 Списание</button>
        <button class="btn btn-ghost btn-sm" @click="later(LATER.add)()">↔️ Перевод</button>
    </div>
    <!-- Карточки счетов -->
    <div id="bank-accounts-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 16px">
        <div v-for="acc in banks" :key="acc.id" class="card" style="padding: 16px; cursor: pointer" @click="emit('account', acc)">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px">
                <div>
                    <div style="font-weight: 700; font-size: 14px">{{ acc.name }}</div>
                    <div style="font-size: 11px; color: var(--text3)">{{ acc.bank }}</div>
                </div>
                <span class="badge badge-blue" style="font-size: 10px">{{ acc.currency }}</span>
            </div>
            <div style="font-size: 22px; font-weight: 700; color: var(--accent); margin-bottom: 4px">
                {{ acc.currency === 'KZT' ? '₸' : acc.currency }} {{ cards.get(acc.id)!.balance.toLocaleString('ru-RU') }}
            </div>
            <div v-if="hasPeriod()" style="font-size: 10px; color: var(--text3); margin-bottom: 4px">Текущий остаток ↑↓ за период</div>
            <div style="display: flex; gap: 12px; font-size: 12px">
                <div><span style="color: var(--green)">↑ {{ cards.get(acc.id)!.money(cards.get(acc.id)!.stats.income) }}</span></div>
                <div><span style="color: var(--red)">↓ {{ cards.get(acc.id)!.money(cards.get(acc.id)!.stats.expense) }}</span></div>
            </div>
        </div>
    </div>
    <!-- Фильтры и сортировка -->
    <div style="display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center">
        <select id="bank-account-filter" v-model="f.account" style="font-size: 13px; width: auto">
            <option value="all">Все счета</option>
            <option v-for="a in banks" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
        <input id="bank-search" v-model="f.search" type="search" placeholder="🔍 Контрагент, описание..." style="font-size: 13px; flex: 1; min-width: 160px" />
        <select id="bank-flow-filter" v-model="f.flow" style="font-size: 13px">
            <option value="all">Все операции</option>
            <option value="income">📥 Только приход</option>
            <option value="expense">📤 Только расход</option>
        </select>
        <select id="bank-cat-filter" v-model="f.cat" style="font-size: 13px">
            <option value="all">Все категории</option>
            <option v-for="c in cats" :key="c" :value="c">{{ catLabel(props.categories, c) }}</option>
        </select>
        <button id="bank-currency-toggle" class="btn btn-ghost btn-sm" style="font-size: 12px" :style="{ display: isUsd ? '' : 'none' }" @click="showUsd = !showUsd">
            {{ showUsd ? '$ Доллары' : '₸ Тенге' }}
        </button>
        <button class="btn btn-ghost btn-sm" title="Обновить из базы" @click="router.reload({ only: ['transactions', 'advanceDebt'] })">🔄</button>
        <span id="bank-filter-count" style="font-size: 12px; color: var(--text3)"
            >{{ rows.length }} операций <span style="color: var(--green); margin-left: 8px">▲{{ fmtShort(sums.in) }}</span>{{ ' ' }}
            <span style="color: var(--red); margin-left: 6px">▼{{ fmtShort(sums.out) }}</span></span
        >
    </div>
    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="cursor: pointer; user-select: none" @click="sortBankTx('date')">Дата <span>{{ icon('date') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortBankTx('acc')">Счёт <span>{{ icon('acc') }}</span></th>
                    <th style="cursor: pointer; user-select: none" @click="sortBankTx('cat')">Категория <span>{{ icon('cat') }}</span></th>
                    <th>Статья</th>
                    <th>Описание</th>
                    <th style="cursor: pointer; user-select: none" @click="sortBankTx('ctr')">Контрагент <span>{{ icon('ctr') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right; color: var(--green)" @click="sortBankTx('income')">Приход <span>{{ icon('income') }}</span></th>
                    <th style="cursor: pointer; user-select: none; text-align: right; color: var(--red)" @click="sortBankTx('expense')">Расход <span>{{ icon('expense') }}</span></th>
                    <th style="text-align: right">Остаток</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="bank-tbody">
                <tr v-if="!rows.length">
                    <td colspan="10" style="text-align: center; padding: 16px; color: var(--text3)">Операций нет за период</td>
                </tr>
                <tr v-for="t in rows" :key="t.id">
                    <td style="font-size: 12px; white-space: nowrap">{{ t.date }}</td>
                    <td style="font-size: 12px; font-weight: 500">{{ accountOf(t.account)?.name || '—' }}</td>
                    <td style="font-size: 12px">{{ catLabel(props.categories, t.category) }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ t.item || '—' }}</td>
                    <td style="font-size: 12px">
                        {{ t.desc || '—'
                        }}<div v-if="t.fx" style="font-size: 10px; color: var(--text3)">💵 ${{ Math.round(usdAcc(t) ? t.amount : t.fx).toLocaleString('en-US') }}</div
                        ><template v-for="n in t.orders" :key="n">{{ ' ' }}<span style="color: var(--accent); font-size: 11px">({{ n }})</span></template
                        ><template v-if="t.esf > 0">{{ ' ' }}<span class="badge badge-green" style="font-size: 10px" :title="`Привязано к ЭСФ на ${fmt(t.esf)}`">🔗 ЭСФ</span></template>
                    </td>
                    <td style="font-size: 12px; color: var(--text3)">{{ t.ctr || '—' }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--green)">{{ t.flow === 'income' ? txAmount(t) : '—' }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--red)">{{ t.flow === 'expense' ? txAmount(t) : '—' }}</td>
                    <td style="text-align: right; font-family: monospace; font-weight: 600">
                        <span :style="{ color: (balances.get(t.id) ?? 0) >= 0 ? 'var(--text1)' : 'var(--red)' }">{{ balText(t) }}</span>
                    </td>
                    <td style="white-space: nowrap">
                        <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later(LATER.edit)()">✏️</button
                        ><button class="btn btn-ghost btn-sm" style="font-size: 11px" title="Копировать" @click="later(LATER.edit)()">📋</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
