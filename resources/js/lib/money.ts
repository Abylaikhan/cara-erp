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
    /** The opening balance as stored (to the tiyn), for the window «Расчётный счёт». */
    openingText: string;
    iik: string;
    bik: string;
    lock_version: number;
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
 * The period of the page (legacy setMoneyPeriod, c54cf25). The bounds are local dates, as legacy now takes them (UTC
 * midnight of the local day). «День» is one chosen day (today at first), «Месяц» a month chosen from the months that have
 * operations; a custom start later than the end pulls the end to it.
 */
export const period = reactive({ mode: 'all', from: '', to: '', day: '', month: '' });
export const MONTH_NAMES = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
/** Legacy mpFillMonths: the current month and every month with an operation, latest first. */
export function moneyMonths(txs: MoneyTx[]): { value: string; label: string }[] {
    const now = new Date();
    const set = new Set([`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`]);
    txs.forEach((t) => {
        const m = String(t.date || '').slice(0, 7);
        if (/^\d{4}-(0[1-9]|1[0-2])$/.test(m)) set.add(m);
    });
    return [...set]
        .sort()
        .reverse()
        .map((v) => ({ value: v, label: `${MONTH_NAMES[+v.slice(5) - 1]} ${v.slice(0, 4)}` }));
}
export function setMoneyPeriod(mode: string, val?: string): void {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    if (mode === 'custom') {
        if (val === 'from' && period.from && period.to && period.to < period.from) period.to = period.from;
        period.mode = mode;
        return;
    }
    period.mode = mode;
    if (mode === 'day') {
        period.day = val || period.day || iso(now);
        Object.assign(period, { from: period.day, to: period.day });
    } else if (mode === 'month') {
        // the choice of the list stays when «Месяц» is pressed again; the current month at first
        period.month = val || period.month || `${y}-${String(m + 1).padStart(2, '0')}`;
        const [yy, mm] = period.month.split('-').map(Number);
        Object.assign(period, { from: iso(new Date(yy, mm - 1, 1)), to: iso(new Date(yy, mm, 0)) });
    } else if (mode === 'quarter') {
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
    statement: 'Импорт банковской выписки появится отдельной частью раздела (ERP-034).',
    zpRecon: 'Исправление удаляет и создаёт денежные операции и появится вместе с проведением денег (ERP-031…033, решения D-03 и D-11). Сравнение уже показано.',
    merge: 'Объединение выплат кассы в одну платёжку меняет операции и выплаты зарплатной ведомости и появится вместе с проведением денег и начислениями ЗП (ERP-031…033, ERP-057, решения D-03 и D-11).',
};
