<script setup lang="ts">
// Detail window of the reports — legacy drilldownModal (L4543–4565), openDrilldown/renderDrilldownTable/filterDrilldown
// (L32938–33010): totals of the number columns, search through every column, the rows and their totals. Excel waits for the
// exports (ERP-067).
import { computed, reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { fmtCurrency } from '@/lib/sales';

export interface DrillColumn {
    key: string;
    label: string;
    num?: boolean;
    color?: string;
    mono?: boolean;
}
type Row = Record<string, string | number | null | undefined>;

const dd = reactive({ open: false, title: '', subtitle: '', search: '', rows: [] as Row[], cols: [] as DrillColumn[] });
function openDrilldown(title: string, subtitle: string, rows: Row[], cols: DrillColumn[]): void {
    Object.assign(dd, { open: true, title, subtitle, search: '', rows, cols });
}
const numCols = computed(() => dd.cols.filter((c) => c.num));
const shown = computed(() => {
    const s = dd.search.toLowerCase();
    return s ? dd.rows.filter((r) => dd.cols.some((c) => String(r[c.key] || '').toLowerCase().includes(s))) : dd.rows;
});
const total = (rows: Row[], key: string) => rows.reduce((s, r) => s + (parseFloat(String(r[key])) || 0), 0);
const isNum = (c: DrillColumn, v: unknown) => c.num && v !== undefined && v !== null && v !== '—';
const exportDrilldownExcel = () => window.alert('Выгрузка в Excel появится вместе с остальными выгрузками (ERP-067).');
defineExpose({ openDrilldown });
</script>

<template>
    <ErpModal id="drilldownModal" :open="dd.open" :modal-style="{ maxWidth: '860px', maxHeight: '85vh', display: 'flex', flexDirection: 'column' }" @close="dd.open = false">
        <div class="modal-header">
            <div>
                <div id="dd-title" class="modal-title">{{ dd.title }}</div>
                <div id="dd-subtitle" style="font-size: 12px; color: var(--text3)">{{ dd.subtitle }}</div>
            </div>
            <button class="modal-close" @click="dd.open = false">✕</button>
        </div>
        <div id="dd-kpi" :style="{ display: numCols.length ? 'grid' : 'none', gridTemplateColumns: 'repeat(3,1fr)', gap: '10px', marginBottom: '14px' }">
            <div v-for="c in numCols" :key="c.key" class="card" style="padding: 10px 14px">
                <div style="font-size: 11px; color: var(--text3); margin-bottom: 2px">{{ c.label }}</div>
                <div :style="{ fontSize: '16px', fontWeight: 700, color: c.color || 'var(--accent)' }">{{ fmtCurrency(total(dd.rows, c.key)) }}</div>
            </div>
        </div>
        <div style="display: flex; gap: 8px; margin-bottom: 10px">
            <input id="dd-search" v-model="dd.search" type="search" placeholder="🔍 Поиск по строкам..." style="font-size: 13px; flex: 1" />
            <button class="btn btn-ghost btn-sm" @click="exportDrilldownExcel">📥 Excel</button>
        </div>
        <div style="overflow-y: auto; flex: 1; border: 1px solid var(--border); border-radius: 8px">
            <table id="dd-table" class="data-table" style="margin: 0">
                <thead id="dd-thead">
                    <tr>
                        <th v-for="c in dd.cols" :key="c.key" :style="{ textAlign: c.num ? 'right' : 'left' }">{{ c.label }}</th>
                    </tr>
                </thead>
                <tbody id="dd-tbody">
                    <tr v-for="(r, i) in shown" :key="i">
                        <td
                            v-for="c in dd.cols"
                            :key="c.key"
                            :style="{
                                textAlign: c.num ? 'right' : 'left',
                                color: isNum(c, r[c.key]) && c.color ? c.color : undefined,
                                fontSize: '12px',
                                fontFamily: c.mono ? 'monospace' : undefined,
                            }"
                        >
                            {{ isNum(c, r[c.key]) ? fmtCurrency(parseFloat(String(r[c.key])) || 0) : r[c.key] || '—' }}
                        </td>
                    </tr>
                    <tr v-if="!shown.length">
                        <td :colspan="dd.cols.length" style="text-align: center; padding: 20px; color: var(--text3)">Нет данных за период</td>
                    </tr>
                </tbody>
                <tfoot id="dd-tfoot">
                    <tr v-if="numCols.length && shown.length" style="background: var(--bg3); font-weight: 700">
                        <template v-for="c in dd.cols" :key="c.key">
                            <td v-if="!c.num" style="padding: 8px 12px">Итого:</td>
                            <td v-else :style="{ textAlign: 'right', padding: '8px 12px', fontFamily: 'monospace', color: c.color || 'inherit' }">{{ fmtCurrency(total(shown, c.key)) }}</td>
                        </template>
                    </tr>
                </tfoot>
            </table>
        </div>
    </ErpModal>
</template>
