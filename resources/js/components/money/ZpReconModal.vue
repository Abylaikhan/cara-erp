<script setup lang="ts">
// «🔁 Сверка переводов на кассу ЗП» of «Деньги» → «Касса» — legacy zrOpen/zrCashAccount/zrIsToZp/zrSrcOf/zrBuild/zrOrphans/
// zrRender/zrOrphHtml (83385ee, 6630d36): by date, what left each bank for the salary cash desk (bank expenses marked so in the description or the
// counterparty) against the income recorded in the cash desk from that bank; and transfers between own accounts that have
// no paired income. The comparison is the same; the fixes delete and create money operations and come with posting money
// (ERP-031…033, decisions D-03 and D-11): their buttons say so.
import { computed } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { LATER, type MoneyAccount, type MoneyTx } from '@/lib/money';

const props = defineProps<{ open: boolean; accounts: MoneyAccount[]; txs: MoneyTx[]; cashId: number }>();
const emit = defineEmits<{ close: [] }>();

const BANK_CATS = ['transfer_out', 'transfer', 'salary_transfer', 'other_out'];
const CASH_CATS = ['transfer_in', 'transfer'];
const fmt = (n: number) => '₸' + Math.round(n || 0).toLocaleString('ru-RU');
const isZp = (a: MoneyAccount) => /зп|зарплат/i.test(a.name + ' ' + (a.bank || '')) && !/салон/i.test(a.name + ' ' + (a.bank || ''));

// Legacy zrCashAccount: the chosen cash desk when it is the salary one, else the first salary cash desk.
const cash = computed(() => {
    const cashes = props.accounts.filter((a) => a.type === 'cash');
    const cur = cashes.find((a) => a.id === props.cashId);
    return cur && isZp(cur) ? cur : (cashes.find(isZp) ?? null);
});
const banks = computed(() => props.accounts.filter((a) => a.type !== 'cash'));
function isToZp(t: MoneyTx): boolean {
    const s = (t.desc || '') + ' ' + (t.ctr || '');
    if (/салон/i.test(s)) return false;
    return /касс\S*\s*(зп|зарплат)/i.test(s) || /в\s+касс\S*\s*\d+/i.test(s) || /поступление в касс/i.test(s);
}
function srcOf(t: MoneyTx): MoneyAccount | null {
    const s = ((t.ctr || '') + ' ' + (t.desc || '')).toLowerCase();
    const hit = banks.value.filter((b) => {
        const n = (b.name || '').toLowerCase();
        const bn = (b.bank || '')
            .toLowerCase()
            .replace(/[«»"]|ао\s*/g, '')
            .trim();
        return (n && s.includes(n)) || (bn && s.includes(bn));
    });
    // «Халык Банк (USD)» contains «Халык Банк» — the longest match wins
    hit.sort((a, b) => (b.name || '').length - (a.name || '').length);
    return hit[0] ?? null;
}
type Group = Record<string, { sum: number; txs: MoneyTx[] }>;
interface Row {
    date: string;
    E: Group;
    A: Group;
    del: number;
    add: number;
    noBank: boolean;
}
// Legacy zrBuild: the dates where the bank expenses and the cash income do not match one to one.
const rows = computed<Row[]>(() => {
    const c = cash.value;
    if (!c) return [];
    const exp: Record<string, Group> = {};
    const act: Record<string, Group> = {};
    const put = (m: Record<string, Group>, d: string, k: string, t: MoneyTx) => {
        const e = ((m[d] ??= {})[k] ??= { sum: 0, txs: [] });
        e.sum += +t.amount || 0;
        e.txs.push(t);
    };
    props.txs
        .filter((t) => t.flow === 'expense' && BANK_CATS.includes(t.category) && banks.value.some((b) => b.id === t.account) && isToZp(t))
        .forEach((t) => put(exp, String(t.date).slice(0, 10), String(t.account), t));
    props.txs
        .filter((t) => t.account === c.id && t.flow === 'income' && CASH_CATS.includes(t.category) && !/^\s*Перевод\s*←/i.test(t.desc || ''))
        .forEach((t) => {
            const src = srcOf(t);
            if (src) put(act, String(t.date).slice(0, 10), String(src.id), t); // a source that is not a bank (another cash desk) is left alone
        });
    const out: Row[] = [];
    new Set([...Object.keys(exp), ...Object.keys(act)]).forEach((d) => {
        const E = exp[d] || {};
        const A = act[d] || {};
        let del = 0;
        let add = 0;
        let bad = false;
        Object.keys(E).forEach((k) => {
            const a = A[k];
            if (a && Math.abs(a.sum - E[k].sum) < 1 && a.txs.length === 1) return;
            bad = true;
            if (a) del += a.txs.length;
            add++;
        });
        Object.keys(A).forEach((k) => {
            if (!E[k]) {
                bad = true;
                del += A[k].txs.length;
            }
        });
        if (bad) out.push({ date: d, E, A, del, add, noBank: !Object.keys(E).length });
    });
    return out.sort((a, b) => b.date.localeCompare(a.date));
});
// Legacy zrOrphans: «Перевод → X» expenses without an income «Перевод ← Y» of the same date and amount on X.
const orphans = computed(() => {
    const used = new Set<MoneyTx>();
    const out: { tx: MoneyTx; to: MoneyAccount; from: MoneyAccount | undefined; date: string }[] = [];
    props.txs
        .filter((t) => t.flow === 'expense' && t.category === 'transfer_out')
        .sort((a, b) => String(a.date).localeCompare(String(b.date)))
        .forEach((t) => {
            const m = /^\s*Перевод\s*→\s*(.+?)\s*$/i.exec(t.desc || '');
            if (!m) return;
            const to = props.accounts.find((a) => (a.name || '').trim().toLowerCase() === m[1].trim().toLowerCase());
            if (!to) return;
            const d = String(t.date).slice(0, 10);
            const pair = props.txs.find(
                (x) =>
                    !used.has(x) &&
                    x.flow === 'income' &&
                    x.account === to.id &&
                    String(x.date).slice(0, 10) === d &&
                    Math.abs((+x.amount || 0) - (+t.amount || 0)) < 1 &&
                    ['transfer_in', 'transfer'].includes(x.category),
            );
            if (pair) used.add(pair);
            else out.push({ tx: t, to, from: props.accounts.find((a) => a.id === t.account), date: d });
        });
    return out;
});
const bankName = (k: string) => props.accounts.find((a) => String(a.id) === k)?.name ?? 'не определён';
const later = () => window.alert(LATER.zpRecon);
</script>

<template>
    <ErpModal id="zpReconModal" :open="open" :modal-style="{ maxWidth: '980px', width: '96vw', maxHeight: '92vh', overflow: 'auto' }" @close="emit('close')">
        <div class="modal-header">
            <div class="modal-title">🔁 Сверка переводов на кассу ЗП</div>
            <button class="modal-close" @click="emit('close')">✕</button>
        </div>
        <div id="zr-body">
            <div v-if="!cash" style="padding: 20px; color: var(--red)">Не найдена касса ЗП (кассa с «ЗП» или «зарплата» в названии, не «ЗП салонов»).</div>
            <template v-else>
                <div style="font-size: 12px; color: var(--text3); margin-bottom: 8px">
                    Касса: <b>{{ cash.name }}</b>. Слева — что ушло из банков в «кассу ЗП» (расходы банков с такой пометкой в описании/контрагенте), справа — что записано
                    поступлением в кассе. Для отмеченных дат неверные поступления будут удалены и созданы правильные: по одному на банк и дату.
                </div>
                <template v-if="rows.length">
                    <div style="overflow: auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Дата</th>
                                    <th>Расход банков</th>
                                    <th>Поступления в кассе</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in rows" :key="r.date">
                                    <td><input :id="'zr-c-' + i" type="checkbox" :disabled="r.noBank" :checked="!r.noBank" /></td>
                                    <td style="white-space: nowrap">{{ r.date }}</td>
                                    <td style="font-size: 12px">
                                        <template v-for="(k, j) in Object.keys(r.E)" :key="k"
                                            ><br v-if="j" />{{ bankName(k) }}: <b>{{ fmt(r.E[k].sum) }}</b> ({{ r.E[k].txs.length }})</template
                                        ><span v-if="!Object.keys(r.E).length" style="color: var(--text3)">—</span>
                                    </td>
                                    <td style="font-size: 12px">
                                        <template v-for="(k, j) in Object.keys(r.A)" :key="k"
                                            ><br v-if="j" />{{ k === '?' ? 'источник не определён' : bankName(k) }}: <b>{{ fmt(r.A[k].sum) }}</b> ({{ r.A[k].txs.length }})</template
                                        ><span v-if="!Object.keys(r.A).length" style="color: var(--text3)">нет</span>
                                    </td>
                                    <td style="font-size: 11px">
                                        <span v-if="r.noBank" style="color: var(--amber)">в банках нет расходов на кассу ЗП — не трогаем</span>
                                        <template v-else>удалить {{ r.del }}, создать {{ r.add }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="font-size: 12px; color: var(--text3); margin-top: 8px">Перед исправлением браузер скачает файл-копию удаляемых операций.</div>
                    <div style="margin-top: 10px; display: flex; gap: 8px; justify-content: flex-end">
                        <button class="btn btn-ghost" @click="emit('close')">Закрыть</button>
                        <button id="zr-go" class="btn btn-primary" @click="later">Исправить отмеченные</button>
                    </div>
                </template>
                <div v-else style="padding: 24px; text-align: center; color: var(--green)">
                    ✓ Расхождений не найдено: поступления в кассе ЗП совпадают с расходами банков по всем датам.
                </div>
                <div v-if="!orphans.length" style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--border); font-size: 12px; color: var(--green)">
                    ✓ Переводов между своими счетами без пары нет.
                </div>
                <div v-else style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--border)">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px">↔️ Переводы между своими счетами без парного поступления</div>
                    <div style="font-size: 12px; color: var(--text3); margin-bottom: 6px">
                        Есть расход «Перевод → …», а на счёте-получателе нет поступления на ту же дату и сумму. Отмеченные поступления будут созданы.
                    </div>
                    <div style="overflow: auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Дата</th>
                                    <th>Откуда</th>
                                    <th>Куда</th>
                                    <th style="text-align: right">Сумма</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(o, i) in orphans" :key="i">
                                    <td><input :id="'zo-c-' + i" type="checkbox" checked /></td>
                                    <td>{{ o.date }}</td>
                                    <td>{{ o.from ? o.from.name : '?' }}</td>
                                    <td>{{ o.to.name }}</td>
                                    <td style="text-align: right; font-family: monospace">{{ fmt(o.tx.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 8px; text-align: right"><button class="btn btn-primary btn-sm" @click="later">Создать недостающие поступления</button></div>
                </div>
                <div id="zr-msg" style="margin-top: 8px; font-size: 13px"></div>
            </template>
        </div>
    </ErpModal>
</template>
