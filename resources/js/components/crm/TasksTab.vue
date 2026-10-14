<script setup lang="ts">
// Tab «Задачи» of CRM — legacy orders-tasks (L4154–4180), renderTasks/populateTasksMgrFilter/showTasksSubtab/showOverdueTasks/
// isTaskOverdue/fmtTaskDateTime/toggleTask (L29506–29645). Same filters, sub-tabs, banner and cards; «today» and «now» are
// taken in the business time zone (legacy took the UTC date).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { businessNow, daysBetween, fmtTaskDateTime, isTaskOverdue, priorityOf, toggleTask, type LeadRow, type TaskRow } from '@/lib/crm';

const props = defineProps<{ tasks: TaskRow[]; leads: LeadRow[]; timezone: string }>();
const emit = defineEmits<{ 'new-task': []; 'edit-task': [task: TaskRow]; 'open-lead': [lead: LeadRow] }>();

const now = ref(businessNow(props.timezone));
let timer = 0;
onMounted(() => (timer = window.setInterval(() => (now.value = businessNow(props.timezone)), 60000)));
onBeforeUnmount(() => window.clearInterval(timer));

const manager = ref('');
const status = ref('open');
const subtab = ref<'lead' | 'general'>('lead');
const managers = computed(() => [...new Set(props.tasks.map((t) => t.manager).filter((m): m is string => !!m))].sort());
const overdue = (t: TaskRow) => isTaskOverdue(t, now.value);
const overdueOpen = computed(() => props.tasks.filter((t) => t.status === 'open' && overdue(t)));

const shown = computed(() =>
    props.tasks
        .filter((t) => {
            if (subtab.value === 'lead' && !t.lead_id) return false;
            if (subtab.value === 'general' && t.lead_id) return false;
            if (manager.value && t.manager !== manager.value) return false;
            if (status.value === 'open' && t.status !== 'open') return false;
            if (status.value === 'done' && t.status !== 'done') return false;
            return true;
        })
        // Open first, overdue first, then by date and time.
        .sort((a, b) => {
            if (a.status !== b.status) return a.status === 'open' ? -1 : 1;
            const aOver = overdue(a);
            const bOver = overdue(b);
            if (aOver !== bOver) return aOver ? -1 : 1;
            return ((a.due_date || '9999') + (a.due_time || '00:00')).localeCompare((b.due_date || '9999') + (b.due_time || '00:00'));
        }),
);

function showOverdueTasks(): void {
    if (!overdueOpen.value.length) return;
    subtab.value = overdueOpen.value.some((t) => t.lead_id) ? 'lead' : 'general';
}

const leadOf = (t: TaskRow) => (t.lead_id ? (props.leads.find((l) => l.id === t.lead_id) ?? null) : null);
function due(t: TaskRow): { text: string; style: string; cls: string } {
    if (overdue(t)) return { text: `⚠️ ${fmtTaskDateTime(t)} — просрочена!`, style: 'color:var(--red);font-weight:700', cls: '' };
    const days = t.due_date ? daysBetween(now.value.date, t.due_date) : null;
    if (days === 0) return { text: `📅 Сегодня ${t.due_time || ''}`, style: 'color:var(--amber)', cls: '' };
    if (days === 1) return { text: `📅 Завтра ${t.due_time || ''}`, style: 'color:var(--amber)', cls: '' };
    return { text: `📅 ${fmtTaskDateTime(t)}`, style: '', cls: 'task-datetime' };
}

defineExpose({ openCount: computed(() => props.tasks.filter((t) => t.status === 'open').length) });
</script>

<template>
    <div class="section-header" style="margin-bottom: 14px">
        <div style="display: flex; gap: 8px; align-items: center">
            <select id="tasks-mgr-filter" v-model="manager" style="font-size: 13px; width: auto">
                <option value="">Все менеджеры</option>
                <option v-for="m in managers" :key="m" :value="m">{{ m }}</option>
            </select>
            <select id="tasks-status-filter" v-model="status" style="font-size: 13px; width: auto">
                <option value="open">Открытые</option>
                <option value="done">Выполненные</option>
                <option value="all">Все</option>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" @click="emit('new-task')">+ Новая задача</button>
    </div>

    <!-- Sub-tabs: By lead / General -->
    <div id="tasks-subtabs" class="tabs" style="margin-bottom: 14px">
        <div class="tab" :class="{ active: subtab === 'lead' }" @click="subtab = 'lead'">
            По лидам <span id="tasks-count-lead" class="kanban-count">{{ props.tasks.filter((t) => t.lead_id).length }}</span>
        </div>
        <div class="tab" :class="{ active: subtab === 'general' }" @click="subtab = 'general'">
            Общие <span id="tasks-count-general" class="kanban-count">{{ props.tasks.filter((t) => !t.lead_id).length }}</span>
        </div>
    </div>

    <!-- Overdue banner -->
    <div
        id="tasks-overdue-banner"
        style="background: rgba(245, 92, 92, 0.1); border: 1px solid var(--red); border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px; color: var(--red)"
        :style="{ display: overdueOpen.length ? 'block' : 'none' }"
    >
        ⚠ Просрочено задач: {{ overdueOpen.length }} — срочно обработайте!{{ ' ' }}
        <button
            style="margin-left: 8px; font-size: 11px; padding: 2px 8px; background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: 4px; cursor: pointer; color: inherit"
            @click="showOverdueTasks"
        >
            Показать все
        </button>
    </div>

    <div id="tasks-list">
        <div v-if="!shown.length" style="padding: 32px; text-align: center; color: var(--text3); font-size: 13px">
            Нет задач{{ status === 'open' ? ' в работе' : '' }}. <span style="color: var(--accent); cursor: pointer" @click="emit('new-task')">+ Создать задачу</span>
        </div>
        <div
            v-for="t in shown"
            :key="t.id"
            :class="['task-card', t.status === 'done' ? 'task-done' : priorityOf(t).cls, overdue(t) ? 'task-overdue' : '']"
            style="position: relative"
        >
            <div class="task-checkbox" :class="{ checked: t.status === 'done' }" :title="t.status === 'done' ? 'Переоткрыть' : 'Отметить выполненной'" @click="toggleTask(t)">
                {{ t.status === 'done' ? '✓' : '' }}
            </div>
            <div class="task-body">
                <div class="task-title">{{ t.title }}</div>
                <div v-if="leadOf(t)" style="margin: 3px 0 2px; font-size: 12px">
                    <span style="color: var(--accent); cursor: pointer; font-weight: 500" @click="emit('open-lead', leadOf(t)!)">🔗 {{ leadOf(t)!.name }}</span
                    ><span v-if="leadOf(t)!.phone" style="color: var(--text3); margin-left: 6px">{{ leadOf(t)!.phone }}</span>{{ ' ' }}
                    <span v-if="leadOf(t)!.interest" style="color: var(--text3); margin-left: 6px; font-size: 11px">· {{ leadOf(t)!.interest!.slice(0, 30) }}</span>
                </div>
                <div v-else style="font-size: 11px; color: var(--text3); margin: 2px 0">📋 Общая задача</div>
                <div class="task-meta">
                    <span :class="due(t).cls" :style="due(t).style">{{ due(t).text }}</span>
                    <span v-if="t.manager">👤 {{ t.manager }}</span>
                    <span :class="priorityOf(t).textCls">{{ priorityOf(t).icon }} {{ priorityOf(t).label }}</span>
                </div>
            </div>
            <div class="task-actions">
                <button class="btn btn-ghost btn-sm" style="font-size: 11px; padding: 3px 8px" @click="emit('edit-task', t)">✏️</button>
            </div>
        </div>
    </div>
</template>
