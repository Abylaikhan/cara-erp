<script setup lang="ts">
// Task form — legacy taskModal (L4744–4794), openTaskModal/filterTaskLeadList/selectTaskLead/clearTaskLead/saveTask/deleteTask
// (L29647–29826). Same fields and texts; saved by the server. The managers are the salon staff of «Сотрудники», labelled as
// legacy does; a task's manager who is not among them stays chosen (legacy erased such a manager on saving). A new task is
// dated «today» in the business time zone.
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { businessNow, TASK_RELOAD, type LeadRow, type TaskRow } from '@/lib/crm';

const props = defineProps<{ leads: LeadRow[]; managers: { name: string; pos: string; comment: string }[]; timezone: string }>();

const m = reactive({
    open: false,
    id: null as number | null,
    lockVersion: 0,
    title: '',
    date: '',
    time: '',
    manager: '',
    priority: 'normal',
    leadId: null as number | null,
    search: '',
    dropdown: false,
    error: '',
    saved: false,
    busy: false,
});
const selected = computed(() => (m.leadId === null ? null : (props.leads.find((l) => l.id === m.leadId) ?? null)));
// Legacy fillSellers: «name (comment)», else «name (position)»; the manager of an edited task is kept when not in the list.
const managerOptions = computed(() => {
    const list = props.managers.map((e) => ({ name: e.name, label: e.name + (e.comment ? ' (' + e.comment + ')' : e.pos ? ' (' + e.pos + ')' : '') }));
    return m.manager && !list.some((o) => o.name === m.manager) ? [...list, { name: m.manager, label: m.manager }] : list;
});

function openTaskModal(task: TaskRow | null, prefillLeadId: number | null = null): void {
    const leadId = task ? task.lead_id : prefillLeadId;
    Object.assign(m, {
        id: task?.id ?? null,
        lockVersion: task?.lock_version ?? 0,
        title: task?.title ?? '',
        date: task ? (task.due_date ?? '') : businessNow(props.timezone).date,
        time: task ? (task.due_time ?? '') : '10:00',
        manager: task?.manager ?? '',
        priority: task?.priority ?? 'normal',
        leadId,
        search: (leadId !== null && props.leads.find((l) => l.id === leadId)?.name) || '',
        dropdown: false,
        error: '',
        saved: false,
        open: true,
    });
}

// Legacy offers the leads it has loaded — the open ones; at most 30 matches by name or phone.
const matches = computed(() => {
    const q = m.search.toLowerCase();
    return q ? props.leads.filter((l) => l.status !== 'won' && l.status !== 'lost' && ((l.name || '').toLowerCase().includes(q) || (l.phone || '').includes(q))).slice(0, 30) : [];
});
function selectTaskLead(lead: LeadRow): void {
    m.leadId = lead.id;
    m.search = lead.name;
    m.dropdown = false;
}
function clearTaskLead(): void {
    m.leadId = null;
    m.search = '';
}
const searchEl = ref<HTMLInputElement | null>(null);
const dropdownEl = ref<HTMLElement | null>(null);
const closeDropdown = (e: MouseEvent) => {
    if (dropdownEl.value && !dropdownEl.value.contains(e.target as Node) && e.target !== searchEl.value) m.dropdown = false;
};
onMounted(() => document.addEventListener('click', closeDropdown));
onBeforeUnmount(() => document.removeEventListener('click', closeDropdown));

const options = () => ({
    preserveScroll: true,
    preserveState: true,
    only: TASK_RELOAD,
    onStart: () => (m.busy = true),
    onFinish: () => (m.busy = false),
    onError: (errors: Record<string, string>) => {
        m.saved = false;
        m.error = Object.values(errors)[0] ?? 'Ошибка сохранения';
    },
});

function saveTask(): void {
    if (!m.title.trim()) return void (m.error = 'Укажите описание задачи');
    m.error = '';
    const data = { title: m.title, due_date: m.date, due_time: m.time, manager: m.manager, priority: m.priority, lead_id: m.leadId };
    const done = {
        ...options(),
        onSuccess: () => {
            m.saved = true;
            m.error = '✓ Сохранено!';
            window.setTimeout(() => (m.open = false), 700);
        },
    };
    if (m.id === null) router.post('/crm/tasks', data, done);
    else router.put(`/crm/tasks/${m.id}`, { ...data, lock_version: m.lockVersion }, done);
}

function deleteTask(): void {
    if (m.id === null) return;
    router.delete(`/crm/tasks/${m.id}`, { ...options(), data: { lock_version: m.lockVersion }, onSuccess: () => (m.open = false) });
}

defineExpose({ openTaskModal });
</script>

<template>
    <ErpModal id="taskModal" :open="m.open" max-width="520px" @close="m.open = false">
        <div class="modal-header">
            <div id="task-modal-title" class="modal-title">{{ m.id === null ? 'Новая задача' : 'Редактировать задачу' }}</div>
            <button class="modal-close" @click="m.open = false">✕</button>
        </div>
        <div id="task-form-error" style="font-size: 13px; margin-bottom: 8px; min-height: 18px" :style="{ color: m.saved ? 'var(--green)' : 'var(--red)' }">{{ m.error }}</div>

        <div class="form-row">
            <label class="label">Описание задачи</label>
            <textarea
                id="task-title"
                v-model="m.title"
                rows="2"
                placeholder="Позвонить клиенту, отправить КП, согласовать сроки..."
                style="width: 100%; padding: 8px; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; color: var(--text1); font-size: 13px; resize: none"
            ></textarea>
        </div>
        <div class="form-row cols-2">
            <div><label class="label">Дата напоминания</label><input id="task-date" v-model="m.date" type="date" /></div>
            <div><label class="label">Время напоминания</label><input id="task-time" v-model="m.time" type="time" /></div>
        </div>
        <div class="form-row cols-2">
            <div>
                <label class="label">Ответственный менеджер</label>
                <select id="task-manager" v-model="m.manager">
                    <option value="">— выберите —</option>
                    <option v-for="o in managerOptions" :key="o.name" :value="o.name">{{ o.label }}</option>
                </select>
            </div>
            <div>
                <label class="label">Приоритет</label>
                <select id="task-priority" v-model="m.priority">
                    <option value="high">🔴 Высокий</option>
                    <option value="normal">🟡 Средний</option>
                    <option value="low">⚪ Низкий</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <label class="label">Привязка к лиду (необязательно)</label>
            <div style="position: relative">
                <input
                    id="task-lead-search"
                    ref="searchEl"
                    v-model="m.search"
                    type="search"
                    placeholder="🔍 Начните вводить имя лида..."
                    style="font-size: 13px; width: 100%"
                    autocomplete="off"
                    @input="m.dropdown = !!m.search"
                />
                <div
                    v-show="m.dropdown"
                    id="task-lead-dropdown"
                    ref="dropdownEl"
                    style="position: absolute; z-index: 999; width: 100%; max-height: 200px; overflow-y: auto; background: var(--bg3); border: 1px solid var(--border); border-radius: 0 0 8px 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2)"
                >
                    <div v-if="!matches.length" style="padding: 8px 12px; font-size: 12px; color: var(--text3)">Не найдено</div>
                    <div v-for="l in matches" :key="l.id" class="task-lead-option" style="padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid var(--border2)" @click.stop="selectTaskLead(l)">
                        <b>{{ l.name || '—' }}</b>{{ ' ' }}
                        <span v-if="l.phone" style="color: var(--text3); font-size: 11px; margin-left: 8px">{{ l.phone }}</span>{{ ' ' }}
                        <span v-if="l.status" style="color: var(--accent); font-size: 10px; margin-left: 6px">{{ l.status }}</span>
                    </div>
                </div>
            </div>
            <div id="task-lead-selected" style="font-size: 12px; color: var(--accent); margin-top: 6px; min-height: 18px">
                <template v-if="selected">
                    ✅ Выбран: <b>{{ selected.name }}</b>{{ selected.phone ? ' · ' + selected.phone : '' }}
                    <button style="margin-left: 8px; font-size: 10px; background: none; border: none; color: var(--text3); cursor: pointer" @click="clearTaskLead">✕ убрать</button>
                </template>
            </div>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
            <button v-show="m.id !== null" id="task-delete-btn" class="btn btn-ghost" style="margin-right: auto; color: var(--red)" :disabled="m.busy" @click="deleteTask">Удалить</button>
            <button class="btn btn-ghost" @click="m.open = false">Отмена</button>
            <button class="btn btn-primary" :disabled="m.busy || m.saved" @click="saveTask">Сохранить</button>
        </div>
    </ErpModal>
</template>
