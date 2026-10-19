<script setup lang="ts">
// Tab «Платёжная ведомость» of «Отчёт салонов» — legacy sr-tab-paysheet (L8196–8217), renderPaysheet/printPaysheet
// (L16294–16356): the lines of the salon payroll (legacy salon_payroll) of one month, by employee, with the totals and the
// places for signatures. As legacy, the month list starts at January (nothing sets it) and the years run from the current one down to 2024; «Печать» opens
// the sheet in a new window.
import { computed, ref } from 'vue';
import type { PayrollLine } from './payroll';
import { MONTH_NAMES, yearsFrom } from './payroll';

const props = defineProps<{ payroll: PayrollLine[] | undefined; today: string }>();
const years = yearsFrom(props.today);
const month = ref('1');
const year = ref(years[0]);
const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');

const rows = computed(() =>
    (props.payroll ?? []).filter((r) => r.month === +month.value && r.year === +year.value).sort((a, b) => a.employee.localeCompare(b.employee)),
);
const sum = (f: 'salary' | 'commission' | 'bonus' | 'total' | 'advance') => rows.value.reduce((s, r) => s + (r[f] || 0), 0);
const area = ref<HTMLElement | null>(null);

// Legacy printPaysheet: the sheet in a new window with its own small style, then the print dialog.
const print = () => {
    if (!area.value) return;
    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(
        '<html><head><title>Ведомость</title><style>body{font-family:Arial,sans-serif;padding:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px 10px;font-size:12px}th{background:#f5f5f5}tfoot tr{background:#eee}</style></head></head><body>' +
            area.value.innerHTML +
            '</body></html>',
    );
    w.document.close();
    w.print();
};
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 16px; align-items: center">
        <select id="sr-ps-month" v-model="month" style="font-size: 13px">
            <option v-for="(m, i) in MONTH_NAMES.slice(1)" :key="m" :value="String(i + 1)">{{ m }}</option>
        </select>
        <select id="sr-ps-year" v-model="year" style="font-size: 13px">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <button class="btn btn-ghost btn-sm" @click="print">Печать</button>
    </div>
    <div id="sr-paysheet-body">
        <template v-if="payroll !== undefined">
            <div v-if="!rows.length" style="text-align: center; padding: 40px; color: var(--text3)">Нет данных за этот период</div>
            <div v-else id="paysheet-print-area" ref="area">
                <div style="text-align: center; margin-bottom: 16px">
                    <div style="font-size: 16px; font-weight: 700">ПЛАТЕЖНАЯ ВЕДОМОСТЬ</div>
                    <div style="font-size: 14px; color: var(--text2)">{{ MONTH_NAMES[+month] }} {{ year }}</div>
                </div>
                <table class="data-table" style="width: 100%">
                    <thead>
                        <tr>
                            <th>N</th>
                            <th>Сотрудник</th>
                            <th style="text-align: right">Оклад</th>
                            <th style="text-align: right">Комиссия</th>
                            <th style="text-align: right">Бонус</th>
                            <th style="text-align: right">Начислено</th>
                            <th style="text-align: right">Аванс</th>
                            <th style="text-align: right">К выдаче</th>
                            <th>Подпись</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="i">
                            <td style="text-align: center">{{ i + 1 }}</td>
                            <td style="font-weight: 600">{{ r.employee }}</td>
                            <td style="text-align: right; font-family: monospace">{{ r.salary > 0 ? fmt(r.salary) : '-' }}</td>
                            <td style="text-align: right; font-family: monospace; color: var(--accent)">{{ r.commission > 0 ? fmt(r.commission) : '-' }}</td>
                            <td style="text-align: right; font-family: monospace">{{ r.bonus > 0 ? fmt(r.bonus) : '-' }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 700">{{ fmt(r.total || 0) }}</td>
                            <td style="text-align: right; font-family: monospace; color: var(--text3)">{{ r.advance > 0 ? fmt(r.advance) : '-' }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 700; color: var(--green)">{{ fmt((r.total || 0) - (r.advance || 0)) }}</td>
                            <td style="min-width: 100px"></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700; background: var(--bg2)">
                            <td colspan="2">Итого</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(sum('salary')) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(sum('commission')) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(sum('bonus')) }}</td>
                            <td style="text-align: right; font-family: monospace; font-size: 15px">{{ fmt(sum('total')) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(sum('advance')) }}</td>
                            <td style="text-align: right; font-family: monospace; font-size: 15px; color: var(--green)">{{ fmt(sum('total') - sum('advance')) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <div style="display: flex; gap: 40px; margin-top: 24px; font-size: 13px">
                    <div>Начальник салонов: _________________ / Сукачева О.В.</div>
                    <div>Дата: _______________</div>
                </div>
            </div>
        </template>
    </div>
</template>
