<script setup lang="ts">
// Tab «Сверка с банком» of «Деньги» — legacy money-tab-bankrecon (L6333–6352), reconDayModal (L6636), showBankReconAccount/
// renderBankRecon/openReconDayModal/clearBankReconSheet (L9346–9600). The statement of Kaspi or Halyk against the journal of the
// account day by day (newest first), mismatches marked, the operations of a day. Loading a statement from Excel (ERP-034,
// ERP-067) and «Перенести в журнал» (posting, ERP-031…033, D-03, D-11) say when they come.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { MoneyAccount } from '@/lib/money';
import { fmtCurrency } from '@/lib/sales';

export interface BankRecon {
    accounts: number[];
    rows: { id: number; account: number; date: string | null; flow: 'income' | 'expense'; amount: string; counterparty: string | null; bin: string | null; description: string | null; transferred: boolean }[];
    journal: { account: number; date: string; in: string; out: string }[];
}
const props = defineProps<{ data: BankRecon | undefined; accounts: MoneyAccount[]; active: boolean }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.data === undefined) router.reload({ only: ['bankRecon'] });
    },
    { immediate: true },
);

const account = ref<number | null>(null);
watch(
    () => props.data,
    (d) => {
        if (d && account.value === null) account.value = d.accounts[0] ?? null;
    },
    { immediate: true },
);
const options = computed(() => (props.data?.accounts ?? []).map((id) => ({ id, a: props.accounts.find((x) => x.id === id) })));

const days = computed(() => {
    const d = props.data;
    if (!d || account.value === null) return [];
    const by = new Map<string, { rawIn: number; rawOut: number; jrnIn: number; jrnOut: number; rows: BankRecon['rows'] }>();
    const get = (date: string) => by.get(date) ?? (by.set(date, { rawIn: 0, rawOut: 0, jrnIn: 0, jrnOut: 0, rows: [] }), by.get(date)!);
    d.rows.filter((r) => r.account === account.value && r.date).forEach((r) => {
        const x = get(r.date!);
        if (r.flow === 'income') x.rawIn += Number(r.amount);
        else x.rawOut += Number(r.amount);
        x.rows.push(r);
    });
    d.journal.filter((j) => j.account === account.value).forEach((j) => {
        const x = get(j.date);
        x.jrnIn += Number(j.in);
        x.jrnOut += Number(j.out);
    });
    return [...by.entries()]
        .sort((a, b) => b[0].localeCompare(a[0]))
        .map(([date, x]) => ({ date, ...x, mismatch: Math.abs(x.rawIn - x.jrnIn) > 0.01 || Math.abs(x.rawOut - x.jrnOut) > 0.01, notTransferred: x.rows.filter((r) => !r.transferred).length }));
});

const day = reactive({ open: false, date: '' });
const dayRows = computed(() => (props.data?.rows ?? []).filter((r) => r.account === account.value && r.date === day.date));
const importLater = () => window.alert('Загрузка банковской выписки из Excel появится вместе с импортом выписок (ERP-034, ERP-067).');
const transferLater = () => window.alert('Перенос операции выписки в журнал появится вместе с проведением денег (ERP-031…033, решения D-03 и D-11).');
function clearBankReconSheet(): void {
    const n = (props.data?.rows ?? []).filter((r) => r.account === account.value && !r.transferred).length;
    if (!n) return window.alert('Нет строк для удаления (лист пуст, либо всё уже перенесено в журнал).');
    if (!window.confirm(`Удалить ${n} строк(и) из сверки по этому счёту?\n\nУже перенесённые в журнал строки затронуты не будут.\nПолезно, если предыдущая загрузка была с ошибкой.`)) return;
    router.post('/money/bank-recon/clear', { account: account.value }, { preserveScroll: true, preserveState: true, only: ['bankRecon', 'flash'], onError: (e) => window.alert('Ошибка при очистке: ' + (Object.values(e)[0] ?? '')) });
}
const mono = `'IBM Plex Mono',monospace`;
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center; flex-wrap: wrap">
        <select id="recon-account-select" v-model="account" style="font-size: 13px">
            <option v-for="o in options" :key="o.id" :value="o.id">{{ o.a?.icon || '' }} {{ o.a?.name }}</option>
        </select>
        <button class="btn btn-ghost btn-sm" @click="importLater">📥 Загрузить выписку</button>
        <button class="btn btn-ghost btn-sm" style="color: var(--red)" @click="clearBankReconSheet">🗑 Очистить</button>
    </div>
    <div style="font-size: 11px; color: var(--text3); margin-bottom: 10px">
        Загруженные выписки не влияют на журнал «Расчётные счета», пока конкретная операция не перенесена вручную. Повторная загрузка за тот же период не создаёт дублей —
        добавляются только новые операции.
    </div>
    <div class="card" style="padding: 0; overflow-x: auto">
        <table class="data-table" style="font-size: 12px">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th style="text-align: right">Приход (выписка)</th>
                    <th style="text-align: right">Приход (журнал)</th>
                    <th style="text-align: right">Расход (выписка)</th>
                    <th style="text-align: right">Расход (журнал)</th>
                    <th style="text-align: center">Сходится?</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="recon-tbody">
                <tr v-for="d in days" :key="d.date" v-memo="[d]" :style="d.mismatch ? { background: 'rgba(239,68,68,.08)' } : undefined">
                    <td>{{ d.date }}</td>
                    <td :style="{ textAlign: 'right', fontFamily: mono, color: 'var(--green)' }">{{ fmtCurrency(d.rawIn) }}</td>
                    <td :style="{ textAlign: 'right', fontFamily: mono, color: 'var(--green)' }">{{ fmtCurrency(d.jrnIn) }}</td>
                    <td :style="{ textAlign: 'right', fontFamily: mono, color: 'var(--red)' }">{{ fmtCurrency(d.rawOut) }}</td>
                    <td :style="{ textAlign: 'right', fontFamily: mono, color: 'var(--red)' }">{{ fmtCurrency(d.jrnOut) }}</td>
                    <td style="text-align: center">
                        <span v-if="d.mismatch" style="color: var(--red)">⚠️ не сходится</span>
                        <span v-else style="color: var(--green)">✓</span>
                    </td>
                    <td>
                        <button v-if="d.rows.length" class="btn btn-ghost btn-sm" style="font-size: 11px" @click="Object.assign(day, { open: true, date: d.date })">
                            Операции ({{ d.notTransferred }} не перенесено)
                        </button>
                    </td>
                </tr>
                <tr v-if="props.data !== undefined && !days.length">
                    <td colspan="7" style="text-align: center; color: var(--text3); padding: 20px">Нет данных — загрузите выписку</td>
                </tr>
            </tbody>
        </table>
    </div>

    <ErpModal id="reconDayModal" :open="day.open" :modal-style="{ maxWidth: '640px', maxHeight: '85vh', overflowY: 'auto' }" @close="day.open = false">
        <div class="modal-header">
            <span id="recon-day-title" class="modal-title">Операции за {{ day.date }}</span>
            <button class="modal-close" @click="day.open = false">✕</button>
        </div>
        <div id="recon-day-body">
            <template v-for="r in dayRows" :key="r.id">
                <div v-if="r.transferred" class="card" style="padding: 8px 12px; margin-bottom: 6px; opacity: 0.6; display: flex; gap: 10px; align-items: center">
                    <span :style="{ color: r.flow === 'income' ? 'var(--green)' : 'var(--red)' }">{{ r.flow === 'income' ? '+' : '-' }}{{ fmtCurrency(Number(r.amount)) }}</span>
                    <span style="flex: 1; font-size: 12px">{{ r.counterparty || '—' }}</span>
                    <span style="font-size: 11px; color: var(--green)">✓ уже в журнале</span>
                </div>
                <div v-else class="card" style="padding: 8px 12px; margin-bottom: 6px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                    <span :style="{ color: r.flow === 'income' ? 'var(--green)' : 'var(--red)', fontWeight: 600 }">{{ r.flow === 'income' ? '+' : '-' }}{{ fmtCurrency(Number(r.amount)) }}</span>
                    <span style="flex: 1; min-width: 160px; font-size: 12px"
                        >{{ r.counterparty || '—' }}<template v-if="r.bin">{{ ' ' }}<span style="color: var(--text3); font-size: 11px">БИН {{ r.bin }}</span></template></span
                    >
                    <span style="font-size: 11px; color: var(--text3); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" :title="r.description || ''">{{
                        r.description || ''
                    }}</span>
                    <button class="btn btn-primary btn-sm" style="font-size: 11px" @click="transferLater">Перенести в журнал</button>
                </div>
            </template>
            <div v-if="!dayRows.length" style="color: var(--text3); font-size: 12px">Нет операций за этот день</div>
        </div>
    </ErpModal>
</template>
