<script setup lang="ts">
// Tab «Воронка лидов» of CRM — legacy orders-funnel (L4182–4205), renderCrmFunnelSummary/populateCrmMgrFilter/renderCrmKanban/
// renderCrmFilterBars/crmDragStart/crmDrop (L28867–29120). Legacy calls CRM_STAGES, which it never defines, so its board
// fails as soon as one lead is in the funnel; the columns here are the statuses its lead form marks «→ воронка»
// (config erp.funnel_stages). The segment filter compares the id as a number (legacy compared a string with a number and
// always emptied the board). The segment is the lead's own (legacy showed «Новый» on every card), «Сумма воронки» adds the
// budgets (legacy added a field its leads never have). Moves, «Выиграли» and «Отказ» are saved.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FunnelLeadModal from '@/components/crm/FunnelLeadModal.vue';
import { changeStatus, crmFmtAmount, leadLost, leadWon, TASKS_LATER, type FunnelStage, type LeadRow, type Segment } from '@/lib/crm';

const props = defineProps<{ leads: LeadRow[]; stages: FunnelStage[]; segments: Segment[]; sources: Record<string, string>; managers: string[]; active: boolean }>();

const stageOf = (l: LeadRow) => props.stages.find((s) => s.statuses.includes(l.status))?.key ?? null;
const funnel = computed(() => props.leads.filter((l) => stageOf(l) !== null));

const manager = ref('');
const segmentFilter = ref<number | null>(null);
const tagFilter = ref<string | null>(null);
const managers = computed(() => [...new Set(funnel.value.map((l) => l.manager).filter((m): m is string => !!m))].sort());
const tags = computed(() => [...new Set(funnel.value.flatMap((l) => l.tags || []))].sort());
const byManager = computed(() => (manager.value ? funnel.value.filter((l) => l.manager === manager.value) : funnel.value));
const leads = computed(() =>
    byManager.value.filter((l) => (!segmentFilter.value || l.segment === segmentFilter.value) && (!tagFilter.value || (l.tags || []).includes(tagFilter.value))),
);

// Legacy renderCrmFunnelSummary: leads of the funnel are «в работе»; won leads leave it, so «Закрыто сделок» stays 0 there.
const summary = computed(() => {
    const total = byManager.value.reduce((s, l) => s + (l.budget || 0), 0);
    return [
        { label: 'Всего лидов', value: String(byManager.value.length), color: '' },
        { label: 'В работе', value: String(byManager.value.length), color: '' },
        { label: 'Закрыто сделок', value: '0', color: 'var(--green)' },
        { label: 'Сумма воронки', value: crmFmtAmount(total), color: '' },
        { label: 'Выиграно', value: crmFmtAmount(0), color: 'var(--green)' },
        { label: 'Конверсия', value: '0%', color: 'var(--red)' },
    ];
});

const segment = (id: number | null) => props.segments.find((s) => s.id === id);
const overdue = (l: LeadRow) => {
    if (!l.next_date) return false;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return new Date(l.next_date) < today;
};
const nextLabel = (l: LeadRow) => (l.next_date ? new Date(l.next_date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) : '');

// Row heights follow the tallest cell of the row (legacy renderCrmKanban, requestAnimationFrame); a hidden tab has no
// heights, so they are taken again when it is shown, and again when a font the cards use for the first time has arrived.
const board = ref<HTMLElement | null>(null);
function syncHeights(): void {
    if (!board.value || !props.active) return;
    const labels = [...board.value.querySelectorAll<HTMLElement>('.crm-row-label')];
    const cols = [...board.value.querySelectorAll<HTMLElement>('.crm-col')].map((col) => [...col.querySelectorAll<HTMLElement>('.crm-cell')]);
    [...labels, ...cols.flat()].forEach((el) => (el.style.minHeight = ''));
    labels.forEach((lbl, i) => {
        const cells = cols.map((col) => col[i]).filter(Boolean);
        const maxH = Math.max(lbl.scrollHeight, ...cells.map((c) => c.scrollHeight), 90);
        lbl.style.minHeight = maxH + 'px';
        cells.forEach((c) => (c.style.minHeight = maxH + 'px'));
    });
}
watch([leads, () => props.active], () => nextTick(syncHeights), { immediate: true, flush: 'post' });
onMounted(() => document.fonts.addEventListener('loadingdone', syncHeights));
onBeforeUnmount(() => document.fonts.removeEventListener('loadingdone', syncHeights));

// ── Drag & drop (crmDragStart / crmDrop) ──
const dragId = ref<number | null>(null);
const over = ref('');
function onDragStart(ev: DragEvent, lead: LeadRow): void {
    dragId.value = lead.id;
    if (ev.dataTransfer) ev.dataTransfer.effectAllowed = 'move';
}
function onDrop(stageKey: string): void {
    over.value = '';
    const lead = props.leads.find((l) => l.id === dragId.value);
    dragId.value = null;
    if (!lead || stageOf(lead) === stageKey) return;
    changeStatus(lead, stageKey);
}

const modal = ref<InstanceType<typeof FunnelLeadModal> | null>(null);
const task = () => window.alert(TASKS_LATER);
</script>

<template>
    <div class="section-header" style="margin-bottom: 10px">
        <div style="font-size: 13px; color: var(--text3)">Перетащите клиента на следующую стадию. Клик «⋯» — детали, история, теги.</div>
        <div style="display: flex; gap: 8px">
            <select id="crm-mgr-filter" v-model="manager" style="font-size: 13px; width: auto">
                <option value="">Все менеджеры</option>
                <option v-for="m in managers" :key="m" :value="m">{{ m }}</option>
            </select>
            <button class="btn btn-primary btn-sm" @click="modal?.openCrmLeadModal(null)">+ Новый лид</button>
        </div>
    </div>

    <!-- Segment filter pills -->
    <div id="crm-segment-filter-bar" class="tags-filter-bar">
        <span style="font-size: 11px; color: var(--text3); white-space: nowrap">Сегмент:</span>
        <button class="crm-segment-filter-btn" :class="{ active: !segmentFilter }" @click="segmentFilter = null">Все</button>
        <button
            v-for="s in props.segments"
            :key="s.id"
            class="crm-segment-filter-btn"
            :class="{ active: segmentFilter === s.id }"
            :style="segmentFilter === s.id ? `border-color:${s.color};color:${s.color};background:${s.color}22` : ''"
            @click="segmentFilter = s.id"
        >
            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle" :style="{ background: s.color }"></span>{{ s.label }}
        </button>
    </div>
    <!-- Tag filter pills -->
    <div id="crm-tag-filter-bar" class="tags-filter-bar">
        <span v-if="!tags.length" style="font-size: 11px; color: var(--text3)">Тег: нет тегов</span>
        <template v-else>
            <span style="font-size: 11px; color: var(--text3); white-space: nowrap">Тег:</span>
            <span class="tag-chip clickable" :class="{ 'active-filter': !tagFilter }" @click="tagFilter = null">Все</span>
            <span v-for="t in tags" :key="t" class="tag-chip clickable" :class="{ 'active-filter': tagFilter === t }" @click="tagFilter = t">🏷 {{ t }}</span>
        </template>
    </div>

    <div id="crm-funnel-summary" class="crm-funnel-summary">
        <div v-for="k in summary" :key="k.label" class="crm-funnel-kpi">
            <div class="crm-funnel-kpi-label">{{ k.label }}</div>
            <div class="crm-funnel-kpi-value" :style="k.color ? { color: k.color } : undefined">{{ k.value }}</div>
        </div>
    </div>
    <div style="overflow: auto; max-height: 65vh">
        <div id="crm-kanban-board" ref="board">
            <div v-if="!leads.length" style="padding: 40px; text-align: center; color: var(--text3); font-size: 14px">
                <div style="font-size: 32px; margin-bottom: 12px">🎯</div>
                Нет лидов в воронке.<br />
                <span style="font-size: 12px">Переведите лидов из справочника в статус<br />«Квалификация», «КП отправлено» или «Переговоры»</span>
            </div>
            <div v-else class="crm-pipeline">
                <!-- Row-label sticky column -->
                <div class="crm-row-labels">
                    <div class="crm-row-label-header">Клиент</div>
                    <div v-for="l in leads" :key="l.id" class="crm-row-label">
                        <div class="crm-row-label-name" title="Открыть карточку" @click="modal?.openCrmLeadModal(l)">{{ l.name }}</div>
                        <div class="crm-row-label-meta">{{ l.phone || '—' }}</div>
                        <div v-if="overdue(l)" style="font-size: 10px; color: var(--red)">⚠ просроченное действие</div>
                        <button
                            title="Создать задачу по лиду"
                            style="margin-top: 3px; font-size: 10px; padding: 2px 6px; background: rgba(79, 142, 247, 0.1); border: 1px solid var(--accent); border-radius: 4px; cursor: pointer; color: var(--accent)"
                            @click="task"
                        >
                            📋 Задача
                        </button>
                    </div>
                </div>
                <!-- Stage columns -->
                <div v-for="stage in props.stages" :key="stage.key" class="crm-col">
                    <div class="crm-col-header">
                        {{ stage.label }}
                        <span class="crm-col-count">{{ leads.filter((l) => stageOf(l) === stage.key).length }} лид.</span>
                    </div>
                    <div
                        v-for="l in leads"
                        :key="l.id"
                        class="crm-cell"
                        :class="{ 'crm-dragover': over === `${l.id}-${stage.key}` }"
                        @dragover.prevent="over = `${l.id}-${stage.key}`"
                        @dragleave="over = ''"
                        @drop.prevent="onDrop(stage.key)"
                    >
                        <div v-if="stageOf(l) === stage.key" class="crm-card" draggable="true" @dragstart="onDragStart($event, l)">
                            <div v-if="segment(l.segment)" style="margin-bottom: 4px">
                                <span class="segment-badge" :style="{ background: segment(l.segment)!.color }">{{ segment(l.segment)!.label }}</span>
                            </div>
                            <div class="crm-card-name">{{ l.name }}</div>
                            <div class="crm-card-amount">{{ crmFmtAmount(l.budget || 0) }}</div>
                            <div class="crm-card-mgr">👤 {{ l.manager || '—' }}</div>
                            <div v-if="l.interest" style="font-size: 11px; color: var(--text3); margin-top: 2px">📦 {{ l.interest.substring(0, 30) }}</div>
                            <div v-if="nextLabel(l)" style="font-size: 10px; color: var(--text3); margin-top: 3px">
                                📅 {{ nextLabel(l) }}{{ l.next_action ? ' · ' + l.next_action.substring(0, 18) + (l.next_action.length > 18 ? '…' : '') : '' }}
                            </div>
                            <div v-if="l.tags && l.tags.length" style="display: flex; flex-wrap: wrap; gap: 3px; margin-top: 4px">
                                <span v-for="t in l.tags" :key="t" class="tag-chip" style="font-size: 10px; padding: 1px 6px">🏷 {{ t }}</span>
                            </div>
                            <div style="display: flex; gap: 4px; margin-top: 8px">
                                <button
                                    style="flex: 1; font-size: 11px; padding: 3px 0; background: rgba(61, 214, 140, 0.15); border: 1px solid var(--green); border-radius: 5px; cursor: pointer; color: var(--green); font-weight: 600"
                                    @click.stop="leadWon(l)"
                                >
                                    ✅ Выиграли
                                </button>
                                <button
                                    style="flex: 1; font-size: 11px; padding: 3px 0; background: rgba(249, 70, 70, 0.08); border: 1px solid var(--red); border-radius: 5px; cursor: pointer; color: var(--red)"
                                    @click.stop="leadLost(l)"
                                >
                                    ❌ Отказ
                                </button>
                                <button
                                    style="font-size: 11px; padding: 3px 6px; background: rgba(79, 142, 247, 0.1); border: 1px solid var(--accent); border-radius: 5px; cursor: pointer; color: var(--accent)"
                                    title="Создать задачу"
                                    @click.stop="task"
                                >
                                    📋
                                </button>
                            </div>
                            <button class="crm-card-detail-btn" title="Детали лида" @click.stop="modal?.openCrmLeadModal(l)">⋯</button>
                        </div>
                        <div v-else style="height: 4px"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <FunnelLeadModal ref="modal" :segments="props.segments" :sources="props.sources" :managers="props.managers" />
</template>
