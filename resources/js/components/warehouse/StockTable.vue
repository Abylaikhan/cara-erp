<script setup lang="ts">
// Tab «Все остатки» of «Склад» — legacy wh-tab-remains (L2562–2615), renderWarehouse (L28305), editableCell/startEditCell
// (L28400), deleteWarehouseRow (L28522). Same filters, summary and table. Directory cells are saved by the server (legacy
// lost every edit: real ids are uuids and parseInt never finds the row); the quantity is shown, not edited — stock moves only
// through warehouse documents (docs/adr/0008-warehouse.md).
import { router } from '@inertiajs/vue3';
import { computed, nextTick, reactive } from 'vue';
import { fmtCurrency, itemType, STATUS_BADGE, whStatus, type MaterialRow, type PhysicalWarehouse } from '@/lib/warehouse';

export interface StockFilters {
    wh: string;
    type: string;
    cat: string;
    search: string;
    status: string;
}

const props = defineProps<{ materials: (MaterialRow & { value: number })[]; warehouses: PhysicalWarehouse[] }>();
const filters = defineModel<StockFilters>('filters', { required: true });

// Legacy fills the category filter from the data: «Все категории (N)» and the categories in JS default order.
const categories = computed(() => [...new Set(props.materials.map((m) => m.category || '').filter(Boolean))].sort());

const rows = computed(() => {
    const f = filters.value;
    const search = f.search.toLowerCase();
    return props.materials.filter((m) => {
        if (f.wh && m.warehouse !== f.wh) return false;
        if (f.type !== 'all' && itemType(m.category || 'Прочее', props.warehouses) !== f.type) return false;
        if (f.cat && m.category !== f.cat) return false;
        if (search && !m.name.toLowerCase().includes(search) && !(m.sku || '').toLowerCase().includes(search)) return false;
        const status = whStatus(m);
        if (f.status === 'instock' && (m.qty || 0) <= 0) return false;
        if (f.status === 'critical' && status !== 'critical') return false;
        if (f.status === 'low' && status !== 'low') return false;
        if (f.status === 'ok' && status !== 'ok') return false;
        if (f.status === 'zero' && m.qty > 0) return false;
        return true;
    });
});

// Summary bar: legacy counts it over the warehouse and category filters only.
const summary = computed(() => {
    const items = props.materials.filter((m) => (!filters.value.wh || m.warehouse === filters.value.wh) && (!filters.value.cat || (m.category || '') === filters.value.cat));
    const qty = items.reduce((s, m) => s + (m.qty || 0), 0);
    const value = items.reduce((s, m) => s + m.value, 0);
    const fmt3 = (n: number) => (n >= 1000000 ? '₸' + (n / 1000000).toFixed(1) + 'М' : n >= 1000 ? '₸' + (n / 1000).toFixed(0) + 'К' : '₸' + Math.round(n));
    return {
        count: items.length.toLocaleString('ru-RU'),
        qty: Math.round(qty).toLocaleString('ru-RU') + ' ед.',
        value: fmt3(value),
        critical: items.filter((m) => whStatus(m) === 'critical').length,
    };
});

function onTypeChange(): void {
    // Legacy onWhTypeFilterChange narrows the category list, but renderWarehouse right after it restores the full list.
    filters.value.cat = '';
}

// ── Inline cells: Enter or leaving the cell saves, Esc restores; an empty text value is not saved (legacy startEditCell) ──
type Field = 'name' | 'sku' | 'unit' | 'min_qty' | 'price';
const NUMBER_FIELDS: Field[] = ['min_qty', 'price'];
const editing = reactive({ id: 0, field: '' as Field | '', value: '' });
const shown = (m: MaterialRow, field: Field): string =>
    field === 'name' ? m.name : field === 'sku' ? m.sku || '' : field === 'unit' ? m.unit || 'шт' : String((field === 'price' ? m.price : m.min_qty) || 0);
const editingField = (m: MaterialRow): Field | '' => (editing.id === m.id ? editing.field : '');

function startEdit(m: MaterialRow, field: Field): void {
    if (editing.id === m.id && editing.field === field) return;
    editing.id = m.id;
    editing.field = field;
    editing.value = shown(m, field);
    void nextTick(() => {
        const input = document.querySelector<HTMLInputElement>('#warehouse-tbody .editing input');
        input?.focus();
        input?.select();
    });
}

function commit(m: MaterialRow): void {
    if (editing.id !== m.id || !editing.field) return;
    const field = editing.field;
    const current = shown(m, field);
    let next: string | number;
    if (NUMBER_FIELDS.includes(field)) {
        next = parseFloat(editing.value);
        if (isNaN(next)) next = 0;
    } else {
        next = editing.value.trim() || current;
    }
    editing.id = 0;
    editing.field = '';
    if (String(next) === current) return;
    router.put(
        `/warehouse/materials/${m.id}`,
        { [field]: next, lock_version: m.lock_version },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['materials', 'stockValue'],
            onError: (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка сохранения'),
        },
    );
}

function onKey(e: KeyboardEvent, m: MaterialRow): void {
    if (e.key === 'Escape') editing.value = shown(m, editing.field as Field);
    if (e.key === 'Enter' || e.key === 'Escape') (e.target as HTMLInputElement).blur();
}

function deleteWarehouseRow(m: MaterialRow): void {
    // Legacy removes the row from the page only, without asking; here the material is deactivated in the database.
    if (!window.confirm(`Удалить материал «${m.name}»?`)) return;
    router.delete(`/warehouse/materials/${m.id}`, {
        preserveScroll: true,
        preserveState: true,
        only: ['materials', 'stockValue'],
        onError: (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка удаления'),
    });
}
</script>

<template>
    <div style="display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; align-items: center">
        <select id="wh-wh-filter" v-model="filters.wh" style="font-size: 13px; width: auto">
            <option value="">Все склады</option>
            <option v-for="w in props.warehouses" :key="w.code" :value="w.code">{{ w.icon }} {{ w.name }}</option>
        </select>
        <select id="wh-type-filter" v-model="filters.type" style="font-size: 13px; width: auto" @change="onTypeChange">
            <option value="all">Все типы</option>
            <option value="material">🏭 Материалы</option>
            <option value="product">🪑 Продукция</option>
            <option value="semi">🔨 Полуфабрикаты</option>
        </select>
        <select id="wh-cat-filter" v-model="filters.cat" style="font-size: 13px; width: auto">
            <option value="">Все категории ({{ props.materials.length }})</option>
            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
        </select>
        <input id="wh-search" v-model="filters.search" type="text" placeholder="🔍 Поиск по наименованию..." style="flex: 1; max-width: 280px; font-size: 13px" />
        <select id="wh-status-filter" v-model="filters.status" style="font-size: 13px; width: auto">
            <option value="all">Все позиции</option>
            <option value="instock">✅ В наличии (qty &gt; 0)</option>
            <option value="critical">🔴 Критично (ниже минимума)</option>
            <option value="low">🟡 Мало (менее 2× минимума)</option>
            <option value="ok">🟢 Норма</option>
            <option value="zero">⚫ Нет в наличии</option>
        </select>
        <span id="wh-count" style="font-size: 12px; color: var(--text3)">{{ rows.length.toLocaleString('ru-RU') }} позиций</span>
    </div>
    <!-- KPI итого по фильтру -->
    <div id="wh-summary-bar" style="display: flex; gap: 16px; padding: 10px 16px; background: var(--bg3); border-radius: 8px; margin-bottom: 12px; font-size: 13px; flex-wrap: wrap">
        <div><span style="color: var(--text3)">Позиций: </span><span id="wh-sum-count" style="font-weight: 700">{{ summary.count }}</span></div>
        <div>
            <span style="color: var(--text3)">Общий остаток: </span><span id="wh-sum-qty" style="font-weight: 700; color: var(--accent)">{{ summary.qty }}</span>
        </div>
        <div><span style="color: var(--text3)">Сумма: </span><span id="wh-sum-value" style="font-weight: 700; color: var(--green)">{{ summary.value }}</span></div>
        <div>
            <span style="color: var(--text3)">⚠️ Критично: </span><span id="wh-sum-crit" style="font-weight: 700; color: var(--red)">{{ summary.critical }}</span>
        </div>
    </div>
    <div class="card" style="padding: 0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Склад</th>
                    <th>Наименование</th>
                    <th>SKU</th>
                    <th style="text-align: right">Остаток</th>
                    <th>Ед.</th>
                    <th style="text-align: right">Мин.</th>
                    <th style="text-align: right">Цена</th>
                    <th style="text-align: right">Сумма</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="warehouse-tbody">
                <tr v-for="m in rows" :key="m.id" v-memo="[m, editingField(m)]" :data-id="m.id">
                    <td><span class="badge badge-blue" style="font-size: 10px">{{ m.category || '—' }}</span></td>
                    <td>
                        <span class="editable" :class="{ editing: editingField(m) === 'name' }" @click="startEdit(m, 'name')">
                            <input v-if="editingField(m) === 'name'" v-model="editing.value" type="text" @blur="commit(m)" @keydown="onKey($event, m)" />
                            <template v-else>{{ m.name }}</template>
                        </span>
                    </td>
                    <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--text3)">
                        <span class="editable" :class="{ editing: editingField(m) === 'sku' }" @click="startEdit(m, 'sku')">
                            <input v-if="editingField(m) === 'sku'" v-model="editing.value" type="text" @blur="commit(m)" @keydown="onKey($event, m)" />
                            <template v-else>{{ m.sku || '' }}</template>
                        </span>
                    </td>
                    <td
                        style="text-align: right; font-family: 'IBM Plex Mono', monospace"
                        :style="whStatus(m) === 'critical' ? 'color:var(--red)' : whStatus(m) === 'low' ? 'color:var(--amber)' : ''"
                    >
                        <span style="display: inline-block; min-width: 20px" title="Остаток меняется складскими документами">{{ m.qty || 0 }}</span>
                    </td>
                    <td style="font-size: 12px; color: var(--text3)">
                        <span class="editable" :class="{ editing: editingField(m) === 'unit' }" @click="startEdit(m, 'unit')">
                            <input v-if="editingField(m) === 'unit'" v-model="editing.value" type="text" @blur="commit(m)" @keydown="onKey($event, m)" />
                            <template v-else>{{ m.unit || 'шт' }}</template>
                        </span>
                    </td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--text3)">
                        <span class="editable" :class="{ editing: editingField(m) === 'min_qty' }" @click="startEdit(m, 'min_qty')">
                            <input v-if="editingField(m) === 'min_qty'" v-model="editing.value" type="number" @blur="commit(m)" @keydown="onKey($event, m)" />
                            <template v-else>{{ m.min_qty || 0 }}</template>
                        </span>
                    </td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px">
                        <span class="editable" :class="{ editing: editingField(m) === 'price' }" @click="startEdit(m, 'price')">
                            <input v-if="editingField(m) === 'price'" v-model="editing.value" type="number" @blur="commit(m)" @keydown="onKey($event, m)" />
                            <template v-else>{{ m.price || 0 }}</template>
                        </span>
                    </td>
                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ fmtCurrency(m.value) }}</td>
                    <td>
                        <span class="badge" :class="STATUS_BADGE[whStatus(m)].cls">{{ STATUS_BADGE[whStatus(m)].label }}</span>
                    </td>
                    <td><button class="row-delete-btn" title="Удалить" @click="deleteWarehouseRow(m)">✕</button></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
