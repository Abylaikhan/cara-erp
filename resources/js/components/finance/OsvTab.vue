<script setup lang="ts">
// Tab «📋 ОСВ» of «Финансы» — legacy finance-osv (L5848–5855), renderOSV/exportOSVExcel/ddOSVAccount (L23722–23836, L33530): the
// management trial balance of the period of the page, counted by the server from the same entries as legacy (ADR-0053); the
// balance check, the table by account, a click opens the entries of the account. «Всё время» — all the operations (legacy fell
// back to 2026, as «ДДС» did).
import { computed, ref, watch } from 'vue';
import DrilldownModal from '@/components/DrilldownModal.vue';
import { downloadXlsx } from '@/lib/xlsx';
import { reloadLatest } from '@/lib/reload';

interface OsvRow {
    code: string;
    kz: string;
    name: string;
    openDt: number;
    openKt: number;
    dtOb: number;
    ktOb: number;
    closeDt: number;
    closeKt: number;
}
export interface OsvData {
    from: string | null;
    to: string | null;
    rows: OsvRow[];
    totals: Omit<OsvRow, 'code' | 'kz' | 'name'>;
}
export interface OsvAccount {
    account: string | null;
    from: string | null;
    to: string | null;
    entries: { dt: string; kt: string; amount: number; desc: string; doc: string; date: string }[];
}
const props = defineProps<{ data: OsvData | undefined; account: OsvAccount | undefined; from: string | null; to: string | null; periodLabel: string; active: boolean }>();
watch(
    () => [props.active, props.from, props.to] as const,
    ([active, from, to]) => {
        if (!active || (props.data && props.data.from === from && props.data.to === to)) return;
        reloadLatest('osv', { osv_from: from ?? '', osv_to: to ?? '' });
    },
    { immediate: true },
);

const fmt = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const balanced = computed(() => {
    const t = props.data?.totals;
    return !!t && Math.abs(t.openDt - t.openKt) < 1 && Math.abs(t.dtOb - t.ktOb) < 1 && Math.abs(t.closeDt - t.closeKt) < 1;
});
const chk = (a: number, b: number) => (Math.abs(a - b) < 1 ? '' : 'color:var(--red)');
const hs = 'padding:8px 12px;text-align:right;font-size:11px;color:var(--text3);border-bottom:2px solid var(--border)';

// ── Детализация счёта (legacy ddOSVAccount): the entries of the period with the account on either side ──
const dd = ref<InstanceType<typeof DrilldownModal> | null>(null);
function ddOSVAccount(r: OsvRow): void {
    reloadLatest('osvAccount', { osv_account: r.code, osv_from: props.from ?? '', osv_to: props.to ?? '' }, {
        onSuccess: () => {
            const rows = (props.account?.entries ?? []).map((e) => ({
                date: e.date || '—',
                doc: e.doc || '—',
                desc: e.desc || '—',
                corr: e.dt === r.code ? `Кт ${e.kt}` : `Дт ${e.dt}`,
                debit: e.dt === r.code ? e.amount : 0,
                credit: e.kt === r.code ? e.amount : 0,
            }));
            const sum = (k: 'debit' | 'credit') => rows.reduce((s, x) => s + x[k], 0);
            const f = (n: number) => (n > 0 ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
            dd.value?.openDrilldown(`ОСВ: Счёт ${r.kz || r.code} — ${r.name}`, `${props.periodLabel} · ${rows.length} проводок · Дт ${f(sum('debit'))} · Кт ${f(sum('credit'))}`, rows, [
                { key: 'date', label: 'Дата' },
                { key: 'doc', label: 'Документ', mono: true },
                { key: 'desc', label: 'Содержание' },
                { key: 'corr', label: 'Корреспонденция' },
                { key: 'debit', label: 'Дебет', num: true, color: 'var(--accent)' },
                { key: 'credit', label: 'Кредит', num: true, color: 'var(--red)' },
            ]);
        },
    });
}

// Legacy exportOSVExcel
function exportOSVExcel(): void {
    const d = props.data;
    if (!d) return;
    const from = props.from || '2026-01-01';
    const to = props.to || '2026-12-31';
    const n = (v: number) => Math.round(v) || 0;
    const t = d.totals;
    const rows: (string | number)[][] = [
        ['Управленческая оборотно-сальдовая ведомость'],
        [`Период: ${from} — ${to}`],
        [],
        ['Сч.', 'Наименование', 'Сальдо нач. Дт', 'Сальдо нач. Кт', 'Оборот Дт', 'Оборот Кт', 'Сальдо кон. Дт', 'Сальдо кон. Кт'],
        ...d.rows.map((r) => [r.code, r.name, n(r.openDt), n(r.openKt), n(r.dtOb), n(r.ktOb), n(r.closeDt), n(r.closeKt)]),
        ['', 'ИТОГО', n(t.openDt), n(t.openKt), n(t.dtOb), n(t.ktOb), n(t.closeDt), n(t.closeKt)],
    ];
    void downloadXlsx('finance', `OSV_${from}.xlsx`, [{ name: 'ОСВ', rows, cols: [6, 35, 16, 16, 16, 16, 16, 16] }]);
}
const td = 'padding:7px 10px;text-align:right;font-family:monospace';
</script>

<template>
    <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 16px">
        <div style="font-size: 12px; color: var(--text3)">📅 Период задаётся глобальным фильтром выше</div>
        <div style="flex: 1"></div>
        <button class="btn btn-ghost btn-sm" @click="exportOSVExcel">📥 Excel</button>
    </div>
    <div id="osv-body">
        <template v-if="props.data">
            <div v-if="balanced" style="padding: 8px 12px; background: rgba(61, 214, 140, 0.1); border-radius: 6px; margin-bottom: 10px; font-size: 12px; color: var(--green)">
                ✅ Дебет = Кредит по всем колонкам — ОСВ сбалансирована
            </div>
            <div v-else style="padding: 8px 12px; background: rgba(245, 92, 92, 0.1); border-radius: 6px; margin-bottom: 10px; font-size: 12px; color: var(--red)">
                ⚠️ Дебет ≠ Кредит — проверьте данные
            </div>
            <div class="card" style="padding: 0; overflow-x: auto">
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 820px">
                    <thead>
                        <tr style="background: var(--bg3)">
                            <th style="padding: 8px 12px; text-align: left; font-size: 11px; color: var(--text3); border-bottom: 2px solid var(--border); width: 36px">Сч.</th>
                            <th style="padding: 8px 12px; text-align: left; font-size: 11px; color: var(--text3); border-bottom: 2px solid var(--border)">Наименование</th>
                            <th :style="hs" colspan="2">Сальдо на начало</th>
                            <th :style="hs" colspan="2">Обороты за период</th>
                            <th :style="hs" colspan="2">Сальдо на конец</th>
                        </tr>
                        <tr style="background: var(--bg3)">
                            <th colspan="2" style="border-bottom: 1px solid var(--border)"></th>
                            <template v-for="i in 3" :key="i">
                                <th style="padding: 6px 10px; text-align: right; font-size: 11px; color: var(--green); border-bottom: 1px solid var(--border)">Дт</th>
                                <th style="padding: 6px 10px; text-align: right; font-size: 11px; color: var(--red); border-bottom: 1px solid var(--border)">Кт</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in props.data.rows" :key="r.code" style="border-top: 1px solid var(--border); cursor: pointer" title="Нажмите для детализации" @click="ddOSVAccount(r)">
                            <td style="padding: 7px 10px; color: var(--text3); font-size: 11px; font-family: monospace" :title="'Управленческий код: ' + r.code">{{ r.kz || r.code }}</td>
                            <td style="padding: 7px 12px; font-weight: 500">{{ r.name }} <span style="font-size: 10px; color: var(--accent)">🔍</span></td>
                            <td :style="td + ';color:var(--green)'">{{ fmt(r.openDt) }}</td>
                            <td :style="td + ';color:var(--red)'">{{ fmt(r.openKt) }}</td>
                            <td :style="td + ';color:var(--green)'">{{ fmt(r.dtOb) }}</td>
                            <td :style="td + ';color:var(--red)'">{{ fmt(r.ktOb) }}</td>
                            <td :style="td + ';font-weight:600;color:var(--green)'">{{ fmt(r.closeDt) }}</td>
                            <td :style="td + ';font-weight:600;color:var(--red)'">{{ fmt(r.closeKt) }}</td>
                        </tr>
                        <tr style="background: var(--accent); color: #fff; font-weight: 700; border-top: 2px solid var(--border)">
                            <td colspan="2" style="padding: 10px 12px">ИТОГО</td>
                            <td :style="'padding:10px 10px;text-align:right;font-family:monospace;' + chk(props.data.totals.openDt, props.data.totals.openKt)">{{ fmt(props.data.totals.openDt) }}</td>
                            <td :style="'padding:10px 10px;text-align:right;font-family:monospace;' + chk(props.data.totals.openDt, props.data.totals.openKt)">{{ fmt(props.data.totals.openKt) }}</td>
                            <td :style="'padding:10px 10px;text-align:right;font-family:monospace;' + chk(props.data.totals.dtOb, props.data.totals.ktOb)">{{ fmt(props.data.totals.dtOb) }}</td>
                            <td :style="'padding:10px 10px;text-align:right;font-family:monospace;' + chk(props.data.totals.dtOb, props.data.totals.ktOb)">{{ fmt(props.data.totals.ktOb) }}</td>
                            <td :style="'padding:10px 10px;text-align:right;font-family:monospace;' + chk(props.data.totals.closeDt, props.data.totals.closeKt)">{{ fmt(props.data.totals.closeDt) }}</td>
                            <td :style="'padding:10px 10px;text-align:right;font-family:monospace;' + chk(props.data.totals.closeDt, props.data.totals.closeKt)">{{ fmt(props.data.totals.closeKt) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 10px; padding: 8px 12px; background: var(--bg3); border-radius: 6px; font-size: 11px; color: var(--text3)">
                ℹ️ Управленческая ОСВ на основе двойной записи. Сч.10 Материалы · Сч.20 Производство · Сч.43 Готовая продукция · Сч.50 Касса · Сч.51 РС · Сч.60 Поставщики ·
                Сч.62 Покупатели · Сч.68 Налоги · Сч.70 ФОТ · Сч.71 Подотчёт · Сч.84 Нераспр. прибыль · Сч.90.1 Выручка · Сч.90.2 Себестоимость продаж · Сч.91.1 Прочие доходы
                · Сч.91.2 Прочие расходы
            </div>
        </template>
    </div>
    <DrilldownModal ref="dd" />
</template>
