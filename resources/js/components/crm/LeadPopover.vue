<script setup lang="ts">
// Lead card «⋯» of the funnel — legacy crm-popover (L8475–8546), openCrmPopover/closeCrmPopover/removeLeadTag/
// renderCommTimeline/selectCommType/toggleCommAddForm/saveCommEntry/deleteCommEntry/repositionPopoverIfOpen/
// crmMoveToNextStage (L28941–29492). Same rows, history and placement above the button. The history is stored (legacy kept it
// in page memory with demo entries), ✕ of an entry and of a tag work (legacy failed on the unquoted lead id), the amount is
// the lead's budget and «→ Следующий этап» is saved.
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { businessNow, COMM_TYPES, moveToNextStage, RELOAD, type Communication, type LeadRow, type Segment } from '@/lib/crm';

const props = defineProps<{ leads: LeadRow[]; segments: Segment[]; sources: Record<string, string>; stageOrder: string[]; timezone: string }>();
const emit = defineEmits<{ edit: [lead: LeadRow] }>();

const leadId = ref<number | null>(null);
const lead = computed(() => (leadId.value === null ? null : (props.leads.find((l) => l.id === leadId.value) ?? null)));
const pos = reactive({ shown: false, top: 0, left: 0, below: false });
const visible = computed(() => lead.value !== null && pos.shown);
const comms = ref<Communication[]>([]);
const form = reactive({ open: false, type: 'call', date: '', text: '', error: '', busy: false });
const pop = ref<HTMLElement | null>(null);
const textEl = ref<HTMLTextAreaElement | null>(null);

// The communications of the lead shown now: an answer for a lead opened before comes too late and is dropped.
async function loadComms(id: number): Promise<void> {
    const response = await fetch(`/crm/leads/${id}/communications`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    const list = response.ok ? await response.json() : [];
    if (leadId.value === id) comms.value = list;
}

async function open(l: LeadRow, button: HTMLElement): Promise<void> {
    leadId.value = l.id;
    form.open = false;
    await loadComms(l.id);
    if (leadId.value !== l.id) return;
    pos.shown = true;
    await nextTick();
    // Above the button, right edges aligned; below it when there is no room above (legacy openCrmPopover).
    const rect = button.getBoundingClientRect();
    const popH = pop.value?.offsetHeight || 340;
    let top = rect.top - popH - 10;
    pos.below = top < 8;
    if (pos.below) top = rect.bottom + 10;
    pos.top = top;
    pos.left = Math.max(8, rect.right - 340);
}
function close(): void {
    pos.shown = false;
    leadId.value = null;
}
/** Legacy repositionPopoverIfOpen: a taller card is moved up to stay inside the window. */
async function reposition(): Promise<void> {
    await nextTick();
    if (!pop.value || !pos.shown) return;
    if (pos.top + pop.value.offsetHeight > window.innerHeight - 10) pos.top = Math.max(8, window.innerHeight - pop.value.offsetHeight - 10);
}
const outside = (e: MouseEvent) => {
    const target = e.target as HTMLElement;
    if (pos.shown && pop.value && !pop.value.contains(target) && !target.classList.contains('crm-card-detail-btn')) close();
};
onMounted(() => document.addEventListener('click', outside, true));
onBeforeUnmount(() => document.removeEventListener('click', outside, true));

const segment = computed(() => props.segments.find((s) => s.id === lead.value?.segment));
const nextText = computed(() => {
    const l = lead.value;
    if (!l || (!l.next_date && !l.next_action)) return '';
    const date = l.next_date ? new Date(l.next_date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' — ' : '';
    return date + (l.next_action || '(не указано)');
});
const timeline = computed(() => [...comms.value].reverse());
const fmtDate = (d: string | null) => (d ? new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '');

const visit = { preserveScroll: true, preserveState: true, only: RELOAD, onError: (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка сохранения') };

function removeLeadTag(tag: string): void {
    const l = lead.value;
    if (!l) return;
    router.put(`/crm/leads/${l.id}`, { form: 'funnel', tags: l.tags.filter((t) => t !== tag), lock_version: l.lock_version }, visit);
}

function toggleCommAddForm(): void {
    form.open = !form.open;
    if (form.open) Object.assign(form, { date: businessNow(props.timezone).date, text: '', type: 'call', error: '' });
    reposition();
}
function saveCommEntry(): void {
    const l = lead.value;
    if (!l) return;
    if (!form.text.trim()) return void textEl.value?.focus();
    router.post(`/crm/leads/${l.id}/communications`, { type: form.type, happened_on: form.date, text: form.text }, {
        ...visit,
        only: ['flash'],
        onStart: () => (form.busy = true),
        onFinish: () => (form.busy = false),
        onSuccess: async () => {
            form.open = false;
            await loadComms(l.id);
            reposition();
        },
    });
}
function deleteCommEntry(entry: Communication): void {
    const l = lead.value;
    if (!l) return;
    router.delete(`/crm/communications/${entry.id}`, {
        ...visit,
        only: ['flash'],
        onSuccess: async () => {
            await loadComms(l.id);
            reposition();
        },
    });
}

function edit(): void {
    const l = lead.value;
    close();
    if (l) emit('edit', l);
}
function next(): void {
    const l = lead.value;
    close();
    if (l) moveToNextStage(l, props.stageOrder);
}

defineExpose({ open });
</script>

<template>
    <div
        v-if="visible && lead"
        id="crm-popover"
        ref="pop"
        class="crm-popover"
        style="width: 340px; max-height: 82vh; overflow-y: auto"
        :style="{ top: pos.top + 'px', left: pos.left + 'px' }"
    >
        <div class="crm-popover-arrow" :style="pos.below ? { bottom: 'auto', top: '-7px', transform: 'rotate(225deg)' } : { top: 'auto', bottom: '-7px', transform: 'rotate(45deg)' }"></div>
        <div id="crm-pop-name" class="crm-popover-title">{{ lead.name }}</div>
        <button class="crm-popover-close" @click="close">✕</button>

        <div class="crm-popover-row">
            <div class="crm-popover-row-icon">💰</div>
            <div class="crm-popover-row-label">Сумма:</div>
            <div id="crm-pop-amount" class="crm-popover-row-value">{{ lead.budget ? '₸' + lead.budget.toLocaleString('ru-RU') : '—' }}</div>
        </div>
        <div class="crm-popover-row">
            <div class="crm-popover-row-icon">👤</div>
            <div class="crm-popover-row-label">Менеджер:</div>
            <div id="crm-pop-manager" class="crm-popover-row-value">{{ lead.manager || '—' }}</div>
        </div>
        <div class="crm-popover-row">
            <div class="crm-popover-row-icon">📞</div>
            <div class="crm-popover-row-label">Телефон:</div>
            <div id="crm-pop-phone" class="crm-popover-row-value">{{ lead.phone || '—' }}</div>
        </div>
        <div class="crm-popover-row">
            <div class="crm-popover-row-icon">🔍</div>
            <div class="crm-popover-row-label">Источник:</div>
            <div id="crm-pop-source" class="crm-popover-row-value">{{ (lead.source && props.sources[lead.source]) || lead.source || '—' }}</div>
        </div>
        <div id="crm-pop-next-row" class="crm-popover-row" :style="{ display: nextText ? 'flex' : 'none' }">
            <div class="crm-popover-row-icon">📅</div>
            <div class="crm-popover-row-label">След. действие:</div>
            <div id="crm-pop-next" class="crm-popover-row-value">{{ nextText }}</div>
        </div>
        <div id="crm-pop-comment-row" class="crm-popover-row" :style="{ display: lead.comment ? 'flex' : 'none' }">
            <div class="crm-popover-row-icon">💬</div>
            <div class="crm-popover-row-label">Комментарий:</div>
            <div id="crm-pop-comment" class="crm-popover-row-value">{{ lead.comment }}</div>
        </div>

        <!-- Segment + Tags -->
        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border)">
            <div style="font-size: 11px; color: var(--text3); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px">Сегмент и теги</div>
            <div id="crm-pop-meta">
                <div v-if="segment" style="margin-bottom: 6px">
                    <span class="segment-badge" :style="{ background: segment.color }">{{ segment.label }}</span>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 4px">
                    <span v-for="t in lead.tags" :key="t" class="tag-chip" style="font-size: 10.5px">🏷 {{ t }}<span class="tag-remove" @click="removeLeadTag(t)">✕</span></span>
                    <span v-if="!lead.tags.length" style="font-size: 11px; color: var(--text3)">нет тегов</span>
                </div>
            </div>
        </div>

        <!-- Communication history timeline -->
        <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border)">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px">
                <div style="font-size: 12px; font-weight: 600; color: var(--text2)">История коммуникации</div>
                <button class="btn btn-ghost btn-sm" style="font-size: 11px; padding: 3px 8px" @click="toggleCommAddForm">+ Добавить</button>
            </div>

            <!-- Quick-add form (hidden by default) -->
            <div v-show="form.open" id="crm-comm-add-form" class="comm-add-form">
                <div id="crm-comm-type-btns" class="comm-type-btns">
                    <button v-for="(ct, key) in COMM_TYPES" :key="key" class="comm-type-btn" :class="{ active: form.type === key }" :data-type="key" @click="form.type = key">
                        {{ ct.icon }} {{ ct.label }}
                    </button>
                </div>
                <input
                    id="crm-comm-date"
                    v-model="form.date"
                    type="date"
                    style="width: 100%; margin-bottom: 8px; font-size: 12px; padding: 5px 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text1)"
                />
                <textarea
                    id="crm-comm-text"
                    ref="textEl"
                    v-model="form.text"
                    rows="2"
                    placeholder="Что обсуждалось / итог..."
                    style="width: 100%; padding: 6px 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text1); font-size: 12px; resize: none; margin-bottom: 8px"
                ></textarea>
                <div style="display: flex; gap: 6px; justify-content: flex-end">
                    <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="toggleCommAddForm">Отмена</button>
                    <button class="btn btn-primary btn-sm" style="font-size: 11px" :disabled="form.busy" @click="saveCommEntry">Сохранить</button>
                </div>
            </div>

            <div id="crm-comm-timeline" class="comm-timeline">
                <div v-if="!timeline.length" class="comm-empty">Коммуникаций пока нет.<br />Нажмите «+ Добавить», чтобы внести первый контакт.</div>
                <div v-for="e in timeline" :key="e.id" class="comm-item">
                    <div class="comm-dot" :class="(COMM_TYPES[e.type] ?? COMM_TYPES.note).dot">{{ (COMM_TYPES[e.type] ?? COMM_TYPES.note).icon }}</div>
                    <div class="comm-body">
                        <div class="comm-header">
                            <span class="comm-type">{{ (COMM_TYPES[e.type] ?? COMM_TYPES.note).label }}</span>
                            <span class="comm-date">{{ fmtDate(e.happened_on) }}</span>
                            <span class="comm-mgr">· {{ e.manager || '—' }}</span>
                        </div>
                        <div class="comm-text">{{ e.text }}</div>
                    </div>
                    <button class="comm-delete-btn" title="Удалить" @click="deleteCommEntry(e)">✕</button>
                </div>
            </div>
        </div>

        <div class="crm-popover-actions">
            <button class="btn btn-ghost btn-sm" style="flex: 1; font-size: 11px" @click="edit">✏️ Редактировать</button>
            <button class="btn btn-primary btn-sm" style="flex: 1; font-size: 11px" @click="next">→ Следующий этап</button>
        </div>
    </div>
</template>
