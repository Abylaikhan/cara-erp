<script setup lang="ts">
// M05 «Входящие ЭСФ» — legacy page-esf (L3056–3238), esfDetailModal (L3179), esfXmlImportModal: initEsfPage, renderEsfList,
// filterEsfUnreconciled, setEsfType, openEsfDetail, handleEsfXmlFiles/renderEsfXmlImportPreview/confirmEsfXmlImport
// (L11926–12560). Same markup, texts and calculations; XML is parsed and imported by the server. «Сверка с оплатами» is
// EsfReconTab (ADR-0043, ADR-0048); «📦 Принять» / «📦 На склад» take a line in with a posted receipt (ADR-0049); «Создать
// расход» waits for the money module (docs/adr/0009-esf.md).
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import EsfReconTab, { type ReconData } from '@/components/esf/EsfReconTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import type { EsfPreview, SharedProps } from '@/types';

interface Invoice {
    id: number;
    num: string | null;
    reg_num: string | null;
    date: string;
    status: string;
    invoice_type: string;
    supplier_name: string;
    supplier_bin: string | null;
    total_amount: number;
    total_nds: number;
    total_with_nds: number;
    linked: number;
}

interface Detail {
    id: number;
    num: string | null;
    date: string;
    reg_num: string | null;
    status: string;
    supplier_name: string;
    supplier_bin: string | null;
    buyer_name: string | null;
    total_amount: number;
    total_nds: number;
    total_with_nds: number;
    items: {
        id: number;
        description: string;
        qty: number;
        unit_price: number;
        amount: number;
        nds_rate: number;
        nds_amount: number;
        total: number;
        match: { material: string; via: string; id: number; qty: number } | null;
        received: string | null;
    }[];
}

const props = defineProps<{ invoices: Invoice[]; materialWarehouses: { code: string; name: string; icon: string }[]; recon?: ReconData }>();
const page = usePage<SharedProps>();

const fmt = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const STATUS_LABEL: Record<string, string> = { DELIVERED: '✅ Доставлен', CREATED: '📝 Создан', CANCELLED: '❌ Отменён' };
const STATUS_COLOR: Record<string, string> = { DELIVERED: 'var(--green)', CREATED: 'var(--amber)', CANCELLED: 'var(--red)' };
const TYPE_COLOR: Record<string, string> = { goods: 'var(--accent)', service: 'var(--green)', general: 'var(--text3)' };

const tab = ref<'list' | 'recon'>('list');

// ── Filters and list (renderEsfList) ──
const f = reactive({ search: '', status: '', type: '', from: '', to: '' });
const onlyUnreconciled = ref(false);
const beforeToggle = computed(() => {
    const search = f.search.toLowerCase();
    return props.invoices.filter((inv) => {
        if (search && !inv.supplier_name?.toLowerCase().includes(search) && !inv.num?.includes(search) && !inv.reg_num?.includes(search)) return false;
        if (f.status && inv.status !== f.status) return false;
        if (f.type && (inv.invoice_type || 'general') !== f.type) return false;
        if (f.from && inv.date < f.from) return false;
        if (f.to && inv.date > f.to) return false;
        return true;
    });
});
const invs = computed(() => (onlyUnreconciled.value ? beforeToggle.value.filter((inv) => inv.linked <= 100) : beforeToggle.value));
const kpi = computed(() => [
    ['📄 Счетов', String(invs.value.length), ''],
    ['💰 Без НДС', fmt(invs.value.reduce((s, i) => s + i.total_amount, 0)), 'var(--accent)'],
    ['🧾 НДС', fmt(invs.value.reduce((s, i) => s + i.total_nds, 0)), 'var(--amber)'],
    ['💳 С НДС', fmt(invs.value.reduce((s, i) => s + i.total_with_nds, 0)), 'var(--green)'],
]);
const recon = computed(() => {
    let reconciled = 0;
    let partial = 0;
    let not = 0;
    beforeToggle.value.forEach((inv) => {
        if (inv.linked <= 0) not++;
        else if (inv.linked >= (inv.total_with_nds || 0) - 100) reconciled++;
        else partial++;
    });
    return { reconciled, partial, not };
});
const reconBadge = (inv: Invoice) =>
    inv.linked <= 0 ? { cls: 'badge', style: 'background:var(--bg3);color:var(--text3)', text: 'не сверено' } : inv.linked >= (inv.total_with_nds || 0) - 100 ? { cls: 'badge badge-green', style: '', text: '✓ сверено' } : { cls: 'badge badge-amber', style: '', text: 'частично' };

function setEsfType(inv: Invoice, type: string): void {
    router.put(`/esf/${inv.id}/type`, { invoice_type: type }, { preserveScroll: true, preserveState: true, only: ['invoices'] });
}

// ── Detail (openEsfDetail): loaded on demand ──
const detail = ref<Detail | null>(null);
const detailOpen = ref(false);
let lastDetail = 0;
async function openEsfDetail(inv: Invoice): Promise<void> {
    lastDetail = inv.id; // two invoices clicked quickly: only the last one opens
    const response = await fetch(`/esf/${inv.id}/detail`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (lastDetail !== inv.id) return;
    if (!response.ok) return void window.alert('Не удалось открыть ЭСФ — обновите страницу.');
    const loaded = (await response.json()) as Detail;
    if (lastDetail !== inv.id) return;
    detail.value = loaded;
    detailOpen.value = true;
}
const DETAIL_STATUS: Record<string, string> = { ...STATUS_LABEL, POSTED: '🏷 Проведён' };

// ── Import (handleEsfXmlFiles → preview → confirmEsfXmlImport) ──
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<EsfPreview | null>(null);
const checked = reactive(new Set<number>());
function handleEsfXmlFiles(e: Event): void {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    if (!files.length) return;
    router.post('/esf/import/preview', { files }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onError: (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка загрузки'),
        onFinish: () => (input.value = ''),
    });
}
watch(
    () => page.props.flash.esfPreview,
    (p) => {
        if (!p) return;
        preview.value = p;
        checked.clear();
        p.rows.forEach((r) => r.ok && !r.duplicate && checked.add(r.index));
    },
);
function toggleRow(i: number, on: boolean): void {
    if (on) checked.add(i);
    else checked.delete(i);
}
function confirmEsfXmlImport(): void {
    if (!preview.value) return;
    router.post('/esf/import', { token: preview.value.token, selected: [...checked] }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (preview.value = null),
        onError: (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка импорта'),
    });
}
watch(
    () => page.props.flash.dialog,
    (d) => {
        if (d && d.type === 'alert') window.alert(d.text);
    },
);

// ── Functions that need other modules ──
const later = (text: string) => () => window.alert(text);
// Legacy exportEsfGoodsToExcel (L12832), ADR-0044: the server reads every goods line and builds the file.
async function exportGoods(): Promise<void> {
    let r: Response;
    try {
        r = await fetch('/esf/export-goods', { credentials: 'same-origin', headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
    } catch {
        window.alert('Нет связи с сервером.\n\nФайл не сформирован. Проверьте соединение и повторите.');
        return;
    }
    if (!r.ok) {
        window.alert(`Файл не сформирован (HTTP ${r.status}).`);
        return;
    }
    const url = URL.createObjectURL(await r.blob());
    const a = Object.assign(document.createElement('a'), { href: url, download: 'ЭСФ_товары_склады.xlsx' });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    window.alert(`✅ Выгружено ${r.headers.get('X-Export-Rows')} позиций из ${r.headers.get('X-Export-Invoices')} ЭСФ`);
}
const createExpense = later('Расход из ЭСФ появится вместе с проведением денежных операций (ERP-031…033, решения D-03 и D-11).');
// Legacy acceptItemToWarehouse: the chosen warehouse of the line; to a matched material after a confirmation, else a new
// material named in two prompts. The server posts a receipt with this line (ADR-0049); a line is taken in once.
const lineWarehouse = reactive<Record<number, string>>({});
const whOf = (it: Detail['items'][number]) => props.materialWarehouses.find((w) => w.code === (lineWarehouse[it.id] ?? props.materialWarehouses[0]?.code));
function acceptItemToWarehouse(it: Detail['items'][number]): void {
    const ws = whOf(it);
    if (!detail.value || !ws) return;
    let data: Record<string, string | number>;
    let done: () => string;
    if (it.match) {
        const newQty = it.match.qty + it.qty;
        if (!window.confirm(`Добавить ${it.qty} ед. к "${it.match.material}" (${ws.icon} ${ws.name})?\nБудет: ${newQty} ед.`)) return;
        data = { material_id: it.match.id, warehouse: ws.code };
        const name = it.match.material;
        done = () => `✅ Остаток обновлён: ${name} → ${newQty} ед.`;
    } else {
        const name = window.prompt(`Название материала (${ws.icon} ${ws.name}):`, it.description);
        if (!name) return;
        const unit = window.prompt('Единица измерения:', 'шт') || 'шт';
        data = { warehouse: ws.code, name, unit };
        done = () => `✅ "${name}" → ${ws.icon} ${ws.name}: ${it.qty} ${unit}`;
    }
    const id = detail.value.id;
    router.post(`/esf/items/${it.id}/to-warehouse`, data, {
        preserveScroll: true,
        preserveState: true,
        only: ['flash'],
        onSuccess: async () => {
            window.alert(done());
            const response = await fetch(`/esf/${id}/detail`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (response.ok && detail.value?.id === id) detail.value = (await response.json()) as Detail;
        },
        onError: (e) => window.alert('Ошибка: ' + (Object.values(e)[0] || '')),
    });
}
</script>

<template>
    <ErpLayout>
        <div id="page-esf" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">📄 Входящие ЭСФ</div>
                    <div class="section-sub">Электронные счета-фактуры от поставщиков</div>
                </div>
                <button class="btn btn-ghost btn-sm" @click="router.reload({ only: ['invoices'] })">🔄</button>
                <button class="btn btn-ghost btn-sm" @click="exportGoods">📥 Товары в Excel</button>
                <button class="btn btn-primary btn-sm" @click="fileInput?.click()">📤 Импорт ЭСФ (XML)</button>
                <input id="esf-xml-input" ref="fileInput" type="file" accept=".xml" multiple style="display: none" @change="handleEsfXmlFiles" />
            </div>

            <div class="tabs" style="margin-bottom: 14px">
                <div id="esf-tab-btn-list" class="tab" :class="{ active: tab === 'list' }" @click="tab = 'list'">📋 Список ЭСФ</div>
                <div id="esf-tab-btn-recon" class="tab" :class="{ active: tab === 'recon' }" @click="tab = 'recon'">🔗 Сверка с оплатами</div>
            </div>

            <div v-show="tab === 'list'" id="esf-tab-list">
                <!-- Фильтры -->
                <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center">
                    <input
                        id="esf-search"
                        v-model="f.search"
                        type="search"
                        placeholder="🔍 Поставщик, номер..."
                        style="font-size: 13px; flex: 1; min-width: 200px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1)"
                    />
                    <select id="esf-status" v-model="f.status" style="font-size: 13px">
                        <option value="">Все статусы</option>
                        <option value="DELIVERED">Доставлен</option>
                        <option value="CREATED">Создан</option>
                        <option value="CANCELLED">Отменён</option>
                    </select>
                    <select id="esf-type" v-model="f.type" style="font-size: 13px">
                        <option value="">Все типы</option>
                        <option value="general">📋 Общее</option>
                        <option value="service">🔧 Услуги</option>
                        <option value="goods">📦 Товары</option>
                    </select>
                    <input id="esf-from" v-model="f.from" type="date" style="font-size: 13px" />
                    <input id="esf-to" v-model="f.to" type="date" style="font-size: 13px" />
                </div>

                <!-- KPI -->
                <div id="esf-kpi" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 10px">
                    <div v-for="[label, value, color] in kpi" :key="label" class="card" style="padding: 12px 14px">
                        <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">{{ label }}</div>
                        <div style="font-size: 18px; font-weight: 700" :style="color ? 'color:' + color : ''">{{ value }}</div>
                    </div>
                </div>
                <div id="esf-recon-summary" style="font-size: 12px; margin-bottom: 16px; padding: 8px 12px; background: var(--bg3); border-radius: 6px">
                    <span style="color: var(--green)">✓ Полностью сверено: <strong>{{ recon.reconciled }}</strong></span>{{ ' ' }}<span style="color: var(--amber); margin-left: 14px"
                        >◐ Частично: <strong>{{ recon.partial }}</strong></span
                    >{{ ' ' }}<span style="color: var(--red); margin-left: 14px">✕ Не сверено: <strong>{{ recon.not }}</strong></span
                    >{{ ' ' }}<button
                        v-if="recon.not > 0 || onlyUnreconciled"
                        class="btn btn-ghost btn-sm"
                        style="margin-left: 14px; font-size: 11px"
                        @click="onlyUnreconciled = !onlyUnreconciled"
                    >
                        {{ onlyUnreconciled ? 'Показать все' : 'Показать только несверенные' }}
                    </button>
                </div>

                <!-- Список ЭСФ -->
                <div id="esf-list">
                    <div v-if="invs.length" class="card" style="padding: 0">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Тип</th>
                                    <th>Дата</th>
                                    <th>Номер</th>
                                    <th>Поставщик</th>
                                    <th>БИН</th>
                                    <th style="text-align: right">Без НДС</th>
                                    <th style="text-align: right">НДС</th>
                                    <th style="text-align: right; color: var(--green)">С НДС</th>
                                    <th>Статус</th>
                                    <th>Сверка</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="inv in invs" :key="inv.id" v-memo="[inv]" style="cursor: pointer" @click="openEsfDetail(inv)">
                                    <td @click.stop>
                                        <select
                                            :value="inv.invoice_type || 'general'"
                                            style="font-size: 11px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg2); padding: 2px 4px"
                                            :style="{ color: TYPE_COLOR[inv.invoice_type || 'general'] }"
                                            @change="setEsfType(inv, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option value="general">📋 Общее</option>
                                            <option value="service">🔧 Услуги</option>
                                            <option value="goods">📦 Товары</option>
                                        </select>
                                    </td>
                                    <td style="font-size: 12px; white-space: nowrap">{{ inv.date || '—' }}</td>
                                    <td style="font-family: monospace; font-size: 12px">{{ inv.num || '—' }}</td>
                                    <td style="font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ inv.supplier_name || '—' }}</td>
                                    <td style="font-size: 11px; color: var(--text3)">{{ inv.supplier_bin || '—' }}</td>
                                    <td style="text-align: right; font-family: monospace; font-size: 12px">{{ fmt(inv.total_amount) }}</td>
                                    <td style="text-align: right; font-family: monospace; font-size: 12px; color: var(--amber)">{{ fmt(inv.total_nds) }}</td>
                                    <td style="text-align: right; font-family: monospace; font-size: 12px; font-weight: 600; color: var(--green)">{{ fmt(inv.total_with_nds) }}</td>
                                    <td>
                                        <span style="font-size: 11px" :style="{ color: STATUS_COLOR[inv.status] || 'var(--text3)' }">{{ STATUS_LABEL[inv.status] || inv.status }}</span>
                                    </td>
                                    <td>
                                        <span :class="reconBadge(inv).cls" :style="reconBadge(inv).style">{{ reconBadge(inv).text }}</span>
                                    </td>
                                    <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click.stop="openEsfDetail(inv)">📋</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else style="text-align: center; padding: 40px; color: var(--text3)">Нет ЭСФ</div>
                </div>
            </div>

            <div v-show="tab === 'recon'" id="esf-tab-recon">
                <EsfReconTab :invoices="props.invoices" :data="props.recon" :active="tab === 'recon'" />
            </div>
        </div>

        <!-- MODAL: ЭСФ -->
        <ErpModal id="esfDetailModal" :open="detailOpen" max-width="800px" @close="detailOpen = false">
            <div class="modal-header">
                <div id="esf-modal-title" class="modal-title">{{ detail ? `ЭСФ №${detail.num} от ${detail.date}` : 'ЭСФ' }}</div>
                <button class="modal-close" @click="detailOpen = false">✕</button>
            </div>
            <div v-if="detail" id="esf-modal-body" class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; font-size: 13px">
                    <div>
                        <div style="color: var(--text3); font-size: 11px">Поставщик</div>
                        <div style="font-weight: 500">{{ detail.supplier_name || '—' }}</div>
                        <div style="color: var(--text3)">{{ detail.supplier_bin || '' }}</div>
                    </div>
                    <div>
                        <div style="color: var(--text3); font-size: 11px">Покупатель</div>
                        <div style="font-weight: 500">{{ detail.buyer_name || '—' }}</div>
                    </div>
                    <div>
                        <div style="color: var(--text3); font-size: 11px">Рег. номер</div>
                        <div style="font-size: 11px; font-family: monospace">{{ detail.reg_num || '—' }}</div>
                    </div>
                    <div>
                        <div style="color: var(--text3); font-size: 11px">Статус</div>
                        <div>{{ DETAIL_STATUS[detail.status] || detail.status || '—' }}</div>
                    </div>
                </div>
                <table class="data-table" style="margin-bottom: 12px; font-size: 12px">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Наименование</th>
                            <th style="text-align: right">Кол-во</th>
                            <th style="text-align: right">Цена</th>
                            <th style="text-align: right">Сумма</th>
                            <th style="text-align: right">НДС%</th>
                            <th style="text-align: right">НДС</th>
                            <th style="text-align: right">Итого</th>
                            <th>Склад</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(it, i) in detail.items" :key="it.id">
                            <td style="color: var(--text3)">{{ i + 1 }}</td>
                            <td>{{ it.description || '—' }}</td>
                            <td style="text-align: right">{{ it.qty }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(it.unit_price) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(it.amount) }}</td>
                            <td style="text-align: right; color: var(--text3)">{{ it.nds_rate }}%</td>
                            <td style="text-align: right; font-family: monospace; color: var(--amber)">{{ fmt(it.nds_amount) }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600">{{ fmt(it.total) }}</td>
                            <td>
                                <div v-if="it.match" style="font-size: 11px; color: var(--green)">✅ {{ it.match.material }}</div>
                                <div v-if="it.received" style="font-size: 11px; color: var(--text3)">📦 Принято: {{ it.received }}</div>
                                <template v-else>
                                    <select
                                        :id="`ws-sel-${it.id}`"
                                        :value="lineWarehouse[it.id] ?? props.materialWarehouses[0]?.code"
                                        style="font-size: 10px; width: 100%"
                                        :style="it.match ? 'margin-top:2px' : ''"
                                        @change="lineWarehouse[it.id] = ($event.target as HTMLSelectElement).value"
                                    >
                                        <option v-for="w in props.materialWarehouses" :key="w.code" :value="w.code">{{ w.icon }} {{ w.name }}</option>
                                    </select>
                                    <button
                                        class="btn btn-ghost btn-sm"
                                        style="margin-top: 2px; width: 100%"
                                        :style="{ fontSize: it.match ? '10px' : '11px' }"
                                        @click="acceptItemToWarehouse(it)"
                                    >
                                        {{ it.match ? '📦 Принять' : '📦 На склад' }}
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700">
                            <td colspan="4">Итого</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(detail.total_amount) }}</td>
                            <td></td>
                            <td style="text-align: right; font-family: monospace; color: var(--amber)">{{ fmt(detail.total_nds) }}</td>
                            <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(detail.total_with_nds) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" @click="detailOpen = false">Закрыть</button>{{ ' ' }}
                <button id="esf-create-expense-btn" class="btn btn-primary" @click="createExpense">💳 Создать расход</button>
            </div>
        </ErpModal>

        <!-- MODAL: Импорт ЭСФ из XML — предпросмотр -->
        <ErpModal id="esfXmlImportModal" :open="preview !== null" max-width="920px" :modal-style="{ maxHeight: '88vh', overflowY: 'auto' }" @close="preview = null">
            <div class="modal-header">
                <span class="modal-title">📤 Импорт ЭСФ из XML — предпросмотр</span>
                <button class="modal-close" @click="preview = null">✕</button>
            </div>
            <template v-if="preview">
                <div id="esf-xml-import-summary" style="font-size: 12px; color: var(--text3); margin-bottom: 10px">
                    Файлов: {{ preview.total }} · распознано корректно: {{ preview.ok
                    }}<span v-if="preview.ok < preview.total" style="color: var(--red)"> · не распознано: {{ preview.total - preview.ok }} (проверьте формат ниже)</span>
                </div>
                <div id="esf-xml-import-list">
                    <template v-for="r in preview.rows" :key="r.index">
                        <div v-if="!r.ok" class="card" style="padding: 10px 14px; margin-bottom: 8px; border: 1px solid var(--red)">
                            <div style="font-size: 12px; color: var(--red)">⚠️ {{ r.fileName }}: {{ r.error }}</div>
                        </div>
                        <div v-else class="card" style="padding: 10px 14px; margin-bottom: 8px" :style="r.duplicate ? 'opacity:0.6' : ''">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px">
                                <input :id="`esf-xml-chk-${r.index}`" type="checkbox" :checked="checked.has(r.index)" style="width: auto" @change="toggleRow(r.index, ($event.target as HTMLInputElement).checked)" />
                                <span style="font-weight: 600; font-size: 13px">{{ r.date }} — №{{ r.num || '—' }} — {{ r.supplier_name }}</span>
                                <span v-if="r.supplier_bin" style="font-size: 11px; color: var(--text3); font-family: monospace">БИН {{ r.supplier_bin }}</span>
                                <span v-if="r.duplicate" style="font-size: 11px; color: var(--amber)">⚠️ похоже, уже есть в базе ({{ r.duplicate }})</span>
                                <span style="margin-left: auto; font-family: monospace; font-weight: 600">{{ fmt(r.total_with_nds || 0) }}</span>
                            </div>
                            <div style="font-size: 11px; color: var(--text3)">
                                {{ r.fileName }} · {{ r.lines }} строк · без НДС {{ fmt(r.total_amount || 0) }} · НДС {{ fmt(r.total_nds || 0) }}
                            </div>
                        </div>
                    </template>
                </div>
            </template>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
                <button class="btn btn-ghost" @click="preview = null">Отмена</button>
                <button class="btn btn-primary" @click="confirmEsfXmlImport">Импортировать отмеченные</button>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
