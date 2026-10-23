<script setup lang="ts">
// Tab «Рабочий лист производства» of «Производство» — legacy mrp-worksheet (L2389–2450), initProdWorksheet/pwbPopulateFilters/
// renderProdWorksheet/itemCharacteristicsText/togglePwbRow/togglePwbSelectAll/initPwbColumnResize/syncStickyScroll/initStickyScroll
// (L8877–8890, L35261–35620): the order lines put on the worksheet with their order, channel, dates, characteristics, workshops,
// stages, executors and materials, the filters, the selection, resizable columns and the scrollbar under the table. Adding
// lines, the Excel import and export, removing, clearing and the stage editor come with the worksheet with posting
// (ERP-041/042, D-05, D-06) and the exports (ERP-067) — ADR-0041.
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import PosBadge from '@/components/PosBadge.vue';
import { PIECE_WORKSHOPS } from './work';

export interface PwbRow {
    key: string;
    num: string;
    pos: number;
    channel: string | null;
    date: string | null;
    ship: string | null;
    dateIso: string | null;
    shipIso: string | null;
    name: string;
    category: string | null;
    chars: string;
    stages: { stage: string; workshop: string; posted: boolean; started: boolean; assignees: string[] }[];
    materials: string[];
    completed: boolean;
}
export interface PwbData {
    rows: PwbRow[];
    execs: string[];
}
const props = defineProps<{ data: PwbData | undefined; active: boolean; operations: string[] }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.data === undefined) router.reload({ only: ['worksheet'] });
    },
    { immediate: true },
);

const LATER = 'Строки рабочего листа добавляются и удаляются вместе с проведением этапов и списанием материалов (ERP-041…043, решения D-05 и D-06).';
const later = () => window.alert(LATER);
const editLater = () => window.alert('Этапы, исполнители и материалы позиции записываются вместе с проведением этапов (ERP-042, решения D-05 и D-06).');
const exportLater = () => window.alert('Выгрузка рабочего листа в Excel появится вместе с остальными выгрузками (ERP-067).');
const importLater = () => window.alert('Загрузка рабочего листа из Excel появится вместе с проведением этапов (ERP-041) и выгрузками (ERP-067).');

// Legacy PL_CHANNEL_LABELS (L26448).
const CHANNELS: Record<string, string> = {
    salon_armada: 'Салон Армада',
    salon_nazarbayeva: 'Салон Назарбаева',
    dealer_astana: 'Дилер Астана',
    dealer_aktau: 'Дилер Актау',
    dealer_atyrau: 'Дилер Атырау',
    dealer_shymkent: 'Дилер Шымкент',
    dealer_aktobe: 'Дилер Актобе',
    office: 'Офис',
};
const channelLabel = (c: string | null) => (c ? CHANNELS[c] || c : '—');
const workshopLabel = (id: string) => {
    const w = PIECE_WORKSHOPS.find((x) => x.id === id);
    return w ? w.icon + ' ' + w.name : id;
};

// ── Filters (legacy pwb-f-*; the lists as pwbPopulateFilters fills them) ──
const f = reactive({ search: '', order: '', category: '', workshop: '', stage: '', exec: '', orderFrom: '', orderTo: '', shipFrom: '', shipTo: '' });
const rows = computed(() => props.data?.rows ?? []);
const categories = computed(() => [...new Set(rows.value.map((r) => r.category).filter((c): c is string => !!c))].sort());
const filtered = computed(() => {
    const search = f.search.toLowerCase();
    const orderF = f.order.toLowerCase();
    return rows.value.filter((r) => {
        if (search && !r.name.toLowerCase().includes(search) && search.replace(/^№\s*/, '') !== String(r.pos)) return false;
        if (
            orderF &&
            !r.num.toLowerCase().includes(orderF) &&
            !(/^\d+$/.test(orderF) && (r.num.match(/\d+/g) || []).some((d) => parseInt(d, 10) === parseInt(orderF, 10)))
        )
            return false;
        if (f.category && r.category !== f.category) return false;
        const orderIso = r.dateIso || '';
        const shipIso = r.shipIso || '';
        if (f.orderFrom && orderIso < f.orderFrom) return false;
        if (f.orderTo && orderIso && orderIso > f.orderTo) return false;
        if (f.shipFrom && shipIso < f.shipFrom) return false;
        if (f.shipTo && shipIso && shipIso > f.shipTo) return false;
        if (f.workshop && !r.stages.some((s) => s.workshop === f.workshop)) return false;
        if (f.stage && !r.stages.some((s) => s.stage === f.stage)) return false;
        if (f.exec && !r.stages.some((s) => s.assignees.some((a) => a === f.exec))) return false;
        return true;
    });
});
const workshopsOf = (r: PwbRow) => [...new Set(r.stages.map((s) => s.workshop).filter(Boolean))];
const execsOf = (r: PwbRow) => [...new Set(r.stages.flatMap((s) => s.assignees))];

// ── Selection (legacy pwbSelected: kept while the rows are filtered) ──
const selected = ref(new Set<string>());
function toggleRow(key: string, on: boolean): void {
    const next = new Set(selected.value);
    if (on) next.add(key);
    else next.delete(key);
    selected.value = next;
}
// Legacy togglePwbSelectAll: the rows on the screen.
function toggleAll(on: boolean): void {
    const next = new Set(selected.value);
    filtered.value.forEach((r) => (on ? next.add(r.key) : next.delete(r.key)));
    selected.value = next;
}
function deleteSelected(): void {
    if (!selected.value.size) {
        window.alert('Ничего не отмечено.');
        return;
    }
    later();
}

// ── Columns: widths kept in the browser (legacy initPwbColumnResize, the same key) ──
const COL_KEY = 'promtech_pwb_col_widths_v1';
const DEFAULT_WIDTHS = [28, 100, 90, 70, 70, 60, 170, 170, 170, 60, 90, 40];
const widths = ref<number[]>(
    (() => {
        try {
            const saved = JSON.parse(localStorage.getItem(COL_KEY) || 'null');
            if (Array.isArray(saved)) return DEFAULT_WIDTHS.map((w, i) => (saved[i] ? +saved[i] : w));
        } catch {
            /* legacy ignores a broken value */
        }
        return [...DEFAULT_WIDTHS];
    })(),
);
function startResize(e: MouseEvent, col: number): void {
    e.preventDefault();
    const startX = e.clientX;
    const th = (e.target as HTMLElement).parentElement as HTMLElement;
    const startWidth = th.getBoundingClientRect().width;
    const onMove = (ev: MouseEvent) => {
        widths.value[col] = Math.max(30, startWidth + (ev.clientX - startX));
        syncPhantom();
    };
    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        try {
            localStorage.setItem(COL_KEY, JSON.stringify(widths.value.map((w) => Math.trunc(w) || null)));
        } catch {
            /* as legacy */
        }
    };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

// ── The scrollbar under the table (legacy initStickyScroll/syncStickyScroll) ──
const wrap = ref<HTMLElement | null>(null);
const bar = ref<HTMLElement | null>(null);
const phantomWidth = ref(0);
function syncPhantom(): void {
    nextTick(() => {
        const table = wrap.value?.querySelector('table');
        if (table) phantomWidth.value = table.scrollWidth;
    });
}
const fromTable = () => bar.value && wrap.value && (bar.value.scrollLeft = wrap.value.scrollLeft);
const fromBar = () => bar.value && wrap.value && (wrap.value.scrollLeft = bar.value.scrollLeft);
watch([filtered, () => props.active], syncPhantom);
onMounted(syncPhantom);

const HEADERS = ['Заказ / Канал', 'Дата заказа / отправки', 'Товар', 'Характеристики', 'Цех', 'Этапы', 'Исполнители', 'Материалы', 'Техкарта', 'Статус'];
const SHADED = new Set([5, 6, 7]);
</script>

<template>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px">
        <button class="btn btn-ghost btn-sm" @click="later">+ За 3 дня</button>
        <button class="btn btn-ghost btn-sm" @click="later">+ За неделю</button>
        <button class="btn btn-ghost btn-sm" @click="later">+ За месяц</button>
        <button class="btn btn-ghost btn-sm" @click="later">+ За год</button>
        <input id="pwb-add-order" type="text" placeholder="№ заказа..." style="width: 120px; font-size: 12px" />
        <button class="btn btn-ghost btn-sm" @click="later">+ Заказ</button>
        <span style="flex: 1"></span>
        <button class="btn btn-ghost btn-sm" @click="importLater">📤 Импорт Excel</button>
        <button class="btn btn-ghost btn-sm" @click="exportLater">📥 Экспорт Excel</button>
        <button class="btn btn-ghost btn-sm" @click="deleteSelected">✕ Удалить отмеченные</button>
        <button class="btn btn-ghost btn-sm" @click="later">🗑 Очистить лист</button>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
        <input id="pwb-f-search" v-model="f.search" type="search" placeholder="🔍 Товар..." style="width: 160px; font-size: 12px" />
        <input id="pwb-f-order" v-model="f.order" type="text" placeholder="№ заказа..." style="width: 110px; font-size: 12px" />
        <select id="pwb-f-category" v-model="f.category" style="font-size: 12px; width: auto">
            <option value="">Все категории</option>
            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
        </select>
        <select id="pwb-f-workshop" v-model="f.workshop" style="font-size: 12px; width: auto">
            <option value="">Все цеха</option>
            <option v-for="w in PIECE_WORKSHOPS" :key="w.id" :value="w.id">{{ w.icon }} {{ w.name }}</option>
        </select>
        <select id="pwb-f-stage" v-model="f.stage" style="font-size: 12px; width: auto">
            <option value="">Все этапы</option>
            <option v-for="s in props.operations" :key="s" :value="s">{{ s }}</option>
        </select>
        <select id="pwb-f-exec" v-model="f.exec" style="font-size: 12px; width: auto">
            <option value="">Все исполнители</option>
            <option v-for="(e, i) in props.data?.execs ?? []" :key="i" :value="e">{{ e }}</option>
        </select>
        <span style="font-size: 11px; color: var(--text3)">Заказ:</span>
        <input id="pwb-f-order-from" v-model="f.orderFrom" type="date" style="font-size: 12px; width: auto" />
        <input id="pwb-f-order-to" v-model="f.orderTo" type="date" style="font-size: 12px; width: auto" />
        <span style="font-size: 11px; color: var(--text3)">Отправка:</span>
        <input id="pwb-f-ship-from" v-model="f.shipFrom" type="date" style="font-size: 12px; width: auto" />
        <input id="pwb-f-ship-to" v-model="f.shipTo" type="date" style="font-size: 12px; width: auto" />
    </div>
    <div class="card" style="padding: 0">
        <div id="pwb-table-wrap" ref="wrap" style="overflow-x: auto" @scroll="fromTable">
            <table id="pwb-table" class="data-table" style="font-size: 12px; white-space: nowrap; table-layout: fixed">
                <colgroup id="pwb-colgroup">
                    <col v-for="(w, i) in widths" :key="i" :style="{ width: w + 'px' }" />
                </colgroup>
                <thead>
                    <tr>
                        <th><input type="checkbox" @change="toggleAll(($event.target as HTMLInputElement).checked)" /></th>
                        <th
                            v-for="(h, i) in HEADERS"
                            :key="h"
                            class="pwb-resizable"
                            :style="SHADED.has(i) ? 'background: var(--bg3); position: relative' : 'position: relative'"
                        >
                            {{ h
                            }}<div
                                style="position: absolute; top: 0; right: -3px; width: 6px; height: 100%; cursor: col-resize; user-select: none; z-index: 2"
                                @mousedown="startResize($event, i + 1)"
                            ></div>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="pwb-tbody">
                    <template v-if="props.data !== undefined">
                        <tr v-if="!filtered.length">
                            <td colspan="12" style="text-align: center; color: var(--text3); padding: 24px">Лист пуст — добавьте заказы кнопками выше</td>
                        </tr>
                        <tr v-for="r in filtered" :key="r.key">
                            <td>
                                <input
                                    type="checkbox"
                                    class="pwb-row-check"
                                    :data-key="r.key"
                                    :checked="selected.has(r.key)"
                                    @change="toggleRow(r.key, ($event.target as HTMLInputElement).checked)"
                                />
                            </td>
                            <td style="font-size: 11px">
                                <div style="font-family: monospace; color: var(--accent)">{{ r.num }}</div>
                                <div style="color: var(--text3)">{{ channelLabel(r.channel) }}</div>
                            </td>
                            <td style="font-size: 11px">
                                <div>{{ r.date || '—' }}</div>
                                <div style="color: var(--text3)">{{ r.ship || '—' }}</div>
                            </td>
                            <td style="max-width: 70px; white-space: normal; font-weight: 500"><PosBadge :n="r.pos" />{{ r.name }}</td>
                            <td style="max-width: 70px; white-space: normal; font-size: 11px; color: var(--text3)">{{ r.chars || '—' }}</td>
                            <td style="max-width: 60px; white-space: normal">
                                <template v-if="workshopsOf(r).length">
                                    <template v-for="(w, wi) in workshopsOf(r)" :key="w"
                                        >{{ wi ? ' ' : '' }}<span class="badge badge-blue" style="font-size: 10px; margin: 1px">{{ workshopLabel(w) }}</span></template
                                    >
                                </template>
                                <span v-else style="color: var(--text3)">—</span>
                            </td>
                            <td style="max-width: 170px; white-space: normal; background: var(--bg3)">
                                <template v-if="r.stages.length">
                                    <template v-for="(s, si) in r.stages" :key="si"
                                        >{{ si ? ' ' : ''
                                        }}<span
                                            class="badge"
                                            :class="s.posted ? 'badge-green' : s.started ? 'badge-amber' : 'badge-blue'"
                                            style="font-size: 11px; font-weight: 600; margin: 1px"
                                            >{{ s.stage || '(без названия)' }}{{ s.posted ? ' ✓' : s.started ? ' ▶' : '' }}</span
                                        ></template
                                    >
                                </template>
                                <span v-else-if="r.completed" class="badge badge-green" style="font-size: 11px">Завершено без этапов</span>
                                <span v-else style="color: var(--text3)">—</span>
                            </td>
                            <td style="max-width: 170px; white-space: normal; background: var(--bg3)">
                                <template v-if="execsOf(r).length">
                                    <template v-for="(n, ni) in execsOf(r)" :key="ni"
                                        >{{ ni ? ' ' : '' }}<span class="badge badge-purple" style="font-size: 11px; font-weight: 600; margin: 1px">{{ n }}</span></template
                                    >
                                </template>
                                <span v-else style="color: var(--text3)">—</span>
                            </td>
                            <td style="max-width: 170px; white-space: normal; background: var(--bg3)">
                                <span v-if="r.materials.length" style="font-weight: 600">📦 {{ r.materials.join(', ') }}</span>
                                <span v-else style="color: var(--text3)">—</span>
                            </td>
                            <td><span style="color: var(--text3)">—</span></td>
                            <td>
                                <span v-if="r.completed || r.stages.some((s) => s.posted)" class="badge badge-green" style="font-size: 10px">Есть проводки</span>
                                <span v-else style="color: var(--text3); font-size: 11px">черновик</span>
                            </td>
                            <td style="display: flex; gap: 4px">
                                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="editLater">✏️</button>
                                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later">✕</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div
            id="pwb-scrollbar-sticky"
            ref="bar"
            style="position: sticky; bottom: 0; overflow-x: auto; overflow-y: hidden; height: 14px; background: var(--bg2); border-top: 1px solid var(--border)"
            @scroll="fromBar"
        >
            <div id="pwb-scrollbar-phantom" :style="{ height: '1px', width: phantomWidth ? phantomWidth + 'px' : undefined }"></div>
        </div>
    </div>
</template>
