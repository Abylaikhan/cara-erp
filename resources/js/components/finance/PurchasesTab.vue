<script setup lang="ts">
// Tab «Закупки» of «Финансы» — legacy finance-purchases (L5619–5711), getPurDateRange/renderPurchasesDash/filterPurDebts/
// renderPurDebtsTable (L24772–25215) and the supplier ledger openSupplierReconciliation (L25046–25176). Kept as legacy counts:
// payments are expenses to a legal entity by the counterparty name, debts group the suppliers by BIN (one BIN found for a name
// is carried to its other spellings, branches are cut off), payments through advance reports count too. The period is a
// calendar period (legacy shifted it by a day through UTC); amounts are in tenge.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { normSupNameMerge } from '@/lib/supplierDuplicates';

export interface PurchasesData {
    invoices: { num: string | null; date: string; status: string; supplier: string; bin: string | null; total: string }[];
    payments: { date: string; counterparty: string | null; bin: string | null; amount: string; desc: string | null }[];
    suppliers: { name: string; bin: string | null }[];
    advanceLines: { date: string | null; supplier: string | null; amount: string; desc: string | null; employee: string | null }[];
}
const props = defineProps<{ data: PurchasesData | undefined; today: string; active: boolean }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.data === undefined) router.reload({ only: ['purchases'] });
    },
    { immediate: true },
);

// ── Period (legacy getPurDateRange) ──
const f = reactive({ period: 'month' as 'month' | 'quarter' | 'year' | 'custom', from: '', to: '' });
const pad = (n: number) => String(n).padStart(2, '0');
const lastDay = (y: number, m: number) => new Date(Date.UTC(y, m, 0)).getUTCDate();
const range = computed(() => {
    const [y, m] = props.today.split('-').map(Number);
    if (f.period === 'month') return { from: `${y}-${pad(m)}-01`, to: `${y}-${pad(m)}-${pad(lastDay(y, m))}` };
    if (f.period === 'quarter') {
        const q = Math.floor((m - 1) / 3);
        return { from: `${y}-${pad(q * 3 + 1)}-01`, to: `${y}-${pad(q * 3 + 3)}-${pad(lastDay(y, q * 3 + 3))}` };
    }
    if (f.period === 'year') return { from: `${y}-01-01`, to: `${y}-12-31` };
    return { from: f.from, to: f.to };
});
const inRange = (d: string | null) => (!range.value.from || (d ?? '') >= range.value.from) && (!range.value.to || (d ?? '') <= range.value.to);

const fmt = (n: number) => (n >= 1000000 ? '₸' + (n / 1000000).toFixed(1) + 'М' : n >= 1000 ? '₸' + Math.round(n / 1000) + 'К' : '₸' + Math.round(n));
const fmt2 = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');

/** Legacy isLegalEntity: a company by its legal form; banks, the own company and state bodies are not suppliers. */
function isLegalEntity(name: string | null): boolean {
    if (!name) return false;
    const n = name.trim();
    if (/банк|bank/i.test(n) || /меркурий/i.test(n) || /правительство для граждан/i.test(n) || /государственная корпорация/i.test(n)) return false;
    return /^(тоо|ип|ао|оао|зао|нао|гкп)\s/i.test(n) || /^(тоо|ип|ао|оао|зао|нао|гкп)["«»'"]/i.test(n) || /\s(тоо|ао|оао|зао|нао)\s/i.test(n);
}
const BRANCH = /\s*(представительство|филиал|отдел|офис|бутик|салон|магазин)\s+.*/i;
const normSupplier = (name: string | null) => normSupNameMerge((name || '').replace(BRANCH, '')) || '—';
const cleanName = (raw: string) => (raw || '').replace(BRANCH, '').trim() || raw;

// ── Dashboard of the period ──
const dash = computed(() => {
    const d = props.data;
    if (!d) return null;
    const invoices = d.invoices.filter((i) => inRange(i.date));
    const totalSum = invoices.reduce((s, i) => s + Number(i.total), 0);
    const suppliers = new Set(invoices.map((i) => i.supplier || i.bin)).size;
    const paidTx = d.payments.filter((t) => isLegalEntity(t.counterparty) && inRange(t.date));
    const totalPaid = paidTx.reduce((s, t) => s + Number(t.amount), 0);
    const bySupplier = new Map<string, { count: number; sum: number }>();
    invoices.forEach((i) => {
        const name = i.supplier || i.bin || '—';
        const e = bySupplier.get(name) ?? { count: 0, sum: 0 };
        e.count++;
        e.sum += Number(i.total);
        bySupplier.set(name, e);
    });
    const top = [...bySupplier.entries()].sort((a, b) => b[1].sum - a[1].sum).slice(0, 8);
    const monthData: Record<string, number> = {};
    invoices.forEach((i) => (monthData[i.date.slice(0, 7)] = (monthData[i.date.slice(0, 7)] || 0) + Number(i.total)));
    const months = Object.keys(monthData).sort().slice(-6);
    const byPay = new Map<string, number>();
    paidTx.forEach((t) => byPay.set(t.counterparty || '—', (byPay.get(t.counterparty || '—') ?? 0) + Number(t.amount)));
    return {
        count: invoices.length,
        totalSum,
        totalPaid,
        suppliers,
        top,
        maxSum: top[0]?.[1].sum || 1,
        months,
        monthData,
        maxM: Math.max(...months.map((m) => monthData[m]), 1),
        topPaid: [...byPay.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8),
    };
});
const MONTHS: Record<string, string> = { '01': 'Янв', '02': 'Фев', '03': 'Мар', '04': 'Апр', '05': 'Май', '06': 'Июн', '07': 'Июл', '08': 'Авг', '09': 'Сен', '10': 'Окт', '11': 'Ноя', '12': 'Дек' };

// ── Debts and advances, all periods ──
interface DebtRow { name: string; bin: string; esf: number; paid: number; debt: number }
const debtRows = computed<DebtRow[]>(() => {
    const d = props.data;
    if (!d) return [];
    const allPaid = d.payments.filter((t) => isLegalEntity(t.counterparty));
    const nameToBin: Record<string, string> = {};
    const register = (raw: string | null, bin: string | null) => {
        if (!bin || !bin.trim()) return;
        const n = normSupplier(raw);
        if (n && !nameToBin[n]) nameToBin[n] = bin.trim();
    };
    d.invoices.forEach((i) => register(i.supplier, i.bin));
    allPaid.forEach((t) => register(t.counterparty, t.bin));
    d.suppliers.forEach((s) => register(s.name, s.bin));
    // One company may carry several BINs (a branch, a lost leading zero): a record's own BIN and the BIN of its name are joined.
    const parent: Record<string, string> = {};
    const find = (b: string): string => {
        while (parent[b] && parent[b] !== b) b = parent[b];
        return b;
    };
    const union = (a: string, b: string) => {
        if (!a || !b) return;
        a = find(a);
        b = find(b);
        if (a === b) return;
        if (b.length > a.length) parent[a] = b;
        else parent[b] = a;
    };
    const unify = (raw: string | null, bin: string | null | undefined) => {
        const own = (bin && bin.trim()) || '';
        const byName = nameToBin[normSupplier(raw)] || '';
        if (own && byName) union(own, byName);
    };
    const supplierFor = (name: string) => {
        const n = normSupNameMerge(name);
        if (!n) return null;
        return d.suppliers.find((s) => normSupNameMerge(s.name) === n) || d.suppliers.find((s) => { const sn = normSupNameMerge(s.name); return sn && (sn.includes(n) || n.includes(sn)); }) || null;
    };
    d.invoices.forEach((i) => unify(i.supplier, i.bin));
    allPaid.forEach((t) => unify(t.counterparty, t.bin));
    d.suppliers.forEach((s) => unify(s.name, s.bin));
    d.advanceLines.forEach((l) => l.supplier && unify(l.supplier, supplierFor(l.supplier)?.bin));
    const effBin = (raw: string, bin: string | null | undefined) => {
        const b = (bin && bin.trim()) || nameToBin[normSupplier(raw)] || '';
        return b ? find(b) : '';
    };
    const key = (raw: string, bin: string | null | undefined) => (effBin(raw, bin) ? 'bin:' + effBin(raw, bin) : 'name:' + normSupplier(raw));
    const groups = new Map<string, { esf: number; paid: number; display: string; bin: string }>();
    const add = (raw: string, bin: string | null | undefined, esf: number, paid: number) => {
        const k = key(raw, bin);
        const g = groups.get(k) ?? { esf: 0, paid: 0, display: cleanName(raw), bin: effBin(raw, bin) };
        if (cleanName(raw).length > (g.display || '').length) g.display = cleanName(raw);
        g.esf += esf;
        g.paid += paid;
        groups.set(k, g);
    };
    d.invoices.forEach((i) => add(i.supplier || i.bin || '—', i.bin, Number(i.total), 0));
    allPaid.forEach((t) => add(t.counterparty || '—', t.bin, 0, Number(t.amount)));
    d.advanceLines.forEach((l) => {
        if (l.supplier && Number(l.amount) > 0) add(l.supplier, supplierFor(l.supplier)?.bin, 0, Number(l.amount));
    });
    return [...groups.entries()]
        .map(([k, g]) => ({ name: g.display || k, bin: g.bin || '', esf: g.esf, paid: g.paid, debt: g.esf - g.paid }))
        .filter((r) => Math.abs(r.debt) >= 100)
        .sort((a, b) => b.debt - a.debt);
});
const search = ref('');
const shownDebts = computed(() => {
    const s = search.value.toLowerCase();
    return debtRows.value.filter((r) => !s || r.name.toLowerCase().includes(s) || (r.bin || '').includes(s));
});

// ── Supplier ledger (legacy openSupplierReconciliation) ──
const esfReconNorm = (s: string | null) => (s || '').toLowerCase().replace(/[«»"']/g, '').replace(/\s+/g, ' ').trim();
const ledger = reactive({ open: false, supplier: '', from: '', to: '' });
function openSupplierReconciliation(name: string, from = '', to = ''): void {
    Object.assign(ledger, { open: true, supplier: name, from, to });
}
const card = computed(() => {
    const d = props.data;
    if (!d || !ledger.open) return null;
    const sNorm = esfReconNorm(ledger.supplier);
    const sBin = (d.suppliers.find((s) => esfReconNorm(s.name) === sNorm)?.bin || '').trim();
    const matches = (name: string | null, bin: string | null) => {
        if (sBin && bin && bin.trim() === sBin) return true;
        const n = esfReconNorm(name);
        if (!n) return false;
        if (n === sNorm) return true;
        return sNorm.length >= 6 && n.length >= 6 && (n.includes(sNorm) || sNorm.includes(n));
    };
    const invoices = d.invoices.filter((i) => matches(i.supplier, i.bin));
    const direct = d.payments.filter((t) => matches(t.counterparty, t.bin));
    const advance = d.advanceLines.filter((l) => matches(l.supplier, ''));
    const docs = [
        ...invoices.map((i) => ({ date: i.date || '', kind: '📄 ЭСФ', doc: i.num || '—', credit: Number(i.total), debit: 0, note: i.status || '' })),
        ...direct.map((t) => ({ date: t.date || '', kind: '💳 Оплата (РС/касса)', doc: '', credit: 0, debit: Number(t.amount), note: (t.desc || '').slice(0, 70) })),
        ...advance.map((l) => ({ date: l.date || '', kind: '🧾 Через авансовый отчёт', doc: '', credit: 0, debit: Number(l.amount), note: (l.employee ? l.employee + ' — ' : '') + (l.desc || '') })),
    ].sort((a, b) => (a.date || '').localeCompare(b.date || ''));
    const { from, to } = ledger;
    const saldoStart = from ? docs.filter((x) => x.date < from).reduce((s, x) => s + x.credit - x.debit, 0) : 0;
    const period = docs.filter((x) => (!from || x.date >= from) && (!to || x.date <= to));
    let running = saldoStart;
    const rows = period.map((x) => ({ ...x, running: (running += x.credit - x.debit) }));
    const credit = period.reduce((s, x) => s + x.credit, 0);
    const debit = period.reduce((s, x) => s + x.debit, 0);
    return { sBin, invoices: invoices.length, payments: direct.length + advance.length, saldoStart, rows, credit, debit, saldoEnd: saldoStart + credit - debit };
});
const saldoColor = (n: number) => (n > 0 ? 'var(--red)' : n < 0 ? 'var(--accent)' : 'var(--text3)');
const mono = `'IBM Plex Mono',monospace`;
</script>

<template>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px">
        <select id="pur-period" v-model="f.period" style="font-size: 13px">
            <option value="month">Текущий месяц</option>
            <option value="quarter">Квартал</option>
            <option value="year">Год</option>
            <option value="custom">Произвольный</option>
        </select>
        <input id="pur-from" v-model="f.from" type="date" :style="{ fontSize: '13px', display: f.period === 'custom' ? '' : 'none' }" />
        <span id="pur-from-to" :style="{ fontSize: '13px', display: f.period === 'custom' ? '' : 'none' }">—</span>
        <input id="pur-to" v-model="f.to" type="date" :style="{ fontSize: '13px', display: f.period === 'custom' ? '' : 'none' }" />
        <button class="btn btn-ghost btn-sm" @click="router.reload({ only: ['purchases'] })">🔄</button>
    </div>

    <div id="pur-kpi-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px">
        <div class="card" style="padding: 14px">
            <div style="font-size: 11px; color: var(--text3)">Всего закупок (ЭСФ)</div>
            <div id="pur-k1" style="font-size: 22px; font-weight: 700; color: var(--accent)">{{ dash ? dash.count.toLocaleString('ru-RU') : '—' }}</div>
        </div>
        <div class="card" style="padding: 14px">
            <div style="font-size: 11px; color: var(--text3)">Сумма закупок с НДС</div>
            <div id="pur-k2" style="font-size: 22px; font-weight: 700; color: var(--green)">{{ dash ? fmt(dash.totalSum) : '—' }}</div>
        </div>
        <div class="card" style="padding: 14px">
            <div style="font-size: 11px; color: var(--text3)">Оплачено поставщикам</div>
            <div id="pur-k3" style="font-size: 22px; font-weight: 700; color: var(--amber)">{{ dash ? fmt(dash.totalPaid) : '—' }}</div>
        </div>
        <div class="card" style="padding: 14px">
            <div style="font-size: 11px; color: var(--text3)">Уникальных поставщиков</div>
            <div id="pur-k4" style="font-size: 22px; font-weight: 700">{{ dash ? dash.suppliers : '—' }}</div>
        </div>
    </div>

    <template v-if="dash">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px">
            <div class="card" style="padding: 16px">
                <div style="font-weight: 600; font-size: 14px; margin-bottom: 12px">🏆 Топ поставщиков по сумме ЭСФ</div>
                <div id="pur-top-suppliers">
                    <div v-for="[name, d] in dash.top" :key="name" style="margin-bottom: 8px">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 2px">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px" :title="name">{{ name }}</span>
                            <span style="font-weight: 600; font-family: monospace">{{ fmt(d.sum) }}</span>
                        </div>
                        <div style="background: var(--bg3); border-radius: 3px; height: 6px">
                            <div :style="{ background: 'var(--accent)', borderRadius: '3px', height: '6px', width: Math.round((d.sum / dash.maxSum) * 100) + '%' }"></div>
                        </div>
                    </div>
                    <div v-if="!dash.top.length" style="color: var(--text3); font-size: 13px">Нет данных</div>
                </div>
            </div>
            <div class="card" style="padding: 16px">
                <div style="font-weight: 600; font-size: 14px; margin-bottom: 12px">📈 Динамика закупок по месяцам</div>
                <div id="pur-monthly-chart" style="display: flex; align-items: flex-end; gap: 4px; height: 140px">
                    <div v-for="m in dash.months" :key="m" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px" :title="fmt2(dash.monthData[m])">
                        <div style="font-size: 9px; color: var(--text3)">{{ fmt(dash.monthData[m]) }}</div>
                        <div :style="{ background: 'var(--accent)', borderRadius: '3px 3px 0 0', width: '100%', height: Math.max(4, Math.round((dash.monthData[m] / dash.maxM) * 120)) + 'px' }"></div>
                    </div>
                </div>
                <div id="pur-monthly-labels" style="display: flex; gap: 4px; margin-top: 4px; font-size: 10px; color: var(--text3)">
                    <div v-for="m in dash.months" :key="m" style="flex: 1; text-align: center">{{ MONTHS[m.slice(5)] || m.slice(5) }} {{ m.slice(2, 4) }}</div>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
            <div class="card" style="padding: 16px">
                <div style="font-weight: 600; font-size: 14px; margin-bottom: 12px">💳 Оплата поставщикам (из журнала)</div>
                <div id="pur-payments-list">
                    <template v-if="dash.topPaid.length">
                        <div v-for="[name, sum] in dash.topPaid" :key="name" style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid var(--border); font-size: 12px">
                            <span style="color: var(--text2)">{{ name }}</span>
                            <span style="font-weight: 600; font-family: monospace; color: var(--green)">{{ fmt2(sum) }}</span>
                        </div>
                        <div style="padding-top: 8px; font-size: 12px; color: var(--text3)">Итого: {{ fmt2(dash.totalPaid) }}</div>
                    </template>
                    <div v-else style="color: var(--text3); font-size: 13px">Нет оплат за период</div>
                </div>
            </div>
            <div class="card" style="padding: 16px">
                <div style="font-weight: 600; font-size: 14px; margin-bottom: 12px">📊 Структура по поставщикам</div>
                <div id="pur-structure">
                    <div v-for="[name, d] in dash.top.slice(0, 6)" :key="name" style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid var(--border); font-size: 12px">
                        <span style="color: var(--text2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px">{{ name }}</span>
                        <span style="font-family: monospace; color: var(--text3)">{{ Math.round((d.sum / dash.totalSum) * 100) }}% · {{ d.count }} ЭСФ</span>
                    </div>
                    <div v-if="!dash.top.length" style="color: var(--text3); font-size: 13px">Нет данных</div>
                </div>
            </div>
        </div>
    </template>

    <div class="card" style="padding: 16px; margin-top: 16px">
        <div style="font-weight: 600; font-size: 14px; margin-bottom: 12px">⚖️ Долги и авансы поставщикам</div>
        <div style="font-size: 12px; color: var(--text3); margin-bottom: 10px">
            Сравнение суммы ЭСФ (поставлено) и оплат из журнала расходов. Долг = поставлено − оплачено. Аванс = оплачено − поставлено.
        </div>
        <div style="margin-bottom: 10px">
            <input
                id="pur-debt-search"
                v-model="search"
                type="search"
                placeholder="🔍 Поиск поставщика..."
                style="font-size: 13px; padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1); width: 280px"
            />
        </div>
        <div style="overflow: auto">
            <table class="data-table" style="font-size: 12px">
                <thead>
                    <tr>
                        <th style="text-align: left">Поставщик</th>
                        <th style="text-align: left">БИН/ИИН</th>
                        <th style="text-align: right">ЭСФ (поставлено)</th>
                        <th style="text-align: right">Оплачено</th>
                        <th style="text-align: right">Долг</th>
                        <th style="text-align: right">Аванс</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="pur-debts-tbody">
                    <tr v-for="(r, i) in shownDebts" :key="i" v-memo="[r]">
                        <td style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" :title="r.name">{{ r.name }}</td>
                        <td style="font-family: monospace; color: var(--text3); font-size: 11px">{{ r.bin || '—' }}</td>
                        <td style="text-align: right; font-family: monospace">{{ fmt2(r.esf) }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt2(r.paid) }}</td>
                        <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: r.debt > 0 ? 'var(--red)' : 'var(--text3)', fontWeight: r.debt > 0 ? 700 : 400 }">{{ r.debt > 0 ? fmt2(r.debt) : '—' }}</td>
                        <td :style="{ textAlign: 'right', fontFamily: 'monospace', color: r.debt < 0 ? 'var(--accent)' : 'var(--text3)' }">{{ r.debt < 0 ? fmt2(-r.debt) : '—' }}</td>
                        <td>
                            <span v-if="r.debt > 0" style="color: var(--red); font-size: 11px">⚠️ Долг</span>
                            <span v-else style="color: var(--green); font-size: 11px">✅ Аванс</span>
                        </td>
                        <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openSupplierReconciliation(r.name)">👁</button></td>
                    </tr>
                    <tr v-if="!shownDebts.length">
                        <td colspan="8" style="text-align: center; padding: 12px; color: var(--text3)">Нет данных</td>
                    </tr>
                </tbody>
                <tfoot id="pur-debts-tfoot">
                    <tr style="font-weight: 700; background: var(--bg3)">
                        <td>ИТОГО ({{ shownDebts.length }} поставщиков)</td>
                        <td></td>
                        <td style="text-align: right; font-family: monospace">{{ fmt2(shownDebts.reduce((s, r) => s + r.esf, 0)) }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt2(shownDebts.reduce((s, r) => s + r.paid, 0)) }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--red)">{{ fmt2(shownDebts.filter((r) => r.debt > 0).reduce((s, r) => s + r.debt, 0)) }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--accent)">{{ fmt2(shownDebts.filter((r) => r.debt < 0).reduce((s, r) => s - r.debt, 0)) }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <ErpModal id="supplier-card-modal" :open="ledger.open" :modal-style="{ maxWidth: '860px', maxHeight: '88vh', overflowY: 'auto' }" @close="ledger.open = false">
        <template v-if="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
                <div>
                    <div style="font-weight: 700; font-size: 15px">
                        {{ ledger.supplier }}<template v-if="card.sBin">{{ ' ' }}<span :style="{ fontFamily: mono, fontSize: '12px', color: 'var(--text3)' }">БИН {{ card.sBin }}</span></template>
                    </div>
                    <div style="font-size: 12px; color: var(--text3); margin-top: 2px">{{ card.invoices }} ЭСФ · {{ card.payments }} оплат</div>
                </div>
                <button class="btn btn-ghost btn-sm" @click="ledger.open = false">✕</button>
            </div>
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap">
                <span style="font-size: 12px; color: var(--text3)">Период:</span>
                <input id="sc-from" v-model="ledger.from" type="date" style="font-size: 12px" />
                <span style="font-size: 12px; color: var(--text3)">—</span>
                <input id="sc-to" v-model="ledger.to" type="date" style="font-size: 12px" />
                <button class="btn btn-ghost btn-sm" @click="Object.assign(ledger, { from: '', to: '' })">Всё время</button>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px">
                <div class="card" style="padding: 10px 14px">
                    <div style="font-size: 10px; color: var(--text3)">Сальдо на начало</div>
                    <div :style="{ fontSize: '14px', fontWeight: 700, color: saldoColor(card.saldoStart) }">
                        {{ ledger.from ? fmt2(Math.abs(card.saldoStart)) + (card.saldoStart > 0 ? ' долг' : card.saldoStart < 0 ? ' аванс' : ' ✓') : '—' }}
                    </div>
                </div>
                <div class="card" style="padding: 10px 14px">
                    <div style="font-size: 10px; color: var(--text3)">Оборот по кредиту (поступление/ЭСФ)</div>
                    <div style="font-size: 14px; font-weight: 700; color: var(--accent)">{{ fmt2(card.credit) }}</div>
                </div>
                <div class="card" style="padding: 10px 14px">
                    <div style="font-size: 10px; color: var(--text3)">Оборот по дебету (оплата)</div>
                    <div style="font-size: 14px; font-weight: 700; color: var(--green)">{{ fmt2(card.debit) }}</div>
                </div>
                <div class="card" :style="{ padding: '10px 14px', border: '2px solid ' + (card.saldoEnd > 0 ? 'var(--red)' : 'var(--green)') }">
                    <div style="font-size: 10px; color: var(--text3)">Сальдо на конец</div>
                    <div :style="{ fontSize: '14px', fontWeight: 700, color: saldoColor(card.saldoEnd) }">
                        {{ fmt2(Math.abs(card.saldoEnd)) }} {{ card.saldoEnd > 0 ? 'долг' : card.saldoEnd < 0 ? 'аванс' : '✓' }}
                    </div>
                </div>
            </div>
            <div style="font-weight: 600; font-size: 13px; margin-bottom: 8px">Ведомость операций (бегущее сальдо)</div>
            <div style="max-height: 50vh; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px">
                <table class="data-table" style="font-size: 12px">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Документ</th>
                            <th>Примечание</th>
                            <th style="text-align: right">Кредит (поступление)</th>
                            <th style="text-align: right">Дебет (оплата)</th>
                            <th style="text-align: right">Сальдо</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(d, i) in card.rows" :key="i">
                            <td style="white-space: nowrap">{{ (d.date || '').slice(0, 10) }}</td>
                            <td style="font-size: 11px">{{ d.kind }}{{ d.doc ? ' №' + d.doc : '' }}</td>
                            <td style="font-size: 11px; color: var(--text3); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" :title="d.note || ''">{{ d.note || '' }}</td>
                            <td :style="{ textAlign: 'right', fontFamily: mono, color: 'var(--accent)' }">{{ d.credit ? fmt2(d.credit) : '' }}</td>
                            <td :style="{ textAlign: 'right', fontFamily: mono, color: 'var(--green)' }">{{ d.debit ? fmt2(d.debit) : '' }}</td>
                            <td :style="{ textAlign: 'right', fontFamily: mono, fontWeight: 600, color: saldoColor(d.running) }">
                                {{ fmt2(Math.abs(d.running)) }}{{ d.running !== 0 ? (d.running > 0 ? ' долг' : ' аванс') : '' }}
                            </td>
                        </tr>
                        <tr v-if="!card.rows.length">
                            <td colspan="6" style="text-align: center; color: var(--text3); padding: 16px">Нет операций за период</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </ErpModal>
</template>
