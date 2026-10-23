<script setup lang="ts">
// M02 «Производство», part 1 — legacy page-mrp (L2233–2300), tab «Производственные заказы»: showMrpTab/showMrpOrdersFilter
// (L19073, L33439), renderMrpOrdersJournal (L36074). Every order line with its effective status (counted by the server, as
// legacy mrpEffectiveStatus), the stages recorded on the worksheet, filters and sorting. The month filter offers the months
// (legacy wrote them into the page as text, so the list held only «Все месяцы»). Tabs «Загрузка цехов» and «Журнал работ» read
// the recorded stages (WorkloadTab, WorklogTab); «Рабочий лист производства» shows the lines on it (ProdWorksheetTab). View
// only: the shop floor, editing the worksheet, technological cards and the Excel tasks come with ERP-041…044 (decisions D-05,
// D-06, D-12).
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import PosBadge from '@/components/PosBadge.vue';
import ProdWorksheetTab, { type PwbData } from '@/components/mrp/ProdWorksheetTab.vue';
import type { WorkData } from '@/components/mrp/work';
import WorkloadTab from '@/components/mrp/WorkloadTab.vue';
import WorklogTab from '@/components/mrp/WorklogTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { ORDER_FORM_LATER, PL_MONTH_LABELS, PROGRESS_CLASS, STATUS_BADGE, STATUS_LABELS } from '@/lib/sales';

interface Position {
    id: number;
    num: string;
    pos: number;
    name: string;
    category: string | null;
    qty: number;
    progress: number;
    status: string;
    resp: string | null;
    date: string | null;
    stages: string[] | null;
    completed: boolean;
}
const props = defineProps<{ positions: Position[]; operations: string[]; today: string; work?: WorkData; worksheet?: PwbData }>();

type Tab = 'orders' | 'shopfloor' | 'worksheet' | 'techcards' | 'workload' | 'worklog';
const tab = ref<Tab>('orders');
// «Загрузка цехов» and «Журнал работ» read the recorded stages, loaded with the first of them (legacy prodProgress).
watch(tab, (t) => {
    if ((t === 'workload' || t === 'worklog') && props.work === undefined) router.reload({ only: ['work'] });
});
const tabs: { id: Tab; label: string }[] = [
    { id: 'orders', label: 'Производственные заказы' },
    { id: 'shopfloor', label: '🏭 Производство' },
    { id: 'worksheet', label: '📋 Рабочий лист производства' },
    { id: 'techcards', label: '📐 Технологические карты' },
    { id: 'workload', label: '📊 Загрузка цехов' },
    { id: 'worklog', label: '📅 Журнал работ' },
];
const LATER: Partial<Record<Tab, string>> = {
    shopfloor: 'Производственная доска цехов появится вместе с проведением этапов (ERP-042…044, решения D-06 и D-08).',
    techcards: 'Технологические карты переносятся вместе с нормами и тарифами этапов (ERP-028, решение D-12).',
};
const later = (text: string) => () => window.alert(text);
const exportTasks = later('Выгрузка заданий в Excel появится вместе с остальными выгрузками (ERP-067).');
const importFact = later('Загрузка факта выполнения из Excel появится вместе с рабочим листом производства (ERP-041) и выгрузками (ERP-067).');
const openOrder = later(ORDER_FORM_LATER);
const openShop = later(LATER.shopfloor!);

// ── Filters (legacy mrp-* controls) ──
const filter = ref<'all' | 'queue' | 'progress' | 'done'>('all');
const iso = (d: string | null) => (d ? d.split('.').reverse().join('-') : '');
const years = computed(() => [...new Set(props.positions.map((p) => iso(p.date).slice(0, 4)).filter((y) => y.length === 4))].sort().reverse());
const categories = computed(() => [...new Set(props.positions.map((p) => p.category).filter((c): c is string => !!c))].sort());
const resps = computed(() => [...new Set(props.positions.map((p) => p.resp).filter((r): r is string => !!r))].sort());
const currentYear = String(new Date().getFullYear());
// Legacy chooses the current year when lines have it.
const f = reactive({ search: '', category: '', stage: '', resp: '', month: '', year: years.value.includes(currentYear) ? currentYear : '', sort: 'num', dir: 'desc' });

const counts = computed(() => {
    const c: Record<string, number> = { all: props.positions.length, queue: 0, progress: 0, done: 0 };
    props.positions.forEach((p) => {
        if (c[p.status] !== undefined) c[p.status]++;
    });
    return c;
});
const rows = computed(() => {
    const search = f.search.toLowerCase();
    const list = props.positions.filter((p) => {
        if (filter.value !== 'all' && p.status !== filter.value) return false;
        if (search && !p.name.toLowerCase().includes(search) && !p.num.toLowerCase().includes(search)) return false;
        if (f.category && p.category !== f.category) return false;
        if (f.resp && p.resp !== f.resp) return false;
        if (f.stage && !(p.stages ?? []).includes(f.stage)) return false;
        if (f.month || f.year) {
            const d = iso(p.date);
            if (!d) return false;
            if (f.year && d.slice(0, 4) !== f.year) return false;
            if (f.month && +d.slice(5, 7) !== +f.month) return false;
        }
        return true;
    });
    const mul = f.dir === 'asc' ? -1 : 1;
    return f.sort === 'deadline'
        ? [...list].sort((a, b) => mul * iso(b.date).localeCompare(iso(a.date)))
        : [...list].sort((a, b) => mul * b.num.localeCompare(a.num, undefined, { numeric: true }));
});
const stageLabel = (p: Position) => (p.stages && p.stages.length ? p.stages.join(', ') : p.completed ? 'Завершено без этапов' : 'Нет данных');
</script>

<template>
    <ErpLayout>
        <div id="page-mrp" class="page active">
            <div class="section-header">
                <div><div class="section-title">Производство</div><div class="section-sub">MRP — управление производством</div></div>
                <div style="display: flex; gap: 8px; align-items: center">
                    <button class="btn btn-ghost btn-sm" title="Выгрузить задания на день в Excel" @click="exportTasks">📥 Задания (Excel)</button>
                    <label class="btn btn-ghost btn-sm" style="cursor: pointer; margin: 0" title="Загрузить факт выполнения из Excel" @click.prevent="importFact"> 📤 Загрузить факт </label>
                </div>
            </div>
            <div class="tabs">
                <div v-for="t in tabs" :key="t.id" class="tab" :class="{ active: tab === t.id }" @click="tab = t.id">{{ t.label }}</div>
            </div>

            <!-- TAB: real production summary journal (built from ordersData) -->
            <div id="mrp-orders" class="mrp-tab active" :style="{ display: tab === 'orders' ? 'block' : 'none' }">
                <div class="tabs">
                    <div class="tab" :class="{ active: filter === 'all' }" @click="filter = 'all'">Все позиции (<span id="mrp-count-all">{{ counts.all }}</span>)</div>
                    <div class="tab" :class="{ active: filter === 'queue' }" @click="filter = 'queue'">В очереди (<span id="mrp-count-queue">{{ counts.queue }}</span>)</div>
                    <div class="tab" :class="{ active: filter === 'progress' }" @click="filter = 'progress'">В работе (<span id="mrp-count-progress">{{ counts.progress }}</span>)</div>
                    <div class="tab" :class="{ active: filter === 'done' }" @click="filter = 'done'">Готово (<span id="mrp-count-done">{{ counts.done }}</span>)</div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Все товарные позиции в производстве</span>
                        <input id="mrp-orders-search" v-model="f.search" type="text" placeholder="Поиск по товару или номеру заказа..." style="width: 220px; font-size: 13px" />
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
                        <select id="mrp-f-activity" v-model="f.category" style="font-size: 12px; width: auto">
                            <option value="">Все виды деятельности</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <select id="mrp-f-stage" v-model="f.stage" style="font-size: 12px; width: auto">
                            <option value="">Все этапы</option>
                            <option v-for="s in props.operations" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <select id="mrp-f-resp" v-model="f.resp" style="font-size: 12px; width: auto">
                            <option value="">Все ответственные</option>
                            <option v-for="r in resps" :key="r" :value="r">{{ r }}</option>
                        </select>
                        <span style="font-size: 11px; color: var(--text3)">Срок:</span>
                        <select id="mrp-f-month" v-model="f.month" style="font-size: 12px; width: auto">
                            <option value="">Все месяцы</option>
                            <option v-for="(m, i) in PL_MONTH_LABELS" :key="m" :value="String(i + 1)">{{ m }}</option>
                        </select>
                        <select id="mrp-f-year" v-model="f.year" style="font-size: 12px; width: auto">
                            <option value="">Все годы</option>
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                        <span style="font-size: 11px; color: var(--text3)">Сортировка:</span>
                        <select id="mrp-sort" v-model="f.sort" style="font-size: 12px; width: auto">
                            <option value="num">По номеру</option>
                            <option value="deadline">По сроку</option>
                        </select>
                        <select id="mrp-sort-dir" v-model="f.dir" style="font-size: 12px; width: auto">
                            <option value="desc">Сначала новые</option>
                            <option value="asc">Сначала старые</option>
                        </select>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Номер</th>
                                <th>Изделие</th>
                                <th>Вид деятельности</th>
                                <th>Кол-во</th>
                                <th>Текущий этап</th>
                                <th>Прогресс</th>
                                <th>Срок</th>
                                <th>Статус</th>
                                <th>Отв.</th>
                            </tr>
                        </thead>
                        <tbody id="mrp-orders-tbody">
                            <tr v-if="!rows.length">
                                <td colspan="9" style="text-align: center; color: var(--text3); padding: 24px; font-size: 13px">Нет позиций, соответствующих фильтру.</td>
                            </tr>
                            <tr
                                v-for="p in rows"
                                :key="p.id"
                                v-memo="[p]"
                                style="cursor: pointer"
                                title="Клик по названию — состав затрат · Клик по строке — редактировать"
                                @click="openOrder"
                            >
                                <td style="font-family: 'IBM Plex Mono', monospace; color: var(--accent)">{{ p.num }}</td>
                                <td>
                                    <span style="color: var(--accent); cursor: pointer; border-bottom: 1px dashed var(--accent)" title="Открыть заказ" @click.stop="openOrder"><PosBadge :n="p.pos" />{{ p.name }}</span>
                                </td>
                                <td>
                                    <span v-if="p.category" class="badge badge-purple" style="cursor: pointer" :title="`Открыть в цехе «${p.category}»`" @click.stop="openShop">{{ p.category }}</span>
                                    <span v-else style="color: var(--text3)">—</span>
                                </td>
                                <td style="font-family: 'IBM Plex Mono', monospace">{{ p.qty }} шт</td>
                                <td style="font-size: 12px; color: var(--text3)">{{ stageLabel(p) }}</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px">
                                        <div class="progress" style="flex: 1"><div class="progress-fill" :class="PROGRESS_CLASS[p.status]" :style="{ width: p.progress + '%' }"></div></div>
                                        <span style="font-size: 11px; font-family: 'IBM Plex Mono', monospace; color: var(--text3)">{{ p.progress }}%</span>
                                    </div>
                                </td>
                                <td style="font-size: 12px">{{ p.date }}</td>
                                <td><span class="badge" :class="STATUS_BADGE[p.status]">{{ STATUS_LABELS[p.status] }}</span></td>
                                <td style="font-size: 12px">{{ p.resp }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="mrp-worksheet" class="mrp-tab" :style="{ display: tab === 'worksheet' ? 'block' : 'none' }">
                <ProdWorksheetTab :data="props.worksheet" :active="tab === 'worksheet'" :operations="props.operations" />
            </div>
            <div id="mrp-workload" class="mrp-tab" :style="{ display: tab === 'workload' ? 'block' : 'none' }">
                <WorkloadTab :data="props.work" :today="props.today" />
            </div>
            <div id="mrp-worklog" class="mrp-tab" :style="{ display: tab === 'worklog' ? 'block' : 'none' }">
                <WorklogTab :data="props.work" :today="props.today" />
            </div>
            <div v-for="(text, key) in LATER" :id="'mrp-' + key" :key="key" class="mrp-tab" :style="{ display: tab === key ? 'block' : 'none' }">
                <div class="card">
                    <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
