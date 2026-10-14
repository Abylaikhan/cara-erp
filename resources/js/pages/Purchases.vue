<script setup lang="ts">
// M04 «Закупки» — legacy page-purchases (L2884–2993), newMaterialRequestModal (L3011): initPurchasesExtra, showPurchExtraTab,
// setMreqView, renderMaterialRequests, renderMaterialRequestsKanban, updatePurchKpis, saveMaterialRequestManual,
// updateMaterialRequestStatus, renderPlanFact, renderNormsGrid (L34416–34640). Same markup and texts; requests and their
// steps are saved by the server, «План-факт» is counted there. Goods receipts and consumption norms are empty in legacy and
// come with stock documents (ERP-046, D-04) and technological cards (ERP-028).
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

interface RequestRow {
    id: number;
    date: string | null;
    material_name: string;
    qty: number;
    unit: string | null;
    order_num: string | null;
    item_name: string | null;
    workshop: string | null;
    requested_by: string | null;
    status: string;
    lock_version: number;
}
interface PlanFactRow {
    name: string;
    unit: string;
    need: number;
    bought: number;
    stock: number;
    to_buy: number;
}

const props = defineProps<{ requests: RequestRow[]; planFact: PlanFactRow[]; workshops: Record<string, string> }>();

const STATUS_LABELS: Record<string, string> = { requested: 'Заявлено', in_purchase: 'Взято в закупку', received: 'Пришло на склад' };
const STATUS_BADGE: Record<string, string> = { requested: 'badge-amber', in_purchase: 'badge-blue', received: 'badge-green' };
const NEXT: Record<string, string> = { requested: 'in_purchase', in_purchase: 'received' };

type Tab = 'requests' | 'receipts' | 'planfact' | 'norms';
const tab = ref<Tab>('requests');
const tabs: { id: Tab; label: string }[] = [
    { id: 'requests', label: '🔄 Заявки и закупки' },
    { id: 'receipts', label: '📥 Поступления' },
    { id: 'planfact', label: '📊 План-факт' },
    { id: 'norms', label: '📐 Нормы расхода' },
];
const view = ref<'table' | 'kanban'>('table');
const statusFilter = ref('');
const search = ref('');

const counts = computed(() => {
    const byStatus: Record<string, number> = { requested: 0, in_purchase: 0, received: 0 };
    props.requests.forEach((r) => {
        if (byStatus[r.status] !== undefined) byStatus[r.status]++;
    });
    return byStatus;
});
const shown = computed(() => {
    const q = search.value.toLowerCase();
    return props.requests.filter((r) => (!statusFilter.value || r.status === statusFilter.value) && (!q || (r.material_name || '').toLowerCase().includes(q)));
});
const column = (status: string) => props.requests.filter((r) => r.status === status);
const workshopName = (r: RequestRow) => (r.workshop ? props.workshops[r.workshop] || r.workshop : '—');

const reload = ['requests', 'planFact', 'flash'];
function updateMaterialRequestStatus(r: RequestRow, status: string): void {
    router.put(`/purchases/requests/${r.id}/status`, { status, lock_version: r.lock_version }, {
        preserveScroll: true,
        preserveState: true,
        only: reload,
        onError: (errors: Record<string, string>) => window.alert('Не удалось изменить статус: ' + (Object.values(errors)[0] ?? '')),
    });
}

// ── newMaterialRequestModal ──
const modal = reactive({ open: false, name: '', qty: '' as string | number, unit: '', comment: '', busy: false });
function openNewMaterialRequestModal(): void {
    Object.assign(modal, { open: true, name: '', qty: '', unit: '', comment: '' });
}
function saveMaterialRequestManual(): void {
    if (!modal.name.trim()) return void window.alert('Укажите материал.');
    router.post('/purchases/requests', { material_name: modal.name, qty: modal.qty, unit: modal.unit, item_name: modal.comment }, {
        preserveScroll: true,
        preserveState: true,
        only: reload,
        onStart: () => (modal.busy = true),
        onFinish: () => (modal.busy = false),
        onSuccess: () => (modal.open = false),
        onError: (errors: Record<string, string>) => window.alert('Не удалось создать заявку: ' + (Object.values(errors)[0] ?? '')),
    });
}

// ── План-факт ──
const deficitOnly = ref(false);
const planFact = computed(() => props.planFact.filter((m) => !deficitOnly.value || m.to_buy > 0));
const num = (n: number) => n.toLocaleString('ru-RU');

const receiptsLater = () => window.alert('Накладные поступления появятся вместе со складскими документами (ERP-046, решение D-04).');
</script>

<template>
    <ErpLayout>
        <div id="page-purchases" class="page active">
            <div class="section-header">
                <div><div class="section-title">Закупки</div><div class="section-sub">Заявки мастеров, закупка, поступление материалов</div></div>
            </div>
            <!-- Дашборд закупок — реальные цифры из заявок -->
            <div id="purch-kpi-row" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">🟡 Заявлено</div>
                    <div id="pk-requested" style="font-size: 22px; font-weight: 700; color: var(--amber)">{{ counts.requested }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">🔵 Взято в закупку</div>
                    <div id="pk-inpurchase" style="font-size: 22px; font-weight: 700; color: var(--accent)">{{ counts.in_purchase }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">✅ Пришло на склад</div>
                    <div id="pk-received" style="font-size: 22px; font-weight: 700; color: var(--green)">{{ counts.received }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Сумма по накладным (всего)</div>
                    <div id="pk-sum" style="font-size: 18px; font-weight: 700; color: var(--text1)">₸0</div>
                </div>
            </div>
            <div class="tabs" style="margin-bottom: 16px">
                <div v-for="t in tabs" :key="t.id" class="tab" :class="{ active: tab === t.id }" @click="tab = t.id">{{ t.label }}</div>
            </div>

            <!-- TAB: Заявки и закупки (таблица + канбан одних и тех же данных) -->
            <div v-show="tab === 'requests'" id="purch-extra-requests">
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
                    <div class="tabs" style="margin: 0">
                        <div id="mreq-view-table-btn" class="tab" :class="{ active: view === 'table' }" style="padding: 6px 14px; font-size: 12px" @click="view = 'table'">📋 Таблица</div>
                        <div id="mreq-view-kanban-btn" class="tab" :class="{ active: view === 'kanban' }" style="padding: 6px 14px; font-size: 12px" @click="view = 'kanban'">🔄 Канбан</div>
                    </div>
                    <select id="mreq-f-status" v-model="statusFilter" style="font-size: 12px; width: auto">
                        <option value="">Все статусы</option>
                        <option value="requested">Заявлено</option>
                        <option value="in_purchase">Взято в закупку</option>
                        <option value="received">Пришло на склад</option>
                    </select>
                    <input id="mreq-f-search" v-model="search" type="search" placeholder="🔍 Материал..." style="width: 180px; font-size: 12px" />
                    <button class="btn btn-ghost btn-sm" @click="openNewMaterialRequestModal">+ Заявка вручную</button>
                </div>
                <table v-show="view === 'table'" id="mreq-table-view" class="data-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Материал</th>
                            <th>Кол-во</th>
                            <th>Заказ / изделие</th>
                            <th>Цех</th>
                            <th>Заявил</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mreq-tbody">
                        <tr v-if="!shown.length">
                            <td colspan="8" style="text-align: center; color: var(--text3); padding: 20px">Заявок нет</td>
                        </tr>
                        <tr v-for="r in shown" :key="r.id">
                            <td style="font-size: 12px; color: var(--text3)">{{ r.date || '' }}</td>
                            <td style="font-weight: 500">{{ r.material_name }}</td>
                            <td style="font-family: monospace">{{ r.qty || 0 }} {{ r.unit || '' }}</td>
                            <td style="font-size: 12px">{{ r.order_num ? r.order_num + ' / ' : '' }}{{ r.item_name || '—' }}</td>
                            <td style="font-size: 12px">{{ workshopName(r) }}</td>
                            <td style="font-size: 12px">{{ r.requested_by || '—' }}</td>
                            <td>
                                <span class="badge" :class="STATUS_BADGE[r.status]" style="font-size: 10px">{{ STATUS_LABELS[r.status] || r.status }}</span>
                            </td>
                            <td>
                                <button v-if="NEXT[r.status]" class="btn btn-ghost btn-sm" style="font-size: 11px" @click="updateMaterialRequestStatus(r, NEXT[r.status])">
                                    {{ r.status === 'requested' ? 'Взять в закупку' : 'Отметить пришедшим' }} →
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div id="mreq-kanban-view" style="grid-template-columns: repeat(3, 1fr); gap: 14px" :style="{ display: view === 'kanban' ? 'grid' : 'none' }">
                    <div
                        v-for="col in [
                            { status: 'requested', title: '🟡 Заявлено', style: 'background:rgba(245,166,35,.1);color:var(--amber)', next: 'Взять в закупку' },
                            { status: 'in_purchase', title: '🔵 Взято в закупку', style: 'background:rgba(79,142,247,.1);color:var(--accent)', next: 'Пришло на склад' },
                            { status: 'received', title: '✅ Пришло на склад', style: 'background:rgba(61,214,140,.1);color:var(--green)', next: '' },
                        ]"
                        :key="col.status"
                        class="card"
                        style="padding: 0; overflow: hidden"
                    >
                        <div style="padding: 10px 14px; font-weight: 600; font-size: 13px; border-bottom: 1px solid var(--border)" :style="col.style">
                            {{ col.title }} <span :id="'mk-count-' + col.status">{{ column(col.status).length }}</span>
                        </div>
                        <div :id="'mk-col-' + col.status" style="padding: 10px; min-height: 160px; display: flex; flex-direction: column; gap: 8px">
                            <div v-if="!column(col.status).length" style="text-align: center; color: var(--text3); font-size: 12px; padding: 16px">Пусто</div>
                            <div v-for="r in column(col.status)" :key="r.id" class="card" style="padding: 10px 12px">
                                <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px">{{ r.material_name }}</div>
                                <div style="font-size: 11px; color: var(--text3); margin-bottom: 6px">{{ r.qty || 0 }} {{ r.unit || '' }} {{ r.order_num ? '· ' + r.order_num : '' }}</div>
                                <button v-if="col.next" class="btn btn-ghost btn-sm" style="width: 100%; font-size: 11px" @click="updateMaterialRequestStatus(r, NEXT[r.status])">{{ col.next }} →</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB: Поступления (бывший "Журнал поступления" + "Все закупки") -->
            <div v-show="tab === 'receipts'" id="purch-extra-receipts">
                <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px">
                    <button class="btn btn-primary btn-sm" @click="receiptsLater">+ Новая накладная</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Поставщик</th>
                            <th>Позиций</th>
                            <th>Сумма</th>
                            <th>ЭСФ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="gr-tbody">
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text3); padding: 20px">Накладных пока нет</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- TAB: План-факт (включает "Дефицит" как фильтр, а не отдельную вкладку) -->
            <div v-show="tab === 'planfact'" id="purch-extra-planfact">
                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 14px">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer"> <input id="pf-deficit-only" v-model="deficitOnly" type="checkbox" /> Показать только дефицит </label>
                    <div style="flex: 1"></div>
                    <button class="btn btn-ghost btn-sm" @click="router.reload({ only: ['planFact', 'requests'] })">🔄 Пересчитать</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Материал</th>
                            <th>Ед.</th>
                            <th style="text-align: right">Нужно (открытые заявки)</th>
                            <th style="text-align: right">Куплено (по накладным)</th>
                            <th style="text-align: right">Остаток на складе</th>
                            <th style="text-align: right; color: var(--red)">К покупке</th>
                            <th>Срок</th>
                        </tr>
                    </thead>
                    <tbody id="pf-tbody">
                        <tr v-if="!planFact.length">
                            <td colspan="7" style="text-align: center; color: var(--text3); padding: 20px">{{ deficitOnly ? 'Дефицита нет' : 'Нет данных' }}</td>
                        </tr>
                        <tr v-for="m in planFact" :key="m.name">
                            <td style="font-weight: 500">{{ m.name }}</td>
                            <td style="font-size: 12px; color: var(--text3)">{{ m.unit }}</td>
                            <td style="text-align: right; font-family: monospace">{{ num(m.need) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ num(m.bought) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ num(m.stock) }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 700" :style="{ color: m.to_buy > 0 ? 'var(--red)' : 'var(--green)' }">{{ m.to_buy > 0 ? num(m.to_buy) : '✅' }}</td>
                            <td style="font-size: 12px; color: var(--text3)">—</td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top: 14px; padding: 12px; background: var(--bg3); border-radius: 8px; font-size: 12px; color: var(--text3)">
                    💡 «Нужно» считается по заявкам, которые ещё не пришли на склад (статусы «Заявлено» и «Взято в закупку»). «К покупке» = Нужно − Остаток на складе, не ниже нуля.
                </div>
            </div>

            <!-- TAB: Нормы расхода — привязаны к Технологическим картам -->
            <div v-show="tab === 'norms'" id="purch-extra-norms">
                <div class="section-sub" style="margin-bottom: 14px">
                    Сколько материала уходит на одну единицу — привязывается к технологической карте (по изделию или по категории), раздел Производство → Технологические карты
                </div>
                <div id="norms-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px">
                    <div style="grid-column: 1/-1; text-align: center; padding: 30px; color: var(--text3)">
                        Технологических карт пока нет — заведите их в разделе Производство → Технологические карты, затем здесь можно будет задать нормы расхода для каждой.
                    </div>
                </div>
            </div>
        </div>

        <ErpModal id="newMaterialRequestModal" :open="modal.open" max-width="420px" @close="modal.open = false">
            <div class="modal-header">
                <span class="modal-title">Заявка на материал</span>
                <button class="modal-close" @click="modal.open = false">✕</button>
            </div>
            <div class="form-row"><label class="label">Материал</label><input id="mreq-name" v-model="modal.name" type="text" /></div>
            <div class="form-row"><label class="label">Количество</label><input id="mreq-qty" v-model="modal.qty" type="number" min="0" step="0.01" /></div>
            <div class="form-row"><label class="label">Ед. изм.</label><input id="mreq-unit" v-model="modal.unit" type="text" placeholder="шт / м² / кг" /></div>
            <div class="form-row"><label class="label">Комментарий (для чего)</label><input id="mreq-comment" v-model="modal.comment" type="text" /></div>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
                <button class="btn btn-ghost" @click="modal.open = false">Отмена</button>
                <button class="btn btn-primary" :disabled="modal.busy" @click="saveMaterialRequestManual">Создать заявку</button>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
