<script setup lang="ts">
// «Воронка лидов» form — legacy crmLeadModal (L4796–4866), openCrmLeadModal/saveCrmLead/deleteCrmLead/handleTagInput/
// renderLeadTagsInModal (L28905–29260). Legacy keeps whatever this form changes in the page only; here it is the same lead the
// «Лиды» tab shows, saved with segment, tags and the next action. Sources are the lead's sources (legacy listed other words
// here than in the lead form), managers are the CRM users (legacy listed placeholder names).
import { router } from '@inertiajs/vue3';
import { nextTick, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { RELOAD, type LeadRow, type Segment } from '@/lib/crm';

const props = defineProps<{ segments: Segment[]; sources: Record<string, string>; managers: string[] }>();

// Stage of the form → lead status («Лид» is a new lead).
const STAGES: [string, string][] = [
    ['new', 'Лид'],
    ['qualify', 'Квалификация'],
    ['proposal', 'КП отправлено'],
    ['negotiation', 'Переговоры'],
    ['won', 'Сделка закрыта'],
    ['lost', 'Отказ'],
];
const toStage = (status: string) => (['contact', 'meeting'].includes(status) ? 'qualify' : status);

const m = reactive({
    open: false,
    id: null as number | null,
    lockVersion: 0,
    name: '',
    phone: '',
    amount: '' as string | number,
    manager: '',
    source: '',
    stage: 'new',
    initialStage: 'new',
    nextDate: '',
    nextAction: '',
    segment: '' as number | '',
    tags: [] as string[],
    tagInput: '',
    comment: '',
    error: '',
    saved: false,
    busy: false,
});
const tagInputEl = ref<HTMLInputElement | null>(null);

function openCrmLeadModal(lead: LeadRow | null): void {
    Object.assign(m, {
        id: lead?.id ?? null,
        lockVersion: lead?.lock_version ?? 0,
        name: lead?.name ?? '',
        phone: lead?.phone ?? '',
        amount: lead?.budget || '',
        manager: lead?.manager ?? '',
        source: lead?.source ?? '',
        stage: lead ? toStage(lead.status) : 'new',
        initialStage: lead ? toStage(lead.status) : 'new',
        nextDate: lead?.next_date ?? '',
        nextAction: lead?.next_action ?? '',
        segment: lead?.segment ?? '',
        tags: [...(lead?.tags ?? [])],
        tagInput: '',
        comment: lead?.comment ?? '',
        error: '',
        saved: false,
        open: true,
    });
}

function addTag(raw: string): void {
    const val = raw.trim().replace(/,$/, '').trim();
    if (val && !m.tags.includes(val)) m.tags.push(val);
}
function handleTagInput(ev: KeyboardEvent): void {
    if (ev.key === 'Enter' || ev.key === ',' || ev.key === ';') {
        ev.preventDefault();
        addTag(m.tagInput);
        m.tagInput = '';
    } else if (ev.key === 'Backspace' && !m.tagInput && m.tags.length) {
        m.tags.pop();
    }
}

const options = () => ({
    preserveScroll: true,
    preserveState: true,
    only: RELOAD,
    onStart: () => (m.busy = true),
    onFinish: () => (m.busy = false),
    onError: (errors: Record<string, string>) => {
        m.saved = false;
        m.error = Object.values(errors)[0] ?? 'Ошибка сохранения';
    },
});

function saveCrmLead(): void {
    if (m.tagInput.trim()) {
        addTag(m.tagInput);
        m.tagInput = '';
    }
    if (!m.name.trim()) return void (m.error = 'Укажите название клиента или компании');
    m.error = '';
    const data: Record<string, string | number | null | string[]> = {
        form: 'funnel', name: m.name, phone: m.phone, budget: m.amount, manager: m.manager, source: m.source || null,
        next_date: m.nextDate || null, next_action: m.nextAction, segment: m.segment === '' ? null : m.segment, tags: m.tags, comment: m.comment,
    };
    // The stage is sent only when changed: «Контакт»/«Встреча» are shown as «Квалификация» and must not become it by saving.
    if (m.id === null || m.stage !== m.initialStage) data.status = m.stage;
    const done = {
        ...options(),
        onSuccess: () => {
            m.saved = true;
            m.error = '✓ Сохранено!';
            window.setTimeout(() => (m.open = false), 800);
        },
    };
    if (m.id === null) router.post('/crm/leads', data, done);
    else router.put(`/crm/leads/${m.id}`, { ...data, lock_version: m.lockVersion }, done);
}

function deleteCrmLead(): void {
    if (m.id === null) return;
    router.delete(`/crm/leads/${m.id}`, { ...options(), data: { lock_version: m.lockVersion }, onSuccess: () => (m.open = false) });
}

defineExpose({ openCrmLeadModal, focusTags: () => nextTick(() => tagInputEl.value?.focus()) });
</script>

<template>
    <ErpModal id="crmLeadModal" :open="m.open" max-width="560px" @close="m.open = false">
        <div class="modal-header">
            <div id="crm-lead-modal-title" class="modal-title">{{ m.id === null ? 'Новый лид' : 'Карточка лида' }}</div>
            <button class="modal-close" @click="m.open = false">✕</button>
        </div>
        <div id="crm-lead-form-error" style="font-size: 13px; margin-bottom: 8px; min-height: 18px" :style="{ color: m.saved ? 'var(--green)' : 'var(--red)' }">{{ m.error }}</div>

        <div class="form-row cols-2">
            <div><label class="label">Клиент / компания</label><input id="cl-name" v-model="m.name" type="text" placeholder="ТОО «...» или Иванов И.И." /></div>
            <div><label class="label">Телефон</label><input id="cl-phone" v-model="m.phone" type="text" placeholder="+7 7XX XXX XXXX" /></div>
        </div>
        <div class="form-row cols-2">
            <div><label class="label">Сумма сделки (₸)</label><input id="cl-amount" v-model="m.amount" type="number" min="0" placeholder="0" /></div>
            <div>
                <label class="label">Ответственный менеджер</label>
                <select id="cl-manager" v-model="m.manager">
                    <option value="">— выберите —</option>
                    <option v-for="name in props.managers" :key="name" :value="name">{{ name }}</option>
                </select>
            </div>
        </div>
        <div class="form-row cols-2">
            <div>
                <label class="label">Источник лида</label>
                <select id="cl-source" v-model="m.source">
                    <option value="">—</option>
                    <option v-for="(label, key) in props.sources" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
            <div>
                <label class="label">Стадия</label>
                <select id="cl-stage" v-model="m.stage">
                    <option v-for="[value, label] in STAGES" :key="value" :value="value">{{ label }}</option>
                </select>
            </div>
        </div>
        <div class="form-row cols-2">
            <div><label class="label">Следующее действие — дата</label><input id="cl-next-date" v-model="m.nextDate" type="date" /></div>
            <div><label class="label">Что сделать</label><input id="cl-next-action" v-model="m.nextAction" type="text" placeholder="Позвонить, отправить КП..." /></div>
        </div>
        <div class="form-row cols-2">
            <div>
                <label class="label">Сегмент</label>
                <select id="cl-segment" v-model="m.segment">
                    <option value="">— не задан —</option>
                    <option v-for="s in props.segments" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
            </div>
            <div></div>
        </div>
        <div class="form-row">
            <label class="label">Теги <span style="font-size: 11px; color: var(--text3); font-weight: 400">(введите и нажмите Enter или запятую)</span></label>
            <div id="cl-tags-wrap" class="tag-input-wrap" @click="tagInputEl?.focus()">
                <span v-for="tag in m.tags" :key="tag" class="tag-chip">{{ tag }}<span class="tag-remove" @click.stop="m.tags = m.tags.filter((t) => t !== tag)">✕</span></span>
                <input id="cl-tag-input" ref="tagInputEl" v-model="m.tagInput" type="text" placeholder="Добавить тег..." @keydown="handleTagInput" />
            </div>
        </div>
        <div class="form-row">
            <label class="label">Комментарий</label>
            <textarea
                id="cl-comment"
                v-model="m.comment"
                rows="2"
                placeholder="Заметки по клиенту..."
                style="width: 100%; padding: 8px; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; color: var(--text1); font-size: 13px; resize: vertical"
            ></textarea>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
            <button v-show="m.id !== null" id="crm-lead-delete-btn" class="btn btn-ghost" style="margin-right: auto; color: var(--red)" :disabled="m.busy" @click="deleteCrmLead">Удалить</button>
            <button class="btn btn-ghost" @click="m.open = false">Отмена</button>
            <button class="btn btn-primary" :disabled="m.busy || m.saved" @click="saveCrmLead">Сохранить</button>
        </div>
    </ErpModal>
</template>
