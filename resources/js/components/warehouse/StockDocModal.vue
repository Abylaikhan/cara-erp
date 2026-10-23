<script setup lang="ts">
// «Склад» → «📥 Приходная накладная» — legacy whDocModal (L6440–6530), openWhDoc/addWhDocItem/renderWhDocItems/whDocItemMat/
// updateWhDocTotal/saveWhDoc (L28683–28800). Same form and lines; the document is saved on the server: «Сохранить черновик»
// keeps the stock, «✅ Провести» adds the quantities with a movement each (№ 45, ADR-0049). Legacy hid «Поставщик» and «ЭСФ
// (основание)» of a receipt (it showed them for a type it never opens) and showed «Склад-источник»; here the reverse — a
// material goes to its own warehouse (ADR-0002, item 123).
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { MaterialRow } from '@/lib/warehouse';
import type { SharedProps } from '@/types';

export interface StockDoc {
    id: number;
    type: string;
    num: string;
    date: string;
    status: 'done' | 'draft';
    supplier: string;
    esf_ref: string;
    note: string;
    createdBy: string;
    lock_version: number;
    items: { materialId: number; name: string; qty: number; qtyText: string; unit: string; price: number; priceText: string; amount: number }[];
}
type Line = { materialId: number; unit: string; qty: string; price: string };

const props = defineProps<{ materials: MaterialRow[]; suppliers: { id: number; name: string }[]; today: string; nextNum: string }>();
const page = usePage<SharedProps>();

const m = reactive({ open: false, id: null as number | null, lockVersion: 0, date: '', num: '', supplier: '', esfRef: '', note: '', items: [] as Line[], busy: false });
const byId = computed(() => new Map(props.materials.map((x) => [x.id, x])));
function openWhDoc(doc: StockDoc | null = null): void {
    Object.assign(m, {
        open: true,
        id: doc?.id ?? null,
        lockVersion: doc?.lock_version ?? 0,
        date: doc?.date ?? props.today,
        num: doc?.num ?? props.nextNum,
        supplier: doc?.supplier ?? '',
        esfRef: doc?.esf_ref ?? '',
        note: doc?.note ?? '',
        items: (doc?.items ?? []).map((it) => ({ materialId: it.materialId, unit: it.unit, qty: it.qtyText, price: it.priceText })),
    });
}
defineExpose({ openWhDoc });

function addWhDocItem(): void {
    m.items.push({ materialId: 0, unit: 'шт', qty: '1', price: '0' });
}
// legacy whDocItemMat: the unit and the price of the material
function whDocItemMat(i: number, id: string): void {
    const mat = byId.value.get(+id);
    m.items[i] = mat ? { ...m.items[i], materialId: mat.id, unit: mat.unit, price: String(mat.price) } : { ...m.items[i], materialId: 0 };
}
const lineSum = (it: Line) => (parseFloat(it.qty) || 0) * (parseFloat(it.price) || 0);
const total = computed(() => m.items.reduce((s, it) => s + lineSum(it), 0));

function saveWhDoc(status: 'draft' | 'done'): void {
    if (!m.items.length) {
        window.alert('Добавьте хотя бы одну позицию');
        return;
    }
    const data = {
        date: m.date,
        num: m.num,
        supplier: m.supplier,
        esf_ref: m.esfRef,
        note: m.note,
        post: status === 'done',
        lines: m.items.map((it) => ({ material_id: it.materialId, qty: it.qty, price: it.price })),
        lock_version: m.lockVersion,
    };
    const options = {
        preserveScroll: true,
        onStart: () => (m.busy = true),
        onFinish: () => (m.busy = false),
        onSuccess: () => {
            m.open = false;
            if (page.props.flash.alert) window.alert(page.props.flash.alert); // legacy «Документ … проведён ✅»
        },
        onError: (e: Record<string, string>) => window.alert(Object.values(e)[0] || 'Ошибка'),
    };
    if (m.id === null) router.post('/warehouse/documents', data, options);
    else router.put(`/warehouse/documents/${m.id}`, data, options);
}
</script>

<template>
    <ErpModal id="whDocModal" :open="m.open" :modal-style="{ maxWidth: '700px', maxHeight: '90vh', overflowY: 'auto' }" @close="m.open = false">
        <div class="modal-header">
            <span id="wh-doc-modal-title" class="modal-title">📥 Приходная накладная</span>
            <button class="modal-close" @click="m.open = false">✕</button>
        </div>
        <!-- Шапка документа -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px">
            <div class="form-row">
                <label class="label">Дата</label>
                <input id="whdoc-date" v-model="m.date" type="date" />
            </div>
            <div class="form-row">
                <label class="label">Номер</label>
                <input id="whdoc-num" v-model="m.num" type="text" placeholder="П-004" />
            </div>
            <div id="whdoc-supplier-row" class="form-row" style="grid-column: 1/-1">
                <label class="label">Поставщик</label>
                <input id="whdoc-supplier" v-model="m.supplier" type="text" placeholder="Название поставщика" list="whdoc-supplier-list" style="font-size: 13px" />
                <datalist id="whdoc-supplier-list">
                    <option v-for="s in props.suppliers" :key="s.id" :value="s.name" />
                </datalist>
            </div>
            <div id="whdoc-esf-row" class="form-row" style="grid-column: 1/-1">
                <label class="label">ЭСФ (основание)</label>
                <input id="whdoc-esf-ref" v-model="m.esfRef" type="text" placeholder="ESF-... или №..." style="font-size: 13px" />
            </div>
            <div class="form-row" style="grid-column: 1/-1">
                <label class="label">Примечание</label>
                <input id="whdoc-note" v-model="m.note" type="text" placeholder="МО-2244 кухня, партия №3..." />
            </div>
        </div>

        <!-- Табличная часть -->
        <div style="font-weight: 600; font-size: 13px; margin-bottom: 8px">Позиции документа</div>
        <table class="data-table" style="margin-bottom: 8px">
            <thead>
                <tr>
                    <th>Материал</th>
                    <th>Кол-во</th>
                    <th>Ед.</th>
                    <th style="text-align: right">Цена</th>
                    <th style="text-align: right">Сумма</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="whdoc-items-tbody">
                <tr v-for="(it, i) in m.items" :key="i">
                    <td>
                        <select style="font-size: 12px; width: 100%" :value="it.materialId || ''" @change="whDocItemMat(i, ($event.target as HTMLSelectElement).value)">
                            <option value="">— выберите —</option>
                            <option v-for="mat in props.materials" :key="mat.id" :value="mat.id">{{ mat.name }}</option>
                        </select>
                    </td>
                    <td><input v-model="it.qty" type="number" min="0" step="0.01" style="width: 70px; font-size: 12px" /></td>
                    <td style="font-size: 12px; color: var(--text3)">{{ it.unit || '—' }}</td>
                    <td><input v-model="it.price" type="number" min="0" style="width: 90px; font-size: 12px; text-align: right" /></td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px">₸{{ lineSum(it).toLocaleString('ru-RU') }}</td>
                    <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="m.items.splice(i, 1)">✕</button></td>
                </tr>
            </tbody>
        </table>
        <button class="btn btn-ghost btn-sm" @click="addWhDocItem">+ Добавить позицию</button>

        <div style="display: flex; gap: 8px; justify-content: space-between; margin-top: 16px; align-items: center">
            <div style="font-size: 13px; font-weight: 600">
                Итого: <span id="whdoc-total" style="color: var(--accent)">₸{{ total.toLocaleString('ru-RU') }}</span>
            </div>
            <div style="display: flex; gap: 8px">
                <button class="btn btn-ghost" @click="m.open = false">Отмена</button>
                <button class="btn btn-ghost btn-sm" :disabled="m.busy" @click="saveWhDoc('draft')">Сохранить черновик</button>
                <button class="btn btn-primary" :disabled="m.busy" @click="saveWhDoc('done')">✅ Провести</button>
            </div>
        </div>
    </ErpModal>
</template>
