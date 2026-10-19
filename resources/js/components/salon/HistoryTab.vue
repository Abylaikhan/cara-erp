<script setup lang="ts">
// Tab «История ЗП» of «Отчёт салонов» — legacy sr-tab-history (L8219–8244), renderSalonPayrollHistory (L16394–16466): the
// payroll totals of each employee by month for a year, with a summary and the totals row; a month with no total shows «—», a
// month with two lines keeps the last one. As legacy, the list of employees is filled once, from the year shown first.
import { computed, ref, watch } from 'vue';
import type { PayrollLine } from './payroll';
import { yearsFrom } from './payroll';

const props = defineProps<{ payroll: PayrollLine[] | undefined; active: boolean; today: string }>();
const years = yearsFrom(props.today);
const year = ref(years[0]);
const employee = ref('');
const fmt = (n: number) => (n > 0 ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

// Legacy fills the list while it has only «Все сотрудники», from the lines of the year chosen at that moment.
const names = ref<string[] | null>(null);
watch(
    () => [props.payroll, props.active] as const,
    () => {
        if (names.value === null && props.payroll !== undefined && props.active) {
            names.value = [...new Set(props.payroll.filter((r) => r.year === +year.value).map((r) => r.employee))].sort();
        }
    },
    { immediate: true },
);

interface Employee {
    name: string;
    months: Record<number, { salary: number; bonus: number; commission: number; total: number }>;
}
const report = computed(() => {
    const data = (props.payroll ?? []).filter((r) => r.year === +year.value);
    const filtered = employee.value ? data.filter((r) => r.employee === employee.value) : data;
    const map = new Map<string, Employee>();
    filtered.forEach((r) => {
        const e = map.get(r.employee) ?? { name: r.employee, months: {} };
        e.months[r.month] = { salary: r.salary || 0, bonus: r.bonus || 0, commission: r.commission || 0, total: r.total || 0 };
        map.set(r.employee, e);
    });
    const employees = [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
    const yearOf = (e: Employee) => Object.values(e.months).reduce((s, m) => s + m.total, 0);
    return {
        employees: employees.map((e) => ({ ...e, yearTotal: yearOf(e) })),
        totalYear: employees.reduce((s, e) => s + yearOf(e), 0),
        totals: MONTHS.map((m) => employees.reduce((s, e) => s + (e.months[m]?.total || 0), 0)),
    };
});
const tip = (d: { salary: number; bonus: number; commission: number }) => `Оклад: ${fmt(d.salary)}, Бонус: ${fmt(d.bonus)}, %: ${fmt(d.commission)}`;
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 16px; align-items: center; flex-wrap: wrap">
        <select id="sr-hist-year" v-model="year" style="font-size: 13px">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <select id="sr-hist-employee" v-model="employee" style="font-size: 13px">
            <option value="">Все сотрудники</option>
            <option v-for="n in names ?? []" :key="n" :value="n">{{ n }}</option>
        </select>
        <span id="sr-hist-count" style="font-size: 12px; color: var(--text3)"></span>
    </div>
    <div id="sr-hist-summary" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px">
        <template v-if="payroll !== undefined">
            <div class="pl-kpi">
                <div class="pl-kpi-label">👥 Сотрудников</div>
                <div class="pl-kpi-value" style="color: var(--accent)">{{ report.employees.length }}</div>
            </div>
            <div class="pl-kpi">
                <div class="pl-kpi-label">💰 Всего ЗП за год</div>
                <div class="pl-kpi-value" style="color: var(--green)">{{ fmt(report.totalYear) }}</div>
            </div>
            <div class="pl-kpi">
                <div class="pl-kpi-label">📅 Год</div>
                <div class="pl-kpi-value" style="color: var(--text2)">{{ year }}</div>
            </div>
        </template>
    </div>
    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th style="text-align: right">Янв</th>
                    <th style="text-align: right">Фев</th>
                    <th style="text-align: right">Мар</th>
                    <th style="text-align: right">Апр</th>
                    <th style="text-align: right">Май</th>
                    <th style="text-align: right">Июн</th>
                    <th style="text-align: right">Июл</th>
                    <th style="text-align: right">Авг</th>
                    <th style="text-align: right">Сен</th>
                    <th style="text-align: right">Окт</th>
                    <th style="text-align: right">Ноя</th>
                    <th style="text-align: right">Дек</th>
                    <th style="text-align: right; color: var(--green)">Итого</th>
                </tr>
            </thead>
            <tbody id="sr-hist-tbody">
                <template v-if="payroll !== undefined">
                    <tr v-for="e in report.employees" :key="e.name">
                        <td style="font-weight: 600; white-space: nowrap">{{ e.name }}</td>
                        <template v-for="m in MONTHS" :key="m">
                            <td v-if="!e.months[m] || e.months[m].total === 0" style="text-align: right; color: var(--text3); font-size: 11px">—</td>
                            <td v-else :title="tip(e.months[m])" style="text-align: right; font-family: monospace; font-size: 12px; cursor: help">{{ fmt(e.months[m].total) }}</td>
                        </template>
                        <td style="text-align: right; font-family: monospace; font-weight: 700; color: var(--green)">{{ fmt(e.yearTotal) }}</td>
                    </tr>
                    <tr v-if="!report.employees.length">
                        <td colspan="14" style="text-align: center; color: var(--text3)">Нет данных</td>
                    </tr>
                    <tr style="background: var(--bg3); font-weight: 700; border-top: 2px solid var(--border)">
                        <td>Итого</td>
                        <td v-for="(t, i) in report.totals" :key="i" style="text-align: right; font-family: monospace">{{ fmt(t) }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(report.totalYear) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
