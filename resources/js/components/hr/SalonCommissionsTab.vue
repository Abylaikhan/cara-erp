<script setup lang="ts">
// Tab «Комиссии салонов» of «Сотрудники» — legacy renderSalonCommissionsSettings/showSalaryHistory (L22163–22300): the head of
// the salons, the sales managers of each salon and those of both, with their salary, commission % and its base, and the history
// of salaries. Shown as legacy shows it to an account that may not change it («Только просмотр.»): changing the salary, the %
// or the base writes the salary history and comes with the payroll rules (ERP-059, D-06, D-13) — ADR-0038.
import { computed, reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { Employee } from '@/lib/hr';

const props = defineProps<{ employees: Employee[] }>();
const fmt = (n: number) => (n > 0 ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const fmtAll = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');

// Legacy: the salons' staff by the salon named in the comment, without the cleaners; the head by the position.
const groups = computed(() => {
    const staff = props.employees.filter(
        (e) => /Армада|Назарбаева/.test(e.comment || '') && !(e.name || '').toLowerCase().includes('уборка') && !(e.pos || '').toLowerCase().includes('уборщ'),
    );
    const chief = staff.find((e) => (e.pos || '').includes('Начальник'));
    const rest = staff.filter((e) => e !== chief);
    const has = (e: Employee, s: string) => (e.comment || '').includes(s);
    return {
        chief,
        blocks: [
            { title: '🏬 Салон Армада', sub: 'Менеджеры продаж', always: true, rows: rest.filter((e) => has(e, 'Армада') && !has(e, 'Назарбаева')) },
            { title: '🏪 Салон Назарбаева', sub: 'Менеджеры продаж', always: true, rows: rest.filter((e) => has(e, 'Назарбаева') && !has(e, 'Армада')) },
            { title: '🔄 Общие (оба салона)', sub: 'Сотрудники', always: false, rows: rest.filter((e) => has(e, 'Армада') && has(e, 'Назарбаева')) },
        ],
    };
});
const methodLabel = (e: Employee) => (e.commissionMethod === 'paid' ? '% от оплаты' : '% от продаж');

const hist = reactive<{ open: boolean; emp: Employee | null }>({ open: false, emp: null });
const showSalaryHistory = (e: Employee) => Object.assign(hist, { open: true, emp: e });
</script>

<template>
    <div style="max-width: 700px">
        <div style="font-size: 13px; color: var(--text3); margin-bottom: 16px">Только просмотр.</div>

        <template v-if="groups.chief">
            <div style="font-size: 15px; font-weight: 700; margin-bottom: 12px; color: var(--accent)">👑 Начальник салонов</div>
            <table class="data-table" style="margin-bottom: 20px">
                <thead>
                    <tr>
                        <th>ФИО</th>
                        <th style="text-align: right">Оклад (₸)</th>
                        <th style="text-align: right">%</th>
                        <th>Метод</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="font-weight: 600">{{ groups.chief.name }}</td>
                        <td style="text-align: right">{{ fmt(groups.chief.salary || 0) }}</td>
                        <td style="text-align: right">{{ groups.chief.commissionPct || 0 }}%</td>
                        <td>{{ methodLabel(groups.chief) }}</td>
                        <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="showSalaryHistory(groups.chief)">📅 История</button></td>
                    </tr>
                </tbody>
            </table>
        </template>

        <template v-for="(block, bi) in groups.blocks" :key="block.title">
            <template v-if="block.always || block.rows.length">
                <hr v-if="bi > 0" style="border: none; border-top: 1px solid var(--border); margin: 12px 0" />
                <div style="font-size: 15px; font-weight: 700; margin-bottom: 12px; color: var(--accent)">{{ block.title }}</div>
                <template v-if="block.rows.length">
                    <div style="font-size: 12px; font-weight: 600; color: var(--text3); margin-bottom: 6px; text-transform: uppercase">{{ block.sub }}</div>
                    <table class="data-table" style="margin-bottom: 12px">
                        <thead>
                            <tr>
                                <th>ФИО</th>
                                <th style="text-align: right">Оклад (₸)</th>
                                <th style="text-align: right">%</th>
                                <th>Метод</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in block.rows" :key="e.id">
                                <td style="font-weight: 600">{{ e.name }}</td>
                                <td style="text-align: right">{{ fmt(e.salary || 0) }}</td>
                                <td style="text-align: right">{{ e.commissionPct || 0 }}%</td>
                                <td>{{ methodLabel(e) }}</td>
                                <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="showSalaryHistory(e)">📅 История</button></td>
                            </tr>
                        </tbody>
                    </table>
                </template>
                <div v-if="!block.rows.length" style="color: var(--text3); font-size: 13px; margin-bottom: 16px">Нет сотрудников</div>
            </template>
        </template>
    </div>

    <ErpModal id="salary-history-modal" :open="hist.open" max-width="500px" @close="hist.open = false">
        <div class="modal-header">
            <span id="shr-title">📅 История ЗП — {{ hist.emp?.name }}</span>
            <button class="modal-close" @click="hist.open = false">✕</button>
        </div>
        <div id="shr-body" style="padding: 16px">
            <table v-if="hist.emp?.salaryHistory?.length" class="data-table">
                <thead>
                    <tr>
                        <th>С месяца</th>
                        <th style="text-align: right">Оклад</th>
                        <th style="text-align: right">%</th>
                        <th>Примечание</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in hist.emp.salaryHistory" :key="i">
                        <td style="font-family: monospace">{{ (r.from || '').slice(0, 7) }}</td>
                        <td style="text-align: right; font-family: monospace">{{ fmtAll(r.salary || 0) }}</td>
                        <td style="text-align: right">{{ r.pct || 0 }}%</td>
                        <td style="font-size: 12px; color: var(--text3)">{{ r.note || '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-else style="color: var(--text3); text-align: center; padding: 20px">История изменений не найдена</div>
        </div>
    </ErpModal>
</template>
