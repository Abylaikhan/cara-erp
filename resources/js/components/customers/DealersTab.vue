<script setup lang="ts">
// Tab «Дилеры» of «Покупатели» — legacy cust-dealers (L3718–3744), renderDealersTab (L19376) and the patched renderDealers
// (L38569, it replaces L19590), startEditCell (L28406), addDealerRow/deleteDealer (L19613), addDealerModal/saveDealer
// (L8441, L19773), downloadDealerTemplate/importDealersCSV/parseGenericCSV (L19815–19897). Same markup and texts; every
// change is saved by the server — legacy lost them on reload (QA-042). Orders, turnover and prepayment come from orders
// (M09): «—» until then. «Статус» shows the order status filter, which the page does not have, so it is always «Все».
import { router, usePage } from '@inertiajs/vue3';
import { nextTick, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { Dealer, SharedProps } from '@/types';

const props = defineProps<{ dealers: Dealer[]; active: boolean }>();

const page = usePage<SharedProps>();
const options = { preserveScroll: true, preserveState: true };
const fail = (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка сохранения');

// Legacy filterTable: the first cell contains the query.
const search = ref('');
const visible = (d: Dealer) => d.name.toLowerCase().includes(search.value.toLowerCase());

// ── Inline cells (startEditCell): Enter or leaving the cell saves, Esc restores; an empty name or region is not saved ──
type Field = 'name' | 'region';
const editing = reactive({ code: '', field: '' as Field | '', value: '' });
const shownValue = (d: Dealer, field: Field) => (field === 'name' ? d.name : (d.region ?? '—'));
const isEditing = (d: Dealer, field: Field) => editing.code === d.code && editing.field === field;

function startEdit(d: Dealer, field: Field): void {
    if (isEditing(d, field)) return;
    editing.code = d.code;
    editing.field = field;
    editing.value = shownValue(d, field);
    void nextTick(() => {
        const el = document.querySelector<HTMLInputElement>('#dealers-tbody .editing input');
        el?.focus();
        el?.select();
    });
}

function commitEdit(d: Dealer): void {
    if (!isEditing(d, editing.field as Field)) return;
    const field = editing.field as Field;
    const current = shownValue(d, field);
    const next = editing.value.trim() || current;
    editing.code = '';
    editing.field = '';
    if (next === current) return;
    router.put(`/customers/dealers/${d.code}`, { [field]: next, lock_version: d.lock_version }, { ...options, onError: fail });
}

function onKey(e: KeyboardEvent, d: Dealer): void {
    if (e.key === 'Escape') editing.value = shownValue(d, editing.field as Field);
    if (e.key === 'Enter' || e.key === 'Escape') (e.target as HTMLInputElement).blur();
}

// ── Rows ──
function addDealerRow(): void {
    router.post('/customers/dealers', { name: 'Новый дилер', region: '—' }, { ...options, onError: fail });
}

function deleteDealer(d: Dealer): void {
    // Legacy has no confirmation, but its deletion never worked (the id is passed as a string); here it is saved (ADR-0002, item 18).
    if (!window.confirm(`Удалить дилера «${d.name}»?`)) return;
    router.delete(`/customers/dealers/${d.code}`, { ...options, onError: fail });
}

// ── addDealerModal (saveDealer) ──
const modal = reactive({ open: false, name: '', region: '', error: '', saved: false, busy: false });

function openDealerModal(): void {
    modal.error = '';
    modal.saved = false;
    modal.open = true;
}

function closeDealerModal(): void {
    modal.open = false;
    modal.error = '';
    modal.saved = false;
}

function saveDealer(): void {
    const name = modal.name.trim();
    if (!name) return void (modal.error = 'Укажите название дилера');
    modal.error = '';
    router.post(
        '/customers/dealers',
        { name, region: modal.region.trim() || '—' },
        {
            ...options,
            onStart: () => (modal.busy = true),
            onFinish: () => (modal.busy = false),
            onError: (errors: Record<string, string>) => (modal.error = Object.values(errors)[0] ?? 'Ошибка сохранения'),
            onSuccess: () => {
                modal.name = '';
                modal.region = '';
                modal.saved = true;
                modal.error = '✓ Дилер добавлен! Теперь оформите его заказ в журнале «Все заказы».';
                window.setTimeout(closeDealerModal, 1800);
            },
        },
    );
}

// ── CSV: template and import (the result card stays for 6 seconds, as showGenericResult) ──
function downloadDealerTemplate(): void {
    const headers = ['Название дилера', 'Регион', 'Кол-во заказов', 'Оборот за год', 'Статус'];
    const example = ['Дилер «Пример»', 'Город', '0', '0', 'Активен'];
    const blob = new Blob(['﻿' + headers.join(';') + '\n' + example.join(';') + '\n'], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'shablon_dilery.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

const fileInput = ref<HTMLInputElement | null>(null);
function importDealersCSV(e: Event): void {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    router.post('/customers/dealers/import', { file }, { ...options, forceFormData: true, onFinish: () => (input.value = '') });
}

const result = ref<{ text: string; color: 'green' | 'red' } | null>(null);
let resultTimer: number | undefined;
watch(
    () => page.props.flash.result,
    (r) => {
        if (!r) return;
        result.value = r;
        window.clearTimeout(resultTimer);
        resultTimer = window.setTimeout(() => (result.value = null), 6000);
    },
);
</script>

<template>
    <!-- TAB: Dealers -->
    <div v-show="props.active" id="cust-dealers" class="cust-tab">
        <div class="section-header" style="margin-bottom: 14px">
            <div style="font-size: 13px; color: var(--text3)">Дилеры — заказы фиксируются от имени дилера, без отдельных контактов</div>
            <div style="display: flex; gap: 8px">
                <input id="dealer-file" ref="fileInput" type="file" accept=".csv" style="display: none" @change="importDealersCSV" />
                <button class="btn btn-ghost btn-sm" @click="fileInput?.click()">📥 Импорт из CSV</button>
                <button class="btn btn-ghost btn-sm" @click="downloadDealerTemplate">Шаблон CSV</button>
                <button class="btn btn-ghost btn-sm" @click="addDealerRow">+ Строка</button>
                <button class="btn btn-primary btn-sm" @click="openDealerModal">+ Подробно</button>
            </div>
        </div>
        <div v-show="result" id="dealer-import-result" style="margin-bottom: 16px">
            <div
                v-if="result"
                class="card"
                :style="{
                    borderColor: result.color === 'green' ? 'rgba(61,214,140,.3)' : 'rgba(245,92,92,.3)',
                    padding: '12px 16px',
                    fontSize: '13px',
                    color: result.color === 'green' ? 'var(--green)' : 'var(--red)',
                }"
            >
                {{ result.text }}
            </div>
        </div>
        <div style="display: flex; gap: 12px; margin-bottom: 20px">
            <div class="kpi" style="flex: 1">
                <div class="kpi-label">Всего дилеров</div>
                <div id="dealer-count" class="kpi-value" style="font-size: 20px">{{ props.dealers.length }}</div>
            </div>
            <div class="kpi" style="flex: 1">
                <div class="kpi-label">Заказов от дилеров (год)</div>
                <div id="dealer-orders" class="kpi-value" style="font-size: 20px">—</div>
            </div>
            <div class="kpi" style="flex: 1">
                <div class="kpi-label">Оборот (год)</div>
                <div id="dealer-turnover" class="kpi-value" style="font-size: 20px">—</div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Список дилеров · клик по ячейке — редактировать</span>
                <input v-model="search" type="text" placeholder="Поиск по названию..." style="width: 200px; font-size: 13px" />
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Название дилера</th>
                        <th>Регион</th>
                        <th>Кол-во заказов</th>
                        <th>Оборот</th>
                        <th>% предоплаты</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="dealers-tbody">
                    <tr v-for="d in props.dealers" v-show="visible(d)" :key="d.code" :data-id="d.code">
                        <td style="font-weight: 500">
                            <span class="editable" :class="{ editing: isEditing(d, 'name') }" @click="startEdit(d, 'name')">
                                <input v-if="isEditing(d, 'name')" v-model="editing.value" type="text" @blur="commitEdit(d)" @keydown="onKey($event, d)" />
                                <template v-else>{{ d.name }}</template>
                            </span>
                        </td>
                        <td style="font-size: 12px; color: var(--text3)">
                            <span class="editable" :class="{ editing: isEditing(d, 'region') }" @click="startEdit(d, 'region')">
                                <input v-if="isEditing(d, 'region')" v-model="editing.value" type="text" @blur="commitEdit(d)" @keydown="onKey($event, d)" />
                                <template v-else>{{ d.region ?? '—' }}</template>
                            </span>
                        </td>
                        <td style="font-family: monospace; color: var(--text3)">—</td>
                        <td style="font-family: monospace">—</td>
                        <td style="font-family: monospace; color: var(--red)">—</td>
                        <td><span style="color: var(--text3); font-size: 12px">Все</span></td>
                        <td><button class="row-delete-btn" @click="deleteDealer(d)">✕</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ErpModal id="addDealerModal" :open="modal.open" @close="closeDealerModal">
        <div class="modal-header">
            <div class="modal-title">Новый дилер</div>
            <button class="modal-close" @click="closeDealerModal">✕</button>
        </div>
        <div id="dealer-form-error" style="font-size: 13px; margin-bottom: 8px; min-height: 18px" :style="{ color: modal.saved ? 'var(--green)' : 'var(--red)' }">{{ modal.error }}</div>
        <div class="form-row cols-2">
            <div><label class="label">Название дилера</label><input id="dlr-name" v-model="modal.name" type="text" placeholder="Дилер «...»" /></div>
            <div><label class="label">Регион</label><input id="dlr-region" v-model="modal.region" type="text" placeholder="Город" /></div>
        </div>
        <div style="font-size: 12px; color: var(--text3); margin-bottom: 8px">
            Кол-во заказов, оборот, % предоплаты и статус рассчитываются автоматически из журнала «Все заказы» — после создания дилера оформите его заказы там.
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
            <button class="btn btn-ghost" @click="closeDealerModal">Отмена</button>
            <button class="btn btn-primary" :disabled="modal.busy || modal.saved" @click="saveDealer">Сохранить</button>
        </div>
    </ErpModal>
</template>
