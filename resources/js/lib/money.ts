// M12 «Деньги» — operations as the page shows them: types, legacy formats and counting (L8623–8712, L9521–9575, L9886–9905).
import { reactive } from 'vue';

export interface MoneyAccount {
    id: number;
    name: string;
    bank: string;
    currency: string;
    type: 'cash' | 'bank';
    icon: string | null;
    opening: number;
}
export interface MoneyTx {
    id: number;
    account: number;
    date: string;
    flow: 'income' | 'expense';
    category: string;
    item: string | null;
    amount: number;
    fx: number | null;
    desc: string;
    ctr: string;
    /** Its own order number (legacy orderNum); `orders` adds those of order_payments. */
    order: string;
    orders: string[];
    esf: number;
}
export type Categories = { income: Record<string, [string, string]>; expense: Record<string, [string, string]> };

export const fmt = (n: number): string => '₸' + Math.round(n).toLocaleString('ru-RU');
/** Legacy short sum of the counters (₸1.2М / ₸35К). */
export const fmtShort = (n: number): string => (n >= 1000000 ? '₸' + (n / 1000000).toFixed(1) + 'М' : n >= 1000 ? '₸' + Math.round(n / 1000) + 'К' : '₸' + Math.round(n));

/** Legacy txCatLabel: icon and label, or the code itself for a category it does not list. */
export function catLabel(categories: Categories, id: string): string {
    const c = categories.income[id] ?? categories.expense[id];
    return c ? c[0] + ' ' + c[1] : id;
}

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/**
 * The period of the page (legacy setMoneyPeriod). The bounds are local dates; legacy took them through UTC, which in
 * Almaty moved the whole period a day earlier.
 */
export const period = reactive({ mode: 'all', from: '', to: '' });
export function setMoneyPeriod(mode: string): void {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    period.mode = mode;
    if (mode === 'month') Object.assign(period, { from: iso(new Date(y, m, 1)), to: iso(new Date(y, m + 1, 0)) });
    else if (mode === 'quarter') {
        const q = Math.floor(m / 3);
        Object.assign(period, { from: iso(new Date(y, q * 3, 1)), to: iso(new Date(y, q * 3 + 3, 0)) });
    } else if (mode === 'year') Object.assign(period, { from: `${y}-01-01`, to: `${y}-12-31` });
    else if (mode === 'all') Object.assign(period, { from: '', to: '' });
}
export const hasPeriod = () => !!(period.from || period.to);

/** Legacy moneyFilteredTx. */
export const inPeriod = (t: MoneyTx): boolean => (!period.from || t.date >= period.from) && (!period.to || t.date <= period.to);

/** Legacy calcAccountStats: income and expense of the period; the balance is their difference (all time without a period). */
export function accountStats(txs: MoneyTx[], accountId: number): { income: number; expense: number; balance: number } {
    let income = 0;
    let expense = 0;
    txs.forEach((t) => {
        if (t.account !== accountId || !inPeriod(t)) return;
        if (t.flow === 'income') income += t.amount;
        else expense += t.amount;
    });
    return { income, expense, balance: income - expense };
}

/** Opening balance of a period: everything of the account before its first day. */
export const openingOf = (txs: MoneyTx[], accountId: number): number =>
    period.from ? txs.filter((t) => t.account === accountId && t.date < period.from).reduce((s, t) => s + (t.flow === 'income' ? t.amount : -t.amount), 0) : 0;

export const LATER = {
    edit: 'Правка и копирование операций появятся вместе с проведением денег: правила оплат и остатков ждут решений D-03 и D-11 (ERP-031…033).',
    add: 'Новые операции и переводы появятся вместе с проведением денег (ERP-031…033, решения D-03 и D-11).',
    account: 'Новые кассы и счета появятся вместе с проведением денег (ERP-031).',
    statement: 'Импорт банковской выписки появится отдельной частью раздела (ERP-034).',
    payouts: 'Разбивка выдачи по сотрудникам появится вместе с разделом «Сотрудники» (ERP-053, решение D-13).',
};
