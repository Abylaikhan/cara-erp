<script setup lang="ts">
// Tab «Инвентаризация» of «Склад» — legacy wh-tab-inventory (L2618–2640), renderWhInventory/startInventory/applyInventory
// (L28220–28303). The count is entered and the differences are calculated on the page, as in legacy. Applying them changes
// stock, so it is done by an inventory document (ERP-040) — legacy only changed the page memory. Legacy groups the rows by
// its old WAREHOUSES and puts every real material into «Прочие материалы»; here the material's own warehouse is used.
import { computed, reactive, ref } from 'vue';
import { DOCUMENTS_LATER, type MaterialRow, type PhysicalWarehouse } from '@/lib/warehouse';

const props = defineProps<{ materials: MaterialRow[]; warehouses: PhysicalWarehouse[] }>();

const warehouse = ref('all');
const facts = reactive(new Map<number, number>());

const byCode = computed(() => new Map(props.warehouses.map((w) => [w.code, w])));
const fmtDiff = (n: number) => String(Math.round(n * 1000) / 1000);

const rows = computed(() =>
    props.materials
        .filter((m) => warehouse.value === 'all' || m.warehouse === warehouse.value)
        .map((m) => {
            const fact = facts.get(m.id) ?? m.qty;
            const diff = fact - m.qty;
            const wh = m.warehouse ? byCode.value.get(m.warehouse) : undefined;
            return { m, fact, diff, diffSum: diff * m.price, wh: wh ? wh.icon + ' ' + wh.name.replace('Склад ', '') : '' };
        }),
);
const totalDiff = computed(() => rows.value.reduce((s, r) => s + r.diffSum, 0));
const signColor = (n: number, zero = 'var(--text3)') => (n === 0 ? zero : n > 0 ? 'var(--green)' : 'var(--red)');

function setFact(id: number, e: Event): void {
    facts.set(id, parseFloat((e.target as HTMLInputElement).value) || 0);
}

function startInventory(): void {
    facts.clear();
}

function applyInventory(): void {
    window.alert(DOCUMENTS_LATER);
}
</script>

<template>
    <div style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
        <select id="wh-inv-wh" v-model="warehouse" style="font-size: 13px; width: auto">
            <option value="all">Все склады</option>
            <option v-for="w in props.warehouses" :key="w.code" :value="w.code">{{ w.icon }} {{ w.name }}</option>
        </select>
        <button class="btn btn-ghost btn-sm" @click="startInventory">🔄 Начать</button>
        <button class="btn btn-primary btn-sm" @click="applyInventory">✅ Применить расхождения</button>
        <div style="flex: 1"></div>
        <span id="wh-inv-diff-sum" style="font-size: 13px; font-weight: 600" :style="{ color: totalDiff > 0 ? 'var(--green)' : 'var(--red)' }">{{
            totalDiff !== 0 ? 'Итого расхождение: ' + (totalDiff > 0 ? '+' : '') + Math.round(totalDiff).toLocaleString('ru-RU') + ' ₸' : ''
        }}</span>
    </div>
    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Склад</th>
                    <th>Наименование</th>
                    <th>SKU</th>
                    <th style="text-align: right">По учёту</th>
                    <th style="text-align: right">Факт</th>
                    <th style="text-align: right">Расхождение</th>
                    <th style="text-align: right">Сумма ±</th>
                </tr>
            </thead>
            <tbody id="wh-inventory-tbody">
                <tr v-for="r in rows" :key="r.m.id" v-memo="[r.m, r.fact]">
                    <td style="font-size: 12px">{{ r.wh }}</td>
                    <td style="font-weight: 500">{{ r.m.name }}</td>
                    <td style="font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--text3)">{{ r.m.sku || '—' }}</td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ r.m.qty }} {{ r.m.unit }}</td>
                    <td style="text-align: right">
                        <input type="number" :value="r.fact" min="0" step="0.01" style="width: 80px; text-align: right; font-size: 13px" @change="setFact(r.m.id, $event)" />
                        <span style="font-size: 11px; color: var(--text3)"> {{ r.m.unit }}</span>
                    </td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace" :style="{ color: signColor(r.diff) }">
                        {{ r.diff === 0 ? '—' : (r.diff > 0 ? '+' : '') + fmtDiff(r.diff) }}
                    </td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace" :style="{ color: signColor(r.diffSum) }">
                        {{ r.diffSum === 0 ? '—' : (r.diffSum > 0 ? '+' : '') + Math.round(r.diffSum).toLocaleString('ru-RU') + ' ₸' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
