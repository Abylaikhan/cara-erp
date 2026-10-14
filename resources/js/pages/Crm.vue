<script setup lang="ts">
// M10 CRM — legacy page-crm (L4134–4373), showOrdersTab (L30753), crmConvertModal (L4869). Tabs «Лиды» and «Воронка лидов»
// are migrated (LeadsTab, FunnelTab); «Клиенты», the order boards and «Задачи» need orders (M09) and the next part of CRM.
// As in legacy, no tab content is shown until a tab is chosen, and each tab is built when first opened.
import { usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import FunnelTab from '@/components/crm/FunnelTab.vue';
import LeadsTab from '@/components/crm/LeadsTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { convert, ORDERS_LATER, type FunnelStage, type LeadRow, type LeadStatus, type Segment } from '@/lib/crm';
import type { SharedProps } from '@/types';

const props = defineProps<{
    leads: LeadRow[];
    statuses: Record<string, LeadStatus>;
    sources: Record<string, string>;
    segments: Segment[];
    funnelStages: FunnelStage[];
    managers: string[];
    currentYear: number;
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
const LATER: Partial<Record<Tab, string>> = {
    clients: 'Клиенты салонов считаются по заказам покупателей: вкладка появится после переноса раздела «Продажи».',
    'orders-kanban-prod': 'Доска заказов по производству появится после переноса раздела «Продажи».',
    'orders-kanban-pay': 'Доска заказов по оплате появится после переноса раздела «Продажи».',
    tasks: 'Задачи и напоминания CRM переносятся следующей частью раздела.',
};

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
                <div class="tab" :class="{ active: active === 'tasks' }" @click="showOrdersTab('tasks')">✅ Задачи (<span id="tasks-open-count">—</span>)</div>
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
                    :managers="props.managers"
                    :active="shown === 'funnel'"
                />
            </div>
            <template v-for="(text, key) in LATER" :key="key">
                <div v-if="visited.has(key)" :id="'orders-' + key" class="orders-tab" :style="display(key)">
                    <div class="card">
                        <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                    </div>
                </div>
            </template>
        </div>

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
