<script setup lang="ts">
// Tab «Рабочий лист салонов» of «Деньги» — legacy money-tab-salonwb (L6295–6330), loadSalonWbFromDB/showSalonWbSalon/
// renderSalonWorksheet/setSalonWbYearFilter/addSalonWbRow/updateSalonWbField/deleteSalonWbRow/clearSalonWbSheet,
// syncStickyScroll (L8832–9205). The cash book of each salon with its running balance over the whole history (by date, then by
// the row's uuid, as legacy), a year filter, cells saved on change. «Перенести» posts money operations (ERP-031…033, D-03,
// D-11) and «Импорт из Excel» reads Excel (ERP-067): both say when they come.
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { fmtCurrency } from '@/lib/sales';

export interface SalonRow {
    id: number;
    uuid: string;
    salon: 'armada' | 'nazarbayeva';
    transferred: boolean;
    transferred_at: string | null;
    order_date: string | null;
    payment_date: string | null;
    delivery_date: string | null;
    order_num: string | null;
    manager: string | null;
    note: string | null;
    recipient_desc: string | null;
    balance_before: string | null;
    cash_amount: string | null;
    card_amount: string | null;
    balance_after: string | null;
    cod_amount: string | null;
    expense_amount: string | null;
    transferred_to_office: string | null;
}
type Field = keyof Omit<SalonRow, 'id' | 'uuid' | 'salon' | 'transferred' | 'transferred_at'>;
const props = defineProps<{ rows: SalonRow[] | undefined; active: boolean }>();
const RELOAD = { preserveScroll: true, preserveState: true, only: ['salonRows', 'flash'] };
watch(
    () => props.active,
    (a) => {
        if (a && props.rows === undefined) router.reload({ only: ['salonRows'] });
    },
    { immediate: true },
);

const LABELS = { armada: 'Армада', nazarbayeva: 'Назарбаева' };
const salon = ref<'armada' | 'nazarbayeva'>('armada');
const yearFilter = ref('current');
const num = (v: string | null) => Number(v) || 0;

// Running balance over the whole history of the salon: cash in, expenses and money handed to the office out.
const withBalance = computed(() => {
    const rows = (props.rows ?? []).filter((r) => r.salon === salon.value);
    const sorted = [...rows].sort((a, b) => (a.order_date || a.payment_date || '').localeCompare(b.order_date || b.payment_date || '') || a.uuid.localeCompare(b.uuid));
    let running = 0;
    return sorted.map((r) => ({ ...r, balance: (running += num(r.cash_amount) - num(r.expense_amount) - num(r.transferred_to_office)) }));
});
const current = computed(() => (withBalance.value.length ? withBalance.value[withBalance.value.length - 1].balance : 0));
const years = computed(() => [...new Set(withBalance.value.map((r) => (r.order_date || r.payment_date || '').slice(0, 4)).filter(Boolean))].sort());
// Legacy: a year that has no rows gives way to the current year, otherwise the last one.
watch(
    [years, () => props.rows !== undefined],
    () => {
        if (props.rows === undefined) return;
        if (yearFilter.value !== 'all' && !years.value.includes(yearFilter.value)) {
            const cur = String(new Date().getFullYear());
            yearFilter.value = years.value.includes(cur) ? cur : years.value.length ? years.value[years.value.length - 1] : 'all';
        }
    },
    { immediate: true },
);
const visible = computed(() => (yearFilter.value === 'all' ? withBalance.value : withBalance.value.filter((r) => (r.order_date || r.payment_date || '').slice(0, 4) === yearFilter.value)));

const COLUMNS: { field: Field; type: 'date' | 'text' | 'number' }[] = [
    { field: 'order_date', type: 'date' }, { field: 'order_num', type: 'text' }, { field: 'manager', type: 'text' }, { field: 'balance_before', type: 'number' },
    { field: 'payment_date', type: 'date' }, { field: 'cash_amount', type: 'number' }, { field: 'card_amount', type: 'number' }, { field: 'balance_after', type: 'number' },
    { field: 'note', type: 'text' }, { field: 'delivery_date', type: 'date' }, { field: 'cod_amount', type: 'number' }, { field: 'expense_amount', type: 'number' },
    { field: 'transferred_to_office', type: 'number' },
];
const value = (r: SalonRow, c: { field: Field; type: string }) => {
    const v = r[c.field];
    return v == null ? '' : c.type === 'number' ? String(Number(v)) : v;
};
const inputStyle = (type: string) => ({
    width: '100%', minWidth: type === 'date' ? '118px' : type === 'number' ? '90px' : '110px', fontSize: '12px', border: 'none', background: 'transparent', padding: '2px 4px',
});
const fail = (errors: Record<string, string>) => window.alert('Не удалось сохранить изменение: ' + (Object.values(errors)[0] ?? ''));
function updateSalonWbField(r: SalonRow, field: Field, v: string): void {
    router.put(`/money/salon-worksheet/${r.id}`, { field, value: v }, { ...RELOAD, onError: fail });
}
function addSalonWbRow(): void {
    router.post('/money/salon-worksheet', { salon: salon.value }, { ...RELOAD, onError: (e) => window.alert('Не удалось создать строку.\n\n' + (Object.values(e)[0] ?? '')) });
}
function deleteSalonWbRow(r: SalonRow): void {
    if (!window.confirm('Удалить строку из рабочего листа?')) return;
    router.delete(`/money/salon-worksheet/${r.id}`, { ...RELOAD, onError: (e) => window.alert('Ошибка удаления: ' + (Object.values(e)[0] ?? '')) });
}
function clearSalonWbSheet(): void {
    const n = withBalance.value.filter((r) => !r.transferred).length;
    if (!n) return window.alert('Нет строк для удаления (все либо уже перенесены в базу, либо лист пуст).');
    if (!window.confirm(`Удалить ${n} строк(и) из рабочего листа салона «${LABELS[salon.value]}»?\n\nУже перенесённые в базу строки затронуты не будут.\nЭто действие нельзя отменить.`)) return;
    router.post('/money/salon-worksheet/clear', { salon: salon.value }, { ...RELOAD, onError: (e) => window.alert('Ошибка при очистке: ' + (Object.values(e)[0] ?? '')) });
}
const importLater = () => window.alert('Импорт рабочего листа из Excel появится вместе с остальными загрузками и выгрузками Excel (ERP-067).');
const transferLater = () => window.alert('Перенос строки в денежные операции появится вместе с проведением денег (ERP-031…033, решения D-03 и D-11).');

// Legacy syncStickyScroll / initStickyScroll: a horizontal scrollbar kept at the bottom of the screen.
const wrap = ref<HTMLElement | null>(null);
const bar = ref<HTMLElement | null>(null);
const phantomWidth = ref(0);
const syncFromTable = () => bar.value && wrap.value && (bar.value.scrollLeft = wrap.value.scrollLeft);
const syncFromBar = () => bar.value && wrap.value && (wrap.value.scrollLeft = bar.value.scrollLeft);
watch(visible, async () => {
    await nextTick();
    phantomWidth.value = wrap.value?.querySelector('table')?.scrollWidth ?? 0;
});
const ruDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : '');
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 14px; align-items: center; flex-wrap: wrap">
        <div class="tabs" style="margin: 0">
            <div id="swb-tab-armada" class="tab" :class="{ active: salon === 'armada' }" @click="salon = 'armada'">🏬 Армада</div>
            <div id="swb-tab-nazarbayeva" class="tab" :class="{ active: salon === 'nazarbayeva' }" @click="salon = 'nazarbayeva'">🏪 Назарбаева</div>
        </div>
        <div style="flex: 1"></div>
        <select id="swb-year-filter" v-model="yearFilter" style="font-size: 12px">
            <template v-if="props.rows !== undefined">
                <option value="all">Все годы</option>
                <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </template>
        </select>
        <div style="font-size: 12px; color: var(--text3)">
            Остаток в кассе салона: <strong id="swb-current-balance" style="color: var(--accent)">{{ props.rows === undefined ? '₸0' : fmtCurrency(current) }}</strong>
        </div>
        <button class="btn btn-ghost btn-sm" @click="importLater">📥 Импорт из Excel</button>
        <button class="btn btn-ghost btn-sm" style="color: var(--red)" @click="clearSalonWbSheet">🗑 Очистить лист</button>
        <button class="btn btn-primary btn-sm" @click="addSalonWbRow">+ Добавить строку</button>
    </div>
    <div style="font-size: 11px; color: var(--text3); margin-bottom: 8px">
        Этот лист не влияет на базу данных программы, пока строка не будет перенесена вручную кнопкой «Перенести».
    </div>
    <div class="card" style="padding: 0">
        <div id="swb-table-wrap" ref="wrap" style="overflow-x: auto" @scroll="syncFromTable">
            <table class="data-table" style="font-size: 12px; white-space: nowrap">
                <thead>
                    <tr>
                        <th>Дата приёма</th>
                        <th>Заказ №</th>
                        <th>Менеджер</th>
                        <th>Сумма заказа (долг до)</th>
                        <th>Дата оплаты</th>
                        <th>Нал.</th>
                        <th>Безнал/Kaspi</th>
                        <th>Остаток по заказу</th>
                        <th>Примечание</th>
                        <th>Дата доставки</th>
                        <th>При получении</th>
                        <th>Расход салона</th>
                        <th>Передано в офис</th>
                        <th>Остаток в салоне</th>
                        <th>Получатель/описание</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="swb-tbody">
                    <tr v-for="r in visible" :key="r.id" v-memo="[r]" :style="r.transferred ? { background: 'var(--bg3)' } : undefined">
                        <td v-for="c in COLUMNS" :key="c.field">
                            <input
                                :type="c.type"
                                :value="value(r, c)"
                                :disabled="r.transferred"
                                :style="inputStyle(c.type)"
                                @change="updateSalonWbField(r, c.field, ($event.target as HTMLInputElement).value)"
                            />
                        </td>
                        <td :style="{ fontFamily: `'IBM Plex Mono',monospace`, textAlign: 'right', color: r.balance < 0 ? 'var(--red)' : 'var(--text2)' }">{{ fmtCurrency(r.balance) }}</td>
                        <td>
                            <input
                                type="text"
                                :value="r.recipient_desc ?? ''"
                                :disabled="r.transferred"
                                :style="inputStyle('text')"
                                @change="updateSalonWbField(r, 'recipient_desc', ($event.target as HTMLInputElement).value)"
                            />
                        </td>
                        <td style="white-space: nowrap">
                            <span v-if="r.transferred" style="color: var(--green); font-size: 11px" :title="'Перенесено ' + ruDate(r.transferred_at)">✓ Перенесено</span>
                            <template v-else>
                                <button class="btn btn-primary btn-sm" style="font-size: 11px" @click="transferLater">Перенести</button>{{ ' ' }}
                                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="deleteSalonWbRow(r)">✕</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="props.rows !== undefined && !visible.length">
                        <td colspan="16" style="text-align: center; color: var(--text3); padding: 20px">Строк за выбранный период нет</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            id="swb-scrollbar-sticky"
            ref="bar"
            style="position: sticky; bottom: 0; overflow-x: auto; overflow-y: hidden; height: 14px; background: var(--bg2); border-top: 1px solid var(--border)"
            @scroll="syncFromBar"
        >
            <div id="swb-scrollbar-phantom" :style="{ height: '1px', width: phantomWidth + 'px' }"></div>
        </div>
    </div>
</template>
