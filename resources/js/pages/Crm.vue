<script setup lang="ts">
// M10 CRM — legacy page-crm (L4134–4373), showOrdersTab (L30753), crmConvertModal (L4869). Tabs «Клиенты», «Лиды», «Воронка
// лидов», «Задачи» and the order boards are migrated (ClientsTab, LeadsTab, FunnelTab, TasksTab, OrderBoards). As in legacy, no
// tab content is shown until a tab is chosen, and each tab is built when first opened. The lead form of the funnel and the
// task form are opened from several tabs, so they belong to the page. A dealer account and the head of the shop see only the tab
// «Проекты» (legacy prjApplyRole, L31725), which is opened at once; the tab itself is ProjectsTab.
import { usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ClientsTab from '@/components/crm/ClientsTab.vue';
import FunnelLeadModal from '@/components/crm/FunnelLeadModal.vue';
import FunnelTab from '@/components/crm/FunnelTab.vue';
import LeadsTab from '@/components/crm/LeadsTab.vue';
import OrderBoards from '@/components/crm/OrderBoards.vue';
import ProjectsTab from '@/components/crm/ProjectsTab.vue';
import TaskModal from '@/components/crm/TaskModal.vue';
import TasksTab from '@/components/crm/TasksTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { convert, ORDERS_LATER, type FunnelStage, type LeadRow, type LeadStatus, type Segment, type TaskRow } from '@/lib/crm';
import type { ProjectMeta, ProjectRow } from '@/lib/projects';
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
    clientsCount: number;
    clientOrders?: InstanceType<typeof ClientsTab>['$props']['orders'];
    boardOrders?: InstanceType<typeof OrderBoards>['$props']['orders'];
    channelBound: boolean;
    today: string;
    projectsOnly: boolean;
    projectsCount: number;
    projects?: ProjectRow[];
    projectMeta: ProjectMeta;
}>();

type Tab = 'clients' | 'leads' | 'projects' | 'funnel' | 'orders-kanban-prod' | 'orders-kanban-pay' | 'tasks';
// «Клиенты» is marked active from the start while nothing is shown (legacy); «Проекты» is opened for projectsOnly.
const active = ref<Tab>(props.projectsOnly ? 'projects' : 'clients');
const shown = ref<Tab | null>(props.projectsOnly ? 'projects' : null);
const visited = reactive(new Set<Tab>(props.projectsOnly ? ['projects'] : []));
// Legacy prjApplyRole hides every other tab of the bar.
const hidden = (tab: Tab) => (props.projectsOnly && tab !== 'projects' ? { display: 'none' } : undefined);
const projectsTab = ref<InstanceType<typeof ProjectsTab> | null>(null);
// Open projects: counted by the server at once, then by the loaded list (legacy loadProjects).
const projectsShown = computed(() => (props.projects !== undefined && projectsTab.value ? projectsTab.value.openCount : props.projectsCount));
// .orders-tab is hidden by the legacy stylesheet; the chosen one gets display:block, as legacy does.
const display = (tab: Tab) => ({ display: shown.value === tab ? 'block' : 'none' });
function showOrdersTab(tab: Tab): void {
    active.value = tab;
    shown.value = tab;
    visited.add(tab);
}

const activeLeads = computed(() => props.leads.filter((l) => l.status !== 'won' && l.status !== 'lost').length);
const openTasks = computed(() => props.tasks.filter((t) => t.status === 'open').length);
const clientsTab = ref<InstanceType<typeof ClientsTab> | null>(null);
// Legacy shows the number of salon clients, then the number the tab lists (with its filters).
const clientsShown = computed(() => (props.clientOrders !== undefined && clientsTab.value ? clientsTab.value.count : props.clientsCount));

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
                <div><div class="section-title">CRM</div><div class="section-sub">{{ props.projectsOnly ? 'Проекты · расчёт нестандартной мебели' : 'Клиенты · Лиды · Воронки · Задачи' }}</div></div>
                <div style="display: flex; gap: 8px">
                    <button v-if="!props.projectsOnly" class="btn btn-primary btn-sm" @click="orders">+ Новый заказ покупателя</button>
                </div>
            </div>
            <div class="tabs" style="align-items: center">
                <div id="crm-tab-clients" class="tab" :class="{ active: active === 'clients' }" :style="hidden('clients')" @click="showOrdersTab('clients')">
                    👥 Клиенты (<span id="tab-count-clients">{{ clientsShown }}</span>)
                </div>
                <div id="crm-tab-leads" class="tab" :class="{ active: active === 'leads' }" :style="hidden('leads')" @click="showOrdersTab('leads')">
                    🎯 Лиды (<span id="tab-count-leads">{{ activeLeads }}</span>)
                </div>
                <div id="crm-tab-projects" class="tab" :class="{ active: active === 'projects' }" @click="showOrdersTab('projects')">📐 Проекты (<span id="tab-count-projects">{{ projectsShown }}</span>)</div>
                <div class="tab" :class="{ active: active === 'funnel' }" :style="hidden('funnel')" @click="showOrdersTab('funnel')">🎯 Воронка лидов</div>
                <div class="tab" :class="{ active: active === 'orders-kanban-prod' }" :style="hidden('orders-kanban-prod')" @click="showOrdersTab('orders-kanban-prod')">
                    🏭 По производству
                </div>
                <div class="tab" :class="{ active: active === 'orders-kanban-pay' }" :style="hidden('orders-kanban-pay')" @click="showOrdersTab('orders-kanban-pay')">💳 По оплате</div>
                <div class="tab" :class="{ active: active === 'tasks' }" :style="hidden('tasks')" @click="showOrdersTab('tasks')">
                    ✅ Задачи (<span id="tasks-open-count">{{ openTasks }}</span>)
                </div>
            </div>

            <div v-if="visited.has('clients')" id="orders-clients" class="orders-tab" :style="display('clients')">
                <ClientsTab ref="clientsTab" :orders="props.clientOrders" :active="shown === 'clients'" />
            </div>
            <div v-if="visited.has('leads')" id="orders-leads" class="orders-tab" :style="display('leads')">
                <LeadsTab :leads="props.leads" :statuses="props.statuses" :sources="props.sources" :current-year="props.currentYear" />
            </div>
            <div v-if="visited.has('projects')" id="orders-projects" class="orders-tab" :style="display('projects')">
                <ProjectsTab ref="projectsTab" :projects="props.projects" :meta="props.projectMeta" :timezone="props.timezone" :active="shown === 'projects'" />
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
            <template v-for="b in [{ tab: 'orders-kanban-prod', kind: 'prod' }, { tab: 'orders-kanban-pay', kind: 'pay' }] as const" :key="b.tab">
                <div v-if="visited.has(b.tab)" :id="'orders-' + b.tab" class="orders-tab" :style="display(b.tab)">
                    <OrderBoards :orders="props.boardOrders" :kind="b.kind" :channel-bound="props.channelBound" :today="props.today" :active="shown === b.tab" />
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
