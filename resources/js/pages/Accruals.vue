<script setup lang="ts">
// M20 «Начисления ЗП», part 1 — legacy page-accruals (L7768–7996), initAccrualsPage/fillPayrollMonthSelects/renderAccrualsList
// (L13214–13258, L13590–13620): the journal of the salary accruals, filtered by month and by the employee's name; the columns are
// read from `details` as legacy reads them. Adding and changing an accrual, the salary by the timesheet, vacation and sick pay
// come with the payroll rules (ERP-056, D-06): their buttons say so.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

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
const props = defineProps<{ today: string; accruals: Accrual[] }>();

const LATER = 'Начисление, расчёт оклада по табелю, отпускных и больничных появятся вместе с правилами расчёта зарплаты (ERP-056, решение D-06).';
const later = () => window.alert(LATER);

const MN = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
// Legacy fillPayrollMonthSelects: from the later of the current month and the latest accrual down to November 2025.
const months = computed(() => {
    const [ty, tm] = props.today.split('-').map(Number);
    let last = ty * 12 + tm - 1;
    for (const a of props.accruals) {
        const m = /^(\d{4})-(\d{2})/.exec(a.date || '');
        if (m) last = Math.max(last, +m[1] * 12 + +m[2] - 1);
    }
    const out: { value: string; label: string }[] = [];
    for (let v = last; v >= 2025 * 12 + 10; v--) {
        const y = Math.floor(v / 12),
            mo = v % 12;
        out.push({ value: `${y}-${String(mo + 1).padStart(2, '0')}`, label: `${MN[mo]} ${y}` });
    }
    return out;
});

const month = ref('');
const search = ref('');
const fmt = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');

const rows = computed(() => {
    const q = search.value.toLowerCase();
    return props.accruals
        .filter((a) => (!month.value || a.date?.startsWith(month.value)) && (!q || a.emp_name?.toLowerCase().includes(q)))
        .map((a) => {
            const acc = a.details?.accruals || [];
            const getA = (...k: string[]) => acc.filter((x) => k.some((kk) => x.type?.includes(kk))).reduce((s, x) => s + x.amount, 0);
            return {
                a,
                oklad: getA('Оклад'),
                sdel: getA('Сдельная'),
                prem: getA('Премия'),
                otpusk: getA('Отпускные'),
                other: getA('Больничный', 'Займ', 'Материальная', 'Районный'),
            };
        });
});
</script>

<template>
    <ErpLayout>
        <div id="page-accruals" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">📋 Начисления ЗП</div>
                    <div class="section-sub">Журнал начислений по сотрудникам</div>
                </div>
                <button class="btn btn-ghost btn-sm" @click="later">🏖 Отпускные / Больничные</button>
                <button class="btn btn-ghost btn-sm" @click="later">📊 Начислить оклад</button>
                <button class="btn btn-primary btn-sm" @click="later">+ Начисление</button>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center">
                <select id="accrual-month-filter" v-model="month" style="font-size: 13px">
                    <option value="">Все периоды</option>
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <input
                    id="accrual-search"
                    v-model="search"
                    type="search"
                    placeholder="🔍 Сотрудник..."
                    style="font-size: 13px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1); width: 200px"
                />
                <button class="btn btn-ghost btn-sm" @click="router.reload()">🔄</button>
            </div>

            <div style="overflow: auto; max-height: calc(100vh - 240px)">
                <table id="accruals-table" class="data-table">
                    <thead>
                        <tr style="position: sticky; top: 0; background: var(--bg2); z-index: 1">
                            <th style="text-align: left">Сотрудник</th>
                            <th>Период</th>
                            <th style="text-align: right; color: var(--accent)">Оклад</th>
                            <th style="text-align: right; color: var(--accent)">Сдельная</th>
                            <th style="text-align: right; color: var(--accent)">Премия</th>
                            <th style="text-align: right; color: var(--accent)">Отпускные</th>
                            <th style="text-align: right; color: var(--accent)">Прочие</th>
                            <th style="text-align: right; color: var(--green); font-weight: 700">Итого нач.</th>
                            <th style="text-align: right; color: var(--red)">Удержано</th>
                            <th style="text-align: right; color: var(--amber); font-weight: 700">К выдаче</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="accruals-tbody">
                        <tr v-for="r in rows" :key="r.a.id" v-memo="[r]">
                            <td style="font-weight: 500; white-space: nowrap; font-size: 13px">{{ r.a.emp_name }}</td>
                            <td style="font-size: 12px; color: var(--text3); white-space: nowrap">{{ r.a.date || '' }}</td>
                            <td style="text-align: right; font-size: 12px">{{ r.oklad ? fmt(r.oklad) : '—' }}</td>
                            <td style="text-align: right; font-size: 12px">{{ r.sdel ? fmt(r.sdel) : '—' }}</td>
                            <td style="text-align: right; font-size: 12px">{{ r.prem ? fmt(r.prem) : '—' }}</td>
                            <td style="text-align: right; font-size: 12px">{{ r.otpusk ? fmt(r.otpusk) : '—' }}</td>
                            <td style="text-align: right; font-size: 12px">{{ r.other ? fmt(r.other) : '—' }}</td>
                            <td style="text-align: right; font-weight: 700; color: var(--green); font-size: 12px">{{ fmt(r.a.amount) }}</td>
                            <td style="text-align: right; color: var(--red); font-size: 12px">{{ r.a.deducted ? fmt(r.a.deducted) : '—' }}</td>
                            <td style="text-align: right; font-weight: 700; color: var(--amber); font-size: 12px">{{ fmt(r.a.net || 0) }}</td>
                            <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later">✏️</button></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="11" style="text-align: center; padding: 20px; color: var(--text3)">Нет данных</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </ErpLayout>
</template>
