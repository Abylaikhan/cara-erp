<script setup lang="ts">
// M19 «Зарплатная ведомость», part 1 — legacy page-payroll (L7707–7770), fillPayrollMonthSelects/onPayrollMonthChange/
// renderSalaryLedger/togglePayrollCols/openPayrollRowMenu/prPayoutsPeriod/showPayouts/prPayoutRows/renderPayouts
// (L13590–14090). By employee for a month or a period: the opening balance, the accruals by kind, the deductions, what is to be
// paid, what was paid out and the closing balance, summed from the payroll records; the journal of payouts. Legacy drew the
// ledger only after a filter was changed and its search did not filter it: here both work at once (ADR-0002). Accruing,
// paying and the Excel files come with the payroll rules and the exports (ERP-057, ERP-067, D-06).
import { router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { downloadXlsx } from '@/lib/xlsx';

interface Line {
    type?: string;
    amount: number;
}
interface Accrual {
    id: string;
    emp_name: string;
    date: string;
    amount: number;
    deducted: number;
    net: number;
    details: { accruals?: Line[]; deductions?: Line[] } | null;
}
interface Payment {
    emp_name: string;
    date: string;
    amount: number;
    comment: string | null;
}
const props = defineProps<{ today: string; accruals: Accrual[]; payments: Payment[] }>();

const LATER = {
    accrual: 'Начисление появится вместе с правилами расчёта зарплаты (ERP-056, решение D-06).',
};
const later = (text: string) => window.alert(text);
const MN = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

// Legacy fillPayrollMonthSelects: from the later of the current month and the latest record down to November 2025; the
// latest month with records is chosen.
const monthList = computed(() => {
    const [ty, tm] = props.today.split('-').map(Number);
    let last = ty * 12 + tm - 1;
    let latest: number | null = null;
    [...props.accruals, ...props.payments].forEach((r) => {
        const m = /^(\d{4})-(\d{2})/.exec(r.date || '');
        if (m) {
            const v = +m[1] * 12 + +m[2] - 1;
            if (latest === null || v > latest) latest = v;
            if (v > last) last = v;
        }
    });
    const out: { value: string; label: string }[] = [];
    for (let v = last; v >= 2025 * 12 + 10; v--) out.push({ value: `${Math.floor(v / 12)}-${String((v % 12) + 1).padStart(2, '0')}`, label: `${MN[v % 12]} ${Math.floor(v / 12)}` });
    const d = latest ?? last;
    return { out, def: `${Math.floor(d / 12)}-${String((d % 12) + 1).padStart(2, '0')}` };
});
const f = reactive({ month: monthList.value.def, from: '', to: '', dept: 'all', search: '' });
function onPayrollMonthChange(): void {
    if (f.month) Object.assign(f, { from: '', to: '' });
}
const manual = (field: 'from' | 'to', value: string) => Object.assign(f, { month: '', [field]: value });
const cols = reactive({ acc: true, ded: true });

const fmt = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');

// ── Legacy renderSalaryLedger ──
const ledger = computed(() => {
    const dateFrom = f.month ? f.month + '-01' : f.from;
    const dateTo = f.month ? f.month + '-31' : f.to;
    const search = f.search.toLowerCase();
    const empMap = new Map<string, { id: string | null; emp_name: string; date: string; amount: number; deducted: number; net: number; acc: Line[]; ded: Line[] }>();
    for (const a of props.accruals) {
        if ((dateFrom && a.date < dateFrom) || (dateTo && a.date > dateTo) || (search && !a.emp_name?.toLowerCase().includes(search))) continue;
        const g = empMap.get(a.emp_name) ?? { id: a.id, emp_name: a.emp_name, date: a.date, amount: 0, deducted: 0, net: 0, acc: [], ded: [] };
        g.amount += a.amount || 0;
        g.deducted += a.deducted || 0;
        g.net += a.net || 0;
        g.acc.push(...(a.details?.accruals || []));
        g.ded.push(...(a.details?.deductions || []));
        empMap.set(a.emp_name, g);
    }
    const allPayments = props.payments;
    const allAccruals = props.accruals;
    // Those with a balance from before the period and no accrual in it
    if (dateFrom) {
        const prevNames = new Set([...allAccruals.filter((a) => a.date < dateFrom).map((a) => a.emp_name), ...allPayments.filter((p) => p.date < dateFrom).map((p) => p.emp_name)]);
        for (const name of prevNames) {
            if (empMap.has(name) || (search && !name.toLowerCase().includes(search))) continue;
            const prevAcc = allAccruals.filter((x) => x.emp_name === name && x.date < dateFrom).reduce((s, x) => s + (x.net || 0), 0);
            const prevPay = allPayments.filter((p) => p.emp_name === name && p.date < dateFrom).reduce((s, p) => s + (p.amount || 0), 0);
            if (Math.abs(prevAcc - prevPay) < 1) continue;
            empMap.set(name, { id: null, emp_name: name, date: dateFrom, amount: 0, deducted: 0, net: 0, acc: [], ded: [] });
        }
    }
    const rows = [...empMap.values()]
        .sort((a, b) => a.emp_name.localeCompare(b.emp_name, 'ru'))
        .map((a) => {
            const getAcc = (...keys: string[]) => a.acc.filter((x) => keys.some((k) => x.type?.includes(k))).reduce((s, x) => s + x.amount, 0);
            const getDed = (...keys: string[]) => a.ded.filter((x) => keys.some((k) => x.type?.includes(k))).reduce((s, x) => s + x.amount, 0);
            const hasDetails = a.acc.length > 0;
            const monthStart = dateFrom || a.date.substring(0, 7) + '-01';
            const nachalo =
                allAccruals.filter((x) => x.emp_name === a.emp_name && x.date < monthStart).reduce((s, x) => s + (x.net || 0), 0) -
                allPayments.filter((p) => p.emp_name === a.emp_name && p.date < monthStart).reduce((s, p) => s + (p.amount || 0), 0);
            const vydano = allPayments.filter((p) => p.emp_name === a.emp_name && p.date >= monthStart && (!dateTo || p.date <= dateTo)).reduce((s, p) => s + (p.amount || 0), 0);
            const ostatok = nachalo + (a.net || 0) - vydano;
            return {
                a,
                nachalo,
                nachColor: nachalo > 0 ? 'var(--amber)' : nachalo < 0 ? 'var(--red)' : 'var(--text3)',
                oklad: getAcc('Оклад') || (hasDetails ? 0 : a.amount || 0),
                sdel: hasDetails ? getAcc('Сдельная') : 0,
                premiya: getAcc('Премия'),
                otpusk: getAcc('Отпускные'),
                zaimN: getAcc('Займ'),
                boln: getAcc('Больничный'),
                acc: a.amount || 0,
                obed: getDed('Обед'),
                zaimP: getDed('Погашение займа'),
                opv: getDed('Пенсионные'),
                osms: getDed('Медицинское', 'Социальный'),
                shtraf: getDed('Штраф'),
                prochie: getDed('ИПН', 'Алименты', 'Корректировка'),
                ded: a.deducted || 0,
                kv: a.net || 0,
                vydano,
                ostatok,
                ostColor: ostatok > 0 ? 'var(--amber)' : ostatok < 0 ? 'var(--red)' : 'var(--green)',
            };
        });
    const keys = ['nachalo', 'oklad', 'sdel', 'premiya', 'otpusk', 'zaimN', 'boln', 'acc', 'obed', 'zaimP', 'opv', 'osms', 'shtraf', 'prochie', 'ded', 'kv', 'vydano', 'ostatok'] as const;
    const totals = Object.fromEntries(keys.map((k) => [k, rows.reduce((s, r) => s + r[k], 0)])) as Record<(typeof keys)[number], number>;
    return { rows, totals };
});

// ── Row menu (legacy openPayrollRowMenu) ──
const menu = reactive({ open: false, top: 0, left: 0, name: '' });
function openMenu(e: MouseEvent, name: string): void {
    const rect = (e.target as HTMLElement).getBoundingClientRect();
    Object.assign(menu, { open: true, top: rect.bottom + 4, left: Math.min(rect.left, window.innerWidth - 220), name });
    setTimeout(() => document.addEventListener('click', () => (menu.open = false), { once: true }), 50);
}
const toCard = (name: string) => router.visit('/hr?emp=' + encodeURIComponent(name));
const toAccruals = (name: string) => router.visit('/accruals?search=' + encodeURIComponent(name));

// ── Журнал выдач (legacy prPayoutsPeriod/showPayouts/renderPayouts) ──
const po = reactive({ open: false, search: '', mode: 'date' });
const poPeriod = computed(() => {
    const from = f.month ? f.month + '-01' : f.from;
    const to = f.month ? f.month + '-31' : f.to;
    return { from, to, label: f.month ? `${MN[+f.month.slice(5) - 1]} ${f.month.slice(0, 4)}` : (from || '…') + ' — ' + (to || '…') };
});
function showPayouts(emp = ''): void {
    Object.assign(po, { open: true, search: emp, mode: 'date' });
}
const poRows = computed(() => {
    const { from, to } = poPeriod.value;
    const q = po.search.toLowerCase().trim();
    return props.payments
        .filter((p) => (!from || p.date >= from) && (!to || p.date <= to) && (!q || String(p.emp_name || '').toLowerCase().includes(q)))
        .sort((a, b) => String(b.date).localeCompare(String(a.date)) || String(a.emp_name).localeCompare(String(b.emp_name), 'ru'));
});
const poByEmp = computed(() => {
    const by: Record<string, { n: number; sum: number; last: string }> = {};
    poRows.value.forEach((p) => {
        const k = p.emp_name || '—';
        const v = (by[k] ??= { n: 0, sum: 0, last: '' });
        v.n++;
        v.sum += +p.amount || 0;
        if (String(p.date) > v.last) v.last = p.date;
    });
    return Object.entries(by).sort((a, b) => a[0].localeCompare(b[0], 'ru'));
});
const poTotal = computed(() => poRows.value.reduce((t, p) => t + (+p.amount || 0), 0));

// ── Excel (ADR-0044): legacy exportSalaryLedger (L14088) and exportPayouts (L13747) ──
function exportSalaryLedger(): void {
    const rows = ledger.value.rows;
    if (!rows.length) {
        window.alert('Сначала откройте ведомость');
        return;
    }
    const n = (v: number) => (v ? Math.round(v) : 0);
    const headers = ['Сотрудник', 'Остаток нач.', 'Оклад', 'Сдельная', 'Премия', 'Отпускные', 'Займ нач.', 'Больничные', 'Итого нач.', 'Обед', 'Займ погаш.', 'ОПВ', 'ОСМС/СО',
        'Штрафы', 'Прочие', 'Итого удерж.', 'К выдаче', 'Выдано', 'Остаток'];
    const data: (string | number)[][] = rows.map((r) => [r.a.emp_name, ...([r.nachalo, r.oklad, r.sdel, r.premiya, r.otpusk, r.zaimN, r.boln, r.acc, r.obed, r.zaimP, r.opv, r.osms, r.shtraf,
        r.prochie, r.ded, r.kv, r.vydano, r.ostatok].map(n))]);
    const totals: (string | number)[] = ['ИТОГО'];
    for (let col = 1; col < headers.length; col++) totals.push(data.reduce((s, r) => s + ((r[col] as number) || 0), 0));
    data.push(totals);
    void downloadXlsx('payroll', `Ведомость_ЗП_${f.month || `${f.from}_${f.to}`}.xlsx`, [{ name: 'Ведомость ЗП', rows: [headers, ...data], cols: [30, ...Array(headers.length - 1).fill(14)] }]);
}
function exportPayouts(): void {
    const rows = poRows.value;
    void downloadXlsx('payroll', 'Выдачи_ЗП_' + (f.month || 'период') + '.xlsx', [
        {
            name: 'Выдачи',
            rows: [['Выдачи ЗП · ' + poPeriod.value.label], [], ['Дата', 'Сотрудник', 'Комментарий', 'Сумма'], ...rows.map((p) => [p.date, p.emp_name, p.comment || '', +p.amount || 0]), [],
                ['', '', 'Итого', rows.reduce((t, p) => t + (+p.amount || 0), 0)]],
            cols: [12, 36, 30, 14],
        },
    ]);
}
const fmtAll = (n: number) => '₸' + Math.round(n || 0).toLocaleString('ru-RU');
const ru = (iso: string) => {
    const p = String(iso || '')
        .slice(0, 10)
        .split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : iso;
};
</script>

<template>
    <ErpLayout>
        <div id="page-payroll" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">💰 Зарплатная ведомость</div>
                    <div class="section-sub">Начисления, удержания, выдача</div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center">
                    <button class="btn btn-ghost btn-sm" @click="showPayouts()">💵 Журнал выдач</button>
                    <button class="btn btn-ghost btn-sm" @click="exportSalaryLedger">📥 Excel</button>
                </div>
            </div>

            <!-- Фильтры -->
            <div style="display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center">
                <select id="payroll-month" v-model="f.month" style="font-size: 13px" @change="onPayrollMonthChange">
                    <option value="">— Произвольный период —</option>
                    <option v-for="m in monthList.out" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <span style="font-size: 13px; color: var(--text3)">или вручную:</span>
                <input id="payroll-from" :value="f.from" type="date" style="font-size: 13px" @change="manual('from', ($event.target as HTMLInputElement).value)" />
                <span style="font-size: 13px; color: var(--text3)">—</span>
                <input id="payroll-to" :value="f.to" type="date" style="font-size: 13px" @change="manual('to', ($event.target as HTMLInputElement).value)" />
                <select id="payroll-dept" v-model="f.dept" style="font-size: 13px">
                    <option value="all">Все</option>
                    <option value="production">Производство / Офис</option>
                    <option value="salon">Салоны</option>
                </select>
                <input
                    id="payroll-search"
                    v-model="f.search"
                    type="search"
                    placeholder="🔍 Поиск..."
                    style="font-size: 13px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1); width: 180px"
                />
                <button class="btn btn-ghost btn-sm" @click="router.reload()">🔄</button>
            </div>

            <!-- Итоги -->
            <div id="payroll-kpi" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 16px">
                <div
                    v-for="[label, val, color] in [
                        ['👥 Сотрудников', String(ledger.rows.length), ''],
                        ['💰 Начислено', fmt(ledger.totals.acc), 'var(--green)'],
                        ['📉 Удержано', fmt(ledger.totals.ded), 'var(--red)'],
                        ['💳 К выдаче', fmt(ledger.totals.kv), 'var(--amber)'],
                        ['✅ Выдано', fmt(ledger.totals.vydano), 'var(--accent)'],
                    ]"
                    :key="label"
                    class="card"
                    style="padding: 12px 14px"
                >
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">{{ label }}</div>
                    <div :style="{ fontSize: '18px', fontWeight: 700, color: color || undefined }">{{ val }}</div>
                </div>
                <div class="card" style="padding: 10px 14px; display: flex; gap: 16px; align-items: center; grid-column: 1/-1">
                    <div>
                        <div style="font-size: 11px; color: var(--text3)">Остаток на начало</div>
                        <div :style="{ fontSize: '15px', fontWeight: 700, color: ledger.totals.nachalo >= 0 ? 'var(--amber)' : 'var(--red)' }">{{ fmt(ledger.totals.nachalo) }}</div>
                    </div>
                    <div style="color: var(--border2); font-size: 20px">→</div>
                    <div>
                        <div style="font-size: 11px; color: var(--text3)">Остаток на конец</div>
                        <div :style="{ fontSize: '15px', fontWeight: 700, color: ledger.totals.ostatok >= 0 ? 'var(--amber)' : 'var(--red)' }">{{ fmt(ledger.totals.ostatok) }}</div>
                    </div>
                </div>
            </div>

            <!-- Таблица -->
            <div style="overflow: auto; max-height: calc(100vh - 320px)">
                <table id="payroll-table" class="data-table" style="min-width: 1400px; font-size: 12px">
                    <thead>
                        <tr style="position: sticky; top: 0; background: var(--bg2); z-index: 1">
                            <th style="min-width: 160px; text-align: left">Сотрудник</th>
                            <th style="text-align: right; color: var(--text3)">Остаток нач.</th>
                            <template v-if="cols.acc">
                                <th class="col-acc" style="text-align: right; color: var(--accent)">Оклад</th>
                                <th class="col-acc" style="text-align: right; color: var(--accent)">Сдельная</th>
                                <th class="col-acc" style="text-align: right; color: var(--accent)">Премия</th>
                                <th class="col-acc" style="text-align: right; color: var(--accent)">Отпускные</th>
                                <th class="col-acc" style="text-align: right; color: var(--accent)">Займ нач.</th>
                                <th class="col-acc" style="text-align: right; color: var(--accent)">Больничные</th>
                            </template>
                            <th style="text-align: right; color: var(--green); font-weight: 700; cursor: pointer; white-space: nowrap" @click="cols.acc = !cols.acc">
                                <span id="toggle-acc-btn">{{ cols.acc ? '◀' : '▶' }}</span> Итого нач.
                            </th>
                            <template v-if="cols.ded">
                                <th class="col-ded" style="text-align: right; color: var(--red)">Обед</th>
                                <th class="col-ded" style="text-align: right; color: var(--red)">Займ погаш.</th>
                                <th class="col-ded" style="text-align: right; color: var(--red)">ОПВ</th>
                                <th class="col-ded" style="text-align: right; color: var(--red)">ОСМС/СО</th>
                                <th class="col-ded" style="text-align: right; color: var(--red)">Штрафы</th>
                                <th class="col-ded" style="text-align: right; color: var(--red)">Прочие</th>
                            </template>
                            <th style="text-align: right; color: var(--red); font-weight: 700; cursor: pointer; white-space: nowrap" @click="cols.ded = !cols.ded">
                                <span id="toggle-ded-btn">{{ cols.ded ? '◀' : '▶' }}</span> Итого удерж.
                            </th>
                            <th style="text-align: right; color: var(--amber); font-weight: 700">К выдаче</th>
                            <th style="text-align: right; color: var(--green)">Выдано</th>
                            <th style="text-align: right; font-weight: 700">Остаток</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="payroll-tbody">
                        <tr v-for="r in ledger.rows" :key="r.a.emp_name" v-memo="[r, cols.acc, cols.ded]" style="user-select: text">
                            <td style="font-weight: 500; white-space: nowrap; user-select: text">{{ r.a.emp_name }}</td>
                            <td :style="{ textAlign: 'right', userSelect: 'text', color: r.nachColor }">{{ r.nachalo ? fmt(r.nachalo) : '—' }}</td>
                            <template v-if="cols.acc">
                                <td v-for="(v, i) in [r.oklad, r.sdel, r.premiya, r.otpusk, r.zaimN, r.boln]" :key="i" class="col-acc" style="text-align: right; user-select: text">
                                    {{ v ? fmt(v) : '—' }}
                                </td>
                            </template>
                            <td style="text-align: right; font-weight: 700; color: var(--green); user-select: text">{{ fmt(r.acc) }}</td>
                            <template v-if="cols.ded">
                                <td
                                    v-for="(v, i) in [r.obed, r.zaimP, r.opv, r.osms, r.shtraf, r.prochie]"
                                    :key="i"
                                    class="col-ded"
                                    style="text-align: right; color: var(--red); user-select: text"
                                >
                                    {{ v ? fmt(v) : '—' }}
                                </td>
                            </template>
                            <td style="text-align: right; font-weight: 700; color: var(--red); user-select: text">{{ r.ded ? fmt(r.ded) : '—' }}</td>
                            <td style="text-align: right; font-weight: 700; color: var(--amber); user-select: text">{{ fmt(r.kv) }}</td>
                            <td style="text-align: right; color: var(--green); user-select: text">
                                <a
                                    v-if="r.vydano"
                                    href="#"
                                    style="color: var(--green); text-decoration: underline dotted"
                                    title="Журнал выдач по сотруднику"
                                    @click.prevent="showPayouts(r.a.emp_name)"
                                    >{{ fmt(r.vydano) }}</a
                                ><template v-else>—</template>
                            </td>
                            <td :style="{ textAlign: 'right', fontWeight: 700, color: r.ostColor, userSelect: 'text' }">{{ fmt(r.ostatok) }}</td>
                            <td style="position: relative">
                                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click.stop="openMenu($event, r.a.emp_name)">⋯</button>
                            </td>
                        </tr>
                        <tr v-if="!ledger.rows.length">
                            <td colspan="18" style="text-align: center; padding: 20px; color: var(--text3)">Нет данных за этот период</td>
                        </tr>
                    </tbody>
                    <tfoot id="payroll-tfoot">
                        <tr style="font-weight: 700; background: var(--bg3); border-top: 2px solid var(--border)">
                            <td>ИТОГО ({{ ledger.rows.length }} чел.)</td>
                            <template
                                v-for="[k, color, cls] in [
                                    ['nachalo', '', ''],
                                    ...(cols.acc
                                        ? [
                                              ['oklad', 'var(--accent)', 'col-acc'],
                                              ['sdel', 'var(--accent)', 'col-acc'],
                                              ['premiya', 'var(--accent)', 'col-acc'],
                                              ['otpusk', 'var(--accent)', 'col-acc'],
                                              ['zaimN', 'var(--accent)', 'col-acc'],
                                              ['boln', 'var(--accent)', 'col-acc'],
                                          ]
                                        : []),
                                    ['acc', 'var(--green)', ''],
                                    ...(cols.ded
                                        ? [
                                              ['obed', 'var(--red)', 'col-ded'],
                                              ['zaimP', 'var(--red)', 'col-ded'],
                                              ['opv', 'var(--red)', 'col-ded'],
                                              ['osms', 'var(--red)', 'col-ded'],
                                              ['shtraf', 'var(--red)', 'col-ded'],
                                              ['prochie', 'var(--red)', 'col-ded'],
                                          ]
                                        : []),
                                    ['ded', 'var(--red)', ''],
                                    ['kv', 'var(--amber)', ''],
                                    ['vydano', 'var(--green)', ''],
                                    ['ostatok', ledger.totals.ostatok >= 0 ? 'var(--amber)' : 'var(--red)', ''],
                                ] as [keyof typeof ledger.totals, string, string][]"
                                :key="k"
                            >
                                <td v-if="ledger.totals[k]" :class="cls || undefined" :style="{ textAlign: 'right', fontWeight: 700, color: color || undefined, userSelect: 'text' }">
                                    {{ fmt(ledger.totals[k]) }}
                                </td>
                                <td v-else :class="cls || undefined" style="text-align: right; color: var(--text3)">—</td>
                            </template>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div
            v-if="menu.open"
            class="payroll-row-menu"
            :style="{
                position: 'fixed',
                zIndex: 999,
                background: 'var(--bg2)',
                border: '1px solid var(--border)',
                borderRadius: '8px',
                boxShadow: '0 4px 16px rgba(0,0,0,0.3)',
                minWidth: '200px',
                overflow: 'hidden',
                fontSize: '13px',
                top: menu.top + 'px',
                left: menu.left + 'px',
            }"
        >
            <div style="padding: 6px 0">
                <div style="padding: 8px 14px; cursor: pointer; display: flex; gap: 8px; align-items: center" @mouseover="($event.currentTarget as HTMLElement).style.background = 'var(--bg3)'" @mouseout="($event.currentTarget as HTMLElement).style.background = ''" @click="later(LATER.accrual)">✏️ Новое начисление</div>
                <div style="padding: 8px 14px; cursor: pointer; display: flex; gap: 8px; align-items: center" @mouseover="($event.currentTarget as HTMLElement).style.background = 'var(--bg3)'" @mouseout="($event.currentTarget as HTMLElement).style.background = ''" @click="toCard(menu.name)">👤 Карточка сотрудника</div>
                <div style="padding: 8px 14px; cursor: pointer; display: flex; gap: 8px; align-items: center" @mouseover="($event.currentTarget as HTMLElement).style.background = 'var(--bg3)'" @mouseout="($event.currentTarget as HTMLElement).style.background = ''" @click="toAccruals(menu.name)">📋 Операции за период</div>
            </div>
        </div>

        <ErpModal id="payoutsModal" :open="po.open" :modal-style="{ maxWidth: '820px', width: '96vw', maxHeight: '90vh', overflow: 'auto' }" @close="po.open = false">
            <div class="modal-header">
                <div id="po-title" class="modal-title">Выдачи ЗП · {{ poPeriod.label }}</div>
                <button class="modal-close" @click="po.open = false">✕</button>
            </div>
            <div style="display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center">
                <input id="po-search" v-model="po.search" type="search" placeholder="🔍 Сотрудник..." style="font-size: 13px; width: 220px" />
                <select id="po-mode" v-model="po.mode" style="font-size: 13px; width: auto">
                    <option value="date">По датам</option>
                    <option value="emp">Итого по сотрудникам</option>
                </select>
                <button class="btn btn-ghost btn-sm" style="margin-left: auto" @click="exportPayouts">📥 Excel</button>
            </div>
            <div style="overflow: auto">
                <table class="data-table">
                    <thead id="po-thead">
                        <tr v-if="po.mode === 'emp'">
                            <th>Сотрудник</th>
                            <th style="text-align: right">Выдач</th>
                            <th>Последняя</th>
                            <th style="text-align: right">Выдано всего</th>
                        </tr>
                        <tr v-else>
                            <th>Дата</th>
                            <th>Сотрудник</th>
                            <th>Комментарий</th>
                            <th style="text-align: right">Сумма</th>
                        </tr>
                    </thead>
                    <tbody id="po-tbody">
                        <tr v-if="!poRows.length">
                            <td colspan="4" style="text-align: center; padding: 20px; color: var(--text3)">За выбранный период выдач нет</td>
                        </tr>
                        <template v-else-if="po.mode === 'emp'">
                            <tr v-for="[k, v] in poByEmp" :key="k">
                                <td>{{ k }}</td>
                                <td style="text-align: right">{{ v.n }}</td>
                                <td>{{ ru(v.last) }}</td>
                                <td style="text-align: right; font-family: monospace">{{ fmtAll(v.sum) }}</td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr v-for="(p, i) in poRows" :key="i">
                                <td style="white-space: nowrap">{{ ru(p.date) }}</td>
                                <td>{{ p.emp_name }}</td>
                                <td style="color: var(--text3); font-size: 12px">{{ p.comment || '' }}</td>
                                <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmtAll(p.amount) }}</td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot id="po-tfoot">
                        <tr v-if="poRows.length" style="font-weight: 700; background: var(--bg3)">
                            <td colspan="3" style="text-align: right">Итого ({{ poRows.length }} выдач):</td>
                            <td style="text-align: right; font-family: monospace">{{ fmtAll(poTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
