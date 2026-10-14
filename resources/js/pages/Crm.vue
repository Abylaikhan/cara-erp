<script setup lang="ts">
// M10 CRM — legacy page-crm (L4134–4373), showOrdersTab (L30753), crmConvertModal (L4869). Tabs «Лиды», «Воронка лидов» and
// «Задачи» are migrated (LeadsTab, FunnelTab, TasksTab); «Клиенты» and the order boards need orders (M09). As in legacy, no
// tab content is shown until a tab is chosen, and each tab is built when first opened. The lead form of the funnel and the
// task form are opened from several tabs, so they belong to the page.
import { usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import FunnelLeadModal from '@/components/crm/FunnelLeadModal.vue';
import FunnelTab from '@/components/crm/FunnelTab.vue';
import LeadsTab from '@/components/crm/LeadsTab.vue';
import TaskModal from '@/components/crm/TaskModal.vue';
import TasksTab from '@/components/crm/TasksTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { convert, ORDERS_LATER, type FunnelStage, type LeadRow, type LeadStatus, type Segment, type TaskRow } from '@/lib/crm';
import type { SharedProps } from '@/types';

const props = defineProps<{
    leads: LeadRow[];
    statuses: Record<string, LeadStatus>;
    sources: Record<string, string>;
    segments: Segment[];
    funnelStages: FunnelStage[];
    stageOrder: string[];
    tasks: TaskRow[];
    managers: string[];
    currentYear: number;
    timezone: string;
}>();

type Tab = 'clients' | 'leads' | 'funnel' | 'orders-kanban-prod' | 'orders-kanban-pay' | 'tasks';
// «Клиенты» is marked active from the start while nothing is shown (legacy).
const active = ref<Tab>('clients');
const shown = ref<Tab | null>(null);
const visited = reactive(new Set<Tab>());
// .orders-tab is hidden by the legacy stylesheet; the chosen one gets display:block, as legacy does.
const display = (tab: Tab) => ({ display: shown.value === tab ? 'block' : 'none' });
function showOrdersTab(tab: Tab): void {
    active.value = tab;
    shown.value = tab;
    visited.add(tab);
}

const activeLeads = computed(() => props.leads.filter((l) => l.status !== 'won' && l.status !== 'lost').length);
const openTasks = computed(() => props.tasks.filter((t) => t.status === 'open').length);
const LATER: Partial<Record<Tab, string>> = {
    clients: 'Клиенты салонов считаются по заказам покупателей: вкладка появится после переноса раздела «Продажи».',
    'orders-kanban-prod': 'Доска заказов по производству появится после переноса раздела «Продажи».',
    'orders-kanban-pay': 'Доска заказов по оплате появится после переноса раздела «Продажи».',
};

const leadModal = ref<InstanceType<typeof FunnelLeadModal> | null>(null);
const taskModal = ref<InstanceType<typeof TaskModal> | null>(null);
const openLead = (lead: LeadRow | null) => leadModal.value?.openCrmLeadModal(lead);
const newTask = (leadId: number | null = null) => taskModal.value?.openTaskModal(null, leadId);
const editTask = (task: TaskRow) => taskModal.value?.openTaskModal(task);

const page = usePage<SharedProps>();
watch(
    () => page.props.flash.dialog,
    (d) => {
        if (d && d.type === 'alert') window.alert(d.text);
    },
);

const orders = () => window.alert(ORDERS_LATER);
function convertLeadToOrder(): void {
    convert.open = false;
    orders();
}
</script>

<template>
    <ErpLayout>
        <div id="page-crm" class="page active">
            <div class="section-header">
                <div><div class="section-title">CRM</div><div class="section-sub">Клиенты · Лиды · Воронки · Задачи</div></div>
                <div style="display: flex; gap: 8px">
                    <button class="btn btn-primary btn-sm" @click="orders">+ Новый заказ покупателя</button>
                </div>
            </div>
            <div class="tabs" style="align-items: center">
                <div id="crm-tab-clients" class="tab" :class="{ active: active === 'clients' }" @click="showOrdersTab('clients')">👥 Клиенты (<span id="tab-count-clients">—</span>)</div>
                <div id="crm-tab-leads" class="tab" :class="{ active: active === 'leads' }" @click="showOrdersTab('leads')">
                    🎯 Лиды (<span id="tab-count-leads">{{ activeLeads }}</span>)
                </div>
                <div class="tab" :class="{ active: active === 'funnel' }" @click="showOrdersTab('funnel')">🎯 Воронка лидов</div>
                <div class="tab" :class="{ active: active === 'orders-kanban-prod' }" @click="showOrdersTab('orders-kanban-prod')">🏭 По производству</div>
                <div class="tab" :class="{ active: active === 'orders-kanban-pay' }" @click="showOrdersTab('orders-kanban-pay')">💳 По оплате</div>
                <div class="tab" :class="{ active: active === 'tasks' }" @click="showOrdersTab('tasks')">✅ Задачи (<span id="tasks-open-count">{{ openTasks }}</span>)</div>
            </div>

            <div v-if="visited.has('leads')" id="orders-leads" class="orders-tab" :style="display('leads')">
                <LeadsTab :leads="props.leads" :statuses="props.statuses" :sources="props.sources" :current-year="props.currentYear" />
            </div>
            <div v-if="visited.has('funnel')" id="orders-funnel" class="orders-tab" :style="display('funnel')">
                <FunnelTab
                    :leads="props.leads"
                    :stages="props.funnelStages"
                    :segments="props.segments"
                    :sources="props.sources"
                    :stage-order="props.stageOrder"
                    :timezone="props.timezone"
                    :active="shown === 'funnel'"
                    @open-lead="openLead"
                    @new-task="newTask"
                />
            </div>
            <div v-if="visited.has('tasks')" id="orders-tasks" class="orders-tab" :style="display('tasks')">
                <TasksTab :tasks="props.tasks" :leads="props.leads" :timezone="props.timezone" @new-task="newTask()" @edit-task="editTask" @open-lead="openLead" />
            </div>
            <template v-for="(text, key) in LATER" :key="key">
                <div v-if="visited.has(key)" :id="'orders-' + key" class="orders-tab" :style="display(key)">
                    <div class="card">
                        <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                    </div>
                </div>
            </template>
        </div>

        <FunnelLeadModal ref="leadModal" :segments="props.segments" :sources="props.sources" :managers="props.managers" />
        <TaskModal ref="taskModal" :leads="props.leads" :managers="props.managers" :timezone="props.timezone" />
        <ErpModal id="crmConvertModal" :open="convert.open" max-width="440px" overlay-class="modal-overlay-elevated" @close="convert.open = false">
            <div class="modal-header">
                <div class="modal-title">🎉 Сделка закрыта!</div>
                <button class="modal-close" @click="convert.open = false">✕</button>
            </div>
            <div id="crm-convert-label" style="font-size: 13px; color: var(--text2); margin-bottom: 16px">{{ convert.label }}</div>
            <div style="display: flex; gap: 8px; justify-content: flex-end">
                <button class="btn btn-ghost" @click="convert.open = false">Не сейчас</button>
                <button class="btn btn-primary" @click="convertLeadToOrder">Создать заказ</button>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
