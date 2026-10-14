<script setup lang="ts">
// M03 «Склад» — legacy page-warehouse (L2470–2884): renderWhKPIs, showWhTab, renderWhWarehouses, filterWhByPhysical,
// renderWhStocks, filterWhByCat, renderWhWorkshops, renderWhDocs, renderWhCost (L27658–28220). Same markup, texts and
// calculations on the real directory. Stock moves only through warehouse documents, which wait for decisions D-04/D-05:
// until then documents, workshop stock and write-offs are empty, without legacy's built-in demo documents
// (docs/adr/0008-warehouse.md).
import { computed, reactive, ref } from 'vue';
import AliasesTab from '@/components/warehouse/AliasesTab.vue';
import InventoryTab from '@/components/warehouse/InventoryTab.vue';
import StockTable, { type StockFilters } from '@/components/warehouse/StockTable.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import {
    CAT_ICONS,
    DOCUMENTS_LATER,
    fmtCardValue,
    statusColor,
    whStatus,
    type MaterialAliasRow,
    type MaterialRow,
    type PhysicalWarehouse,
    type Workshop,
} from '@/lib/warehouse';

const props = defineProps<{
    materials: (MaterialRow & { value: number })[];
    stockValue: string;
    warehouses: PhysicalWarehouse[];
    workshops: Workshop[];
    aliases: MaterialAliasRow[];
    suppliers: { id: number; name: string }[];
}>();

// Quantities and prices come rounded to 0.01 as legacy loadWarehouseFromDB keeps them; row values are computed by the server.
const items = computed(() => props.materials);

// ── KPI (renderWhKPIs; the stock value is summed by the server) ──
const kpi = computed(() => {
    let critical = 0;
    let low = 0;
    items.value.forEach((m) => {
        const s = whStatus(m);
        if (s === 'critical') critical++;
        else if (s === 'low') low++;
    });
    return { count: items.value.length, critical, low, value: '₸' + (Number(props.stockValue) / 1000000).toFixed(1) + 'М' };
});

// ── Tabs (showWhTab). Heavy tabs are built on the first visit and kept, so filters and counts survive switching. ──
type Tab = 'warehouses' | 'stocks' | 'workshops' | 'docs' | 'remains' | 'inventory' | 'cost' | 'aliases';
const tab = ref<Tab>('warehouses');
const visited = reactive<Record<Tab, boolean>>({ warehouses: true, stocks: false, workshops: false, docs: false, remains: false, inventory: false, cost: false, aliases: false });
const tabs: { id: Tab; label: string }[] = [
    { id: 'warehouses', label: '🏭 По складам' },
    { id: 'stocks', label: '📂 По категориям' },
    { id: 'workshops', label: '🔧 Цехи' },
    { id: 'docs', label: '📄 Документы' },
    { id: 'remains', label: '📦 Все остатки' },
    { id: 'inventory', label: '📋 Инвентаризация' },
    { id: 'cost', label: '💰 Себестоимость' },
    { id: 'aliases', label: '🔗 Алиасы' },
];
function showWhTab(t: Tab): void {
    tab.value = t;
    visited[t] = true;
}

const filters = reactive<StockFilters>({ wh: '', type: 'all', cat: '', search: '', status: 'all' });

// ── По складам (renderWhWarehouses): a card lists the materials of its categories; a click shows the warehouse's stock ──
const groups = computed(() =>
    [
        { type: 'material', label: '🏭 Склады материалов и сырья' },
        { type: 'semi', label: '🔨 Незавершённое производство' },
        { type: 'product', label: '🪑 Склады готовой продукции' },
    ].map((g) => ({
        ...g,
        warehouses: props.warehouses
            .filter((w) => w.type === g.type)
            .map((w) => {
                const list = items.value.filter((m) => w.categories.includes(m.category || 'Прочее'));
                return {
                    w,
                    list,
                    value: list.reduce((s, m) => s + m.value, 0),
                    critical: list.filter((m) => whStatus(m) === 'critical').length,
                    low: list.filter((m) => whStatus(m) === 'low').length,
                };
            }),
    })),
);
function filterWhByPhysical(code: string): void {
    filters.wh = code;
    showWhTab('remains');
}

// ── По категориям (renderWhStocks): categories in the order of the data, the largest first ──
const byCategory = computed(() => {
    const map = new Map<string, typeof items.value>();
    items.value.forEach((m) => {
        const cat = m.category || 'Прочее';
        map.set(cat, [...(map.get(cat) ?? []), m]);
    });
    return [...map.entries()]
        .sort((a, b) => b[1].length - a[1].length)
        .map(([cat, list]) => ({
            cat,
            list,
            value: list.reduce((s, m) => s + m.value, 0),
            critical: list.filter((m) => whStatus(m) === 'critical').length,
            low: list.filter((m) => whStatus(m) === 'low').length,
        }));
});
function filterWhByCat(cat: string): void {
    filters.cat = cat;
    showWhTab('remains');
}

// ── Документы / Цехи / Себестоимость: no warehouse documents and no orders yet ──
const docType = ref('all');
const docWorkshop = ref('all');
const later = () => window.alert(DOCUMENTS_LATER);
const addMaterial = () => window.alert('Добавление материала появится в следующей части переноса раздела «Склад».');
</script>

<template>
    <ErpLayout>
        <div id="page-warehouse" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Склад</div>
                    <div class="section-sub">Многоскладовой учёт · приход · перемещение · списание</div>
                </div>
                <div style="display: flex; gap: 8px">
                    <button class="btn btn-ghost btn-sm" @click="later">📥 Приход</button>
                    <button class="btn btn-ghost btn-sm" @click="later">↔️ Перемещение</button>
                    <button class="btn btn-ghost btn-sm" @click="later">📤 Списание</button>
                    <button class="btn btn-primary btn-sm" @click="addMaterial">+ Материал</button>
                </div>
            </div>

            <!-- KPI -->
            <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Всего позиций</div>
                    <div id="wh-kpi-count" class="kpi-value" style="font-size: 22px">{{ kpi.count }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">🔴 Критично</div>
                    <div id="wh-kpi-critical" class="kpi-value" style="font-size: 22px; color: var(--red)">{{ kpi.critical }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">🟡 Мало</div>
                    <div id="wh-kpi-low" class="kpi-value" style="font-size: 22px; color: var(--amber)">{{ kpi.low }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Стоимость склада</div>
                    <div id="wh-kpi-value" class="kpi-value" style="font-size: 22px">{{ kpi.value }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Документов (месяц)</div>
                    <div id="wh-kpi-moves" class="kpi-value" style="font-size: 22px; color: var(--accent)">0</div>
                </div>
            </div>

            <!-- Вкладки -->
            <div class="tabs" style="margin-bottom: 16px">
                <div v-for="t in tabs" :key="t.id" class="tab" :class="{ active: tab === t.id }" @click="showWhTab(t.id)">{{ t.label }}</div>
            </div>

            <!-- ПО СКЛАДАМ (физические склады) -->
            <div v-show="tab === 'warehouses'" id="wh-tab-warehouses">
                <div id="wh-warehouses-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px">
                    <template v-for="g in groups" :key="g.type">
                        <div style="grid-column: 1/-1; font-size: 13px; font-weight: 700; color: var(--text2); margin: 8px 0 4px">{{ g.label }}</div>
                        <div
                            v-for="c in g.warehouses"
                            :key="c.w.code"
                            class="card"
                            style="padding: 0; overflow: hidden; cursor: pointer"
                            :data-whid="c.w.code"
                            @click="filterWhByPhysical(c.w.code)"
                        >
                            <div style="padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--bg3); display: flex; align-items: center; gap: 10px">
                                <div
                                    style="width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px"
                                    :style="{ background: c.w.color + '22' }"
                                >
                                    {{ c.w.icon }}
                                </div>
                                <div style="flex: 1">
                                    <div style="font-weight: 600; font-size: 13px">{{ c.w.name }}</div>
                                    <div style="font-size: 11px; color: var(--text3)">{{ c.list.length }} позиций</div>
                                </div>
                                <div style="text-align: right">
                                    <div style="font-weight: 600" :style="{ color: c.w.color }">₸{{ fmtCardValue(c.value) }}</div>
                                    <div v-if="c.critical" style="font-size: 11px; color: var(--red)">⚠️ {{ c.critical }}</div>
                                    <div v-else-if="c.low" style="font-size: 11px; color: var(--amber)">↓ {{ c.low }}</div>
                                    <div v-else-if="c.list.length" style="font-size: 11px; color: var(--green)">✓</div>
                                </div>
                            </div>
                            <div style="padding: 8px 14px">
                                <div
                                    v-for="m in c.list.slice(0, 3)"
                                    :key="m.id"
                                    style="display: flex; justify-content: space-between; padding: 3px 0; border-top: 1px solid var(--border); font-size: 11px"
                                >
                                    <span
                                        style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 190px"
                                        :style="{ color: statusColor(whStatus(m), 'var(--text2)') }"
                                        >{{ m.name }}</span
                                    ><span style="font-family: monospace; color: var(--text3); margin-left: 8px; white-space: nowrap">{{ m.qty || 0 }} {{ m.unit || 'шт' }}</span>
                                </div>
                                <div v-if="c.list.length > 3" style="text-align: center; font-size: 11px; color: var(--accent); padding-top: 4px">+ ещё {{ c.list.length - 3 }} →</div>
                                <div v-if="!c.list.length" style="text-align: center; font-size: 12px; color: var(--text3); padding: 8px">Нет позиций</div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ПО КАТЕГОРИЯМ -->
            <div v-if="visited.stocks" v-show="tab === 'stocks'" id="wh-tab-stocks">
                <div id="wh-stocks-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px">
                    <div v-for="c in byCategory" :key="c.cat" class="card" style="padding: 0; overflow: hidden; cursor: pointer" :data-cat="c.cat" @click="filterWhByCat(c.cat)">
                        <div style="padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--bg3); display: flex; justify-content: space-between; align-items: center">
                            <div style="font-size: 20px; margin-right: 8px">{{ CAT_ICONS[c.cat] || '📦' }}</div>
                            <div style="flex: 1">
                                <div style="font-weight: 600; font-size: 13px">{{ c.cat }}</div>
                                <div style="font-size: 11px; color: var(--text3)">{{ c.list.length }} позиций</div>
                            </div>
                            <div style="text-align: right">
                                <div style="font-weight: 600; font-size: 13px">₸{{ fmtCardValue(c.value) }}</div>
                                <div v-if="c.critical" style="font-size: 11px; color: var(--red)">⚠️ {{ c.critical }} крит.</div>
                                <div v-else-if="c.low" style="font-size: 11px; color: var(--amber)">↓ {{ c.low }} мало</div>
                                <div v-else style="font-size: 11px; color: var(--green)">✓ норма</div>
                            </div>
                        </div>
                        <div style="padding: 10px 14px">
                            <div v-for="m in c.list.slice(0, 4)" :key="m.id" style="display: flex; justify-content: space-between; padding: 3px 0; border-bottom: 1px solid var(--border); font-size: 12px">
                                <span
                                    style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px"
                                    :style="{ color: statusColor(whStatus(m), 'var(--text1)') }"
                                    >{{ m.name }}</span
                                >
                                <span style="font-family: 'IBM Plex Mono', monospace; color: var(--text3); white-space: nowrap; margin-left: 8px">{{ m.qty || 0 }} {{ m.unit || 'шт' }}</span>
                            </div>
                            <div v-if="c.list.length > 4" style="text-align: center; font-size: 11px; color: var(--accent); padding-top: 6px">+ ещё {{ c.list.length - 4 }} позиций →</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ЦЕХИ: stock and write-offs of a workshop come from warehouse documents -->
            <div v-show="tab === 'workshops'" id="wh-tab-workshops">
                <div id="wh-workshops-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px">
                    <div v-for="ws in props.workshops" :key="ws.id" class="card" style="padding: 0; overflow: hidden">
                        <div style="padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--bg3); display: flex; align-items: center; gap: 10px">
                            <div style="font-size: 24px">{{ ws.icon }}</div>
                            <div style="flex: 1">
                                <div style="font-weight: 600; font-size: 13px">{{ ws.name }}</div>
                                <div style="font-size: 11px; color: var(--text3)">0 позиций в цехе</div>
                            </div>
                        </div>
                        <div style="padding: 12px 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 12px">
                            <div>
                                <div style="color: var(--text3); margin-bottom: 2px">Остаток в цехе</div>
                                <div style="font-weight: 600">₸0</div>
                            </div>
                            <div>
                                <div style="color: var(--text3); margin-bottom: 2px">Списано (месяц)</div>
                                <div style="font-weight: 600; color: var(--red)">₸0</div>
                            </div>
                            <div>
                                <div style="color: var(--text3); margin-bottom: 2px">Документов (месяц)</div>
                                <div style="font-weight: 600; color: var(--accent)">0</div>
                            </div>
                            <div>
                                <div style="color: var(--text3); margin-bottom: 2px">Перемещений</div>
                                <div style="font-weight: 600">0</div>
                            </div>
                        </div>
                        <div style="padding: 8px 14px; border-top: 1px solid var(--border); display: flex; gap: 6px">
                            <button class="btn btn-ghost btn-sm" style="flex: 1; font-size: 11px" @click="later">📤 Списание</button>
                            <button class="btn btn-ghost btn-sm" style="flex: 1; font-size: 11px" @click="later">📥 Принять</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ДОКУМЕНТЫ -->
            <div v-show="tab === 'docs'" id="wh-tab-docs">
                <div style="display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap">
                    <select id="wh-doc-type-filter" v-model="docType" style="font-size: 13px; width: auto">
                        <option value="all">Все типы</option>
                        <option value="receipt">📥 Приход</option>
                        <option value="transfer">↔️ Перемещение</option>
                        <option value="writeoff">📤 Списание</option>
                    </select>
                    <select id="wh-doc-ws-filter" v-model="docWorkshop" style="font-size: 13px; width: auto">
                        <option value="all">Все цехи</option>
                        <option v-for="ws in props.workshops" :key="ws.id" :value="ws.id">{{ ws.icon }} {{ ws.name }}</option>
                    </select>
                    <div style="flex: 1"></div>
                    <button class="btn btn-ghost btn-sm" @click="later">📥 Приход</button>
                    <button class="btn btn-ghost btn-sm" @click="later">↔️ Перемещение</button>
                    <button class="btn btn-ghost btn-sm" @click="later">📤 Списание / ОПР</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Номер</th>
                            <th>Тип</th>
                            <th>Поставщик</th>
                            <th>Откуда / Куда</th>
                            <th>Позиций</th>
                            <th style="text-align: right">Сумма</th>
                            <th>Основание</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="wh-docs-tbody">
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 20px; color: var(--text3)">Складских документов пока нет</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ВСЕ ОСТАТКИ -->
            <div v-if="visited.remains" v-show="tab === 'remains'" id="wh-tab-remains">
                <StockTable v-model:filters="filters" :materials="items" :warehouses="props.warehouses" />
            </div>

            <!-- ИНВЕНТАРИЗАЦИЯ -->
            <div v-if="visited.inventory" v-show="tab === 'inventory'" id="wh-tab-inventory">
                <InventoryTab :materials="items" :warehouses="props.warehouses" />
            </div>

            <!-- СЕБЕСТОИМОСТЬ: orders and write-off documents are not migrated yet, so there is no order to choose -->
            <div v-show="tab === 'cost'" id="wh-tab-cost">
                <div style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center">
                    <select id="wh-cost-order" style="font-size: 13px; width: auto">
                        <option value="">— выберите заказ —</option>
                    </select>
                    <span style="font-size: 12px; color: var(--text3)">Себестоимость рассчитывается по документам списания</span>
                </div>
                <div id="wh-cost-body"><div style="color: var(--text3); padding: 20px">Выберите заказ для расчёта себестоимости</div></div>
            </div>

            <!-- АЛИАСЫ -->
            <div v-if="visited.aliases" v-show="tab === 'aliases'" id="wh-tab-aliases">
                <AliasesTab :aliases="props.aliases" :materials="items" :suppliers="props.suppliers" />
            </div>
        </div>
    </ErpLayout>
</template>
