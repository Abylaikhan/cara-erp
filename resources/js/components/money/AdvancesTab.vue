<script setup lang="ts">
// Tab «Авансовые отчёты» of «Деньги» — legacy money-tab-advances (L6255–6290), loadAdvancesFromDB/renderAdvances (L10054–10129)
// with advTotalSpent/advBalance/advStatus (L8641–8668): search, status, the reports with what was issued, spent (by the
// lines), returned and the balance. Loaded when the tab is first opened. View only: the report form comes with ERP-035.
import { router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { fmt } from '@/lib/money';

interface Advance {
    id: number;
    doc_num: string | null;
    employee: string;
    date: string;
    purpose: string | null;
    issued: number;
    spent: number;
    returned: number;
    status: string;
    issue_doc: { date: string; amount: number } | null;
    esf: number;
}
const props = defineProps<{ advances: Advance[] | undefined; active: boolean }>();

watch(
    () => props.active,
    (active) => {
        if (active && props.advances === undefined) router.reload({ only: ['advances'] });
    },
    { immediate: true },
);

const f = reactive({ search: '', status: '' });
const rows = computed(() => {
    const q = f.search.toLowerCase();
    return (props.advances ?? []).filter((a) => (!q || a.employee.toLowerCase().includes(q) || (a.purpose || '').toLowerCase().includes(q)) && (!f.status || a.status === f.status));
});
const balance = (a: Advance) => a.issued - a.spent - (a.returned || 0);
/** Legacy advStatus. */
function status(a: Advance): { label: string; cls: string } {
    const bal = balance(a);
    if (a.status === 'closed') return { label: '✅ Закрыт', cls: 'badge-green' };
    if (a.status === 'reported') {
        if (Math.abs(bal) < 0.5) return { label: '📋 К закрытию', cls: 'badge-blue' };
        return bal > 0 ? { label: '📋 Вернуть ₸' + Math.round(bal).toLocaleString('ru-RU'), cls: 'badge-amber' } : { label: '📋 Доплата ₸' + Math.round(-bal).toLocaleString('ru-RU'), cls: 'badge-red' };
    }
    return { label: '🟡 Открыт', cls: 'badge-amber' };
}
const later = () => window.alert('Форма авансового отчёта появится вместе с проведением авансов: выдача, строки расходов и возврат двигают деньги (ERP-035, решения D-03 и D-11).');
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 14px; align-items: center; flex-wrap: wrap">
        <input
            id="adv-search"
            v-model="f.search"
            type="search"
            placeholder="🔍 Сотрудник, цель..."
            style="font-size: 13px; flex: 1; min-width: 160px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1)"
        />
        <select id="adv-status-filter" v-model="f.status" style="font-size: 13px">
            <option value="">Все статусы</option>
            <option value="open">Открытые</option>
            <option value="reported">Отчитались</option>
            <option value="closed">Закрытые</option>
        </select>
        <button class="btn btn-ghost btn-sm" @click="router.reload({ only: ['advances', 'advanceDebt'] })">🔄</button>
        <button class="btn btn-primary btn-sm" @click="later">+ Авансовый отчёт</button>
    </div>
    <div style="overflow: auto; max-height: calc(100vh - 320px)">
        <table class="data-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Подотчётное лицо</th>
                    <th>Дата выдачи</th>
                    <th>Документы выдачи</th>
                    <th>Цель</th>
                    <th style="text-align: right">Выдано</th>
                    <th style="text-align: right; color: var(--red)">Потрачено</th>
                    <th style="text-align: right; color: var(--green)">Возврат</th>
                    <th style="text-align: right; color: var(--amber)">Остаток/Перерасход</th>
                    <th>Дата отчёта</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="advances-tbody">
                <tr v-if="props.advances && !rows.length">
                    <td colspan="12" style="text-align: center; padding: 20px; color: var(--text3)">Авансовых отчётов нет</td>
                </tr>
                <tr v-for="a in rows" :key="a.id" style="cursor: pointer" @click="later">
                    <td style="font-family: monospace; color: var(--accent); font-size: 12px">{{ a.doc_num || 'АО-' + String(a.id).substring(0, 6) }}</td>
                    <td style="font-weight: 500">{{ a.employee }}</td>
                    <td style="font-size: 12px; white-space: nowrap">{{ a.date }}</td>
                    <td style="font-size: 11px; color: var(--text3)">{{ a.issue_doc ? `${a.issue_doc.date} ₸${Math.round(a.issue_doc.amount).toLocaleString('ru-RU')}` : '—' }}</td>
                    <td style="font-size: 12px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                        {{ a.purpose || '—' }}<span v-if="a.esf > 0" style="font-size: 10px; color: var(--accent); margin-left: 4px" :title="`${a.esf} привязанных ЭСФ`">📄{{ a.esf }}</span>
                    </td>
                    <td style="text-align: right; font-family: monospace">{{ fmt(a.issued) }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--red)">{{ a.spent > 0 ? fmt(a.spent) : '—' }}</td>
                    <td style="text-align: right; font-family: monospace; color: var(--green)">{{ a.returned > 0 ? fmt(a.returned) : '—' }}</td>
                    <td
                        style="text-align: right; font-family: monospace; font-weight: 600"
                        :style="{ color: Math.abs(balance(a)) < 0.5 ? 'var(--green)' : balance(a) > 0 ? 'var(--amber)' : 'var(--red)' }"
                    >
                        {{ Math.abs(balance(a)) < 0.5 ? '✓' : balance(a) > 0 ? fmt(balance(a)) : '⬆️ ' + fmt(-balance(a)) }}
                    </td>
                    <td style="font-size: 12px; white-space: nowrap">—</td>
                    <td>
                        <span class="badge" :class="status(a).cls" style="font-size: 10px">{{ status(a).label }}</span>
                    </td>
                    <td @click.stop>
                        <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later">✏️</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
