<script setup lang="ts">
// Tab «Лиды» of CRM — legacy orders-leads (L4321–4373), setLeadsPeriod/setLeadsView/onLeadsStatusFilterChange/renderLeadsTab
// (L31476–31683). Same filters, cards, table and counts. Legacy loads only active leads until «Все статусы», «Выиграли» or
// «Проиграли» is chosen, which shows in «Показано: N из M»; that is kept. Status changes, «→ Клиент» and the form are saved.
import { computed, reactive, ref } from 'vue';
import LeadModal from '@/components/crm/LeadModal.vue';
import { convertLeadToClient, createdLabel, quickChangeLeadStatus, type LeadRow, type LeadStatus } from '@/lib/crm';

const props = defineProps<{ leads: LeadRow[]; statuses: Record<string, LeadStatus>; sources: Record<string, string>; currentYear: number }>();

// The first opening of the tab sets «Текущий год» (legacy setLeadsPeriod('year')); the date fields show only for «Период...».
const f = reactive({ search: '', period: 'year', from: `${props.currentYear}-01-01`, to: `${props.currentYear}-12-31`, status: 'active', source: 'all', budgetMin: '' });
const view = ref<'cards' | 'table'>('cards');
const allLoaded = ref(false);

function setLeadsPeriod(mode: string): void {
    if (mode === 'year') Object.assign(f, { from: `${new Date().getFullYear()}-01-01`, to: `${new Date().getFullYear()}-12-31` });
    else if (mode === 'all') Object.assign(f, { from: '', to: '' });
}
function onStatusFilterChange(): void {
    if (['won', 'lost', 'all'].includes(f.status)) allLoaded.value = true;
}

const isOpen = (l: LeadRow) => l.status !== 'won' && l.status !== 'lost';
const loaded = computed(() => (allLoaded.value ? props.leads : props.leads.filter(isOpen)));
const shown = computed(() => {
    const search = f.search.toLowerCase();
    const budgetMin = parseFloat(String(f.budgetMin)) || 0;
    return loaded.value.filter((l) => {
        if (search && !(l.name || '').toLowerCase().includes(search) && !(l.phone || '').includes(search) && !(l.interest || '').toLowerCase().includes(search) && !(l.comment || '').toLowerCase().includes(search))
            return false;
        if (f.status === 'active' && !isOpen(l)) return false;
        if (!['active', 'all', ''].includes(f.status) && l.status !== f.status) return false;
        if (f.source !== 'all' && l.source !== f.source) return false;
        if (budgetMin > 0 && (l.budget || 0) < budgetMin) return false;
        if (f.from || f.to) {
            const created = createdLabel(l);
            if (!created) return !f.from;
            const d = created.split('.').reverse().join('-');
            if (f.from && d < f.from) return false;
            if (f.to && d > f.to) return false;
        }
        return true;
    });
});
const status = (l: LeadRow) => props.statuses[l.status] ?? props.statuses.new;
const fmt = (n: number) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
const modal = ref<InstanceType<typeof LeadModal> | null>(null);

defineExpose({ activeCount: computed(() => props.leads.filter(isOpen).length) });
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center">
        <input id="leads-search" v-model="f.search" type="search" placeholder="🔍 Имя, телефон, интерес..." style="font-size: 13px; flex: 1; min-width: 180px; max-width: 260px" />
        <!-- Период -->
        <select id="leads-period" v-model="f.period" style="font-size: 13px" @change="setLeadsPeriod(f.period)">
            <option value="year">Текущий год</option>
            <option value="all">Все время</option>
            <option value="custom">Период...</option>
        </select>
        <div id="leads-period-dates" style="gap: 4px; align-items: center" :style="{ display: f.period === 'custom' ? 'flex' : 'none' }">
            <input id="leads-date-from" v-model="f.from" type="date" style="font-size: 12px" />
            <span style="color: var(--text3)">—</span>
            <input id="leads-date-to" v-model="f.to" type="date" style="font-size: 12px" />
        </div>
        <select id="leads-status-filter" v-model="f.status" style="font-size: 13px" @change="onStatusFilterChange">
            <option value="active">Активные лиды</option>
            <option value="all">Все статусы</option>
            <option value="new">🆕 Новый</option>
            <option value="contact">📞 Контакт</option>
            <option value="meeting">🤝 Встреча</option>
            <option value="proposal">📄 КП отправлено</option>
            <option value="won">✅ Выиграли</option>
            <option value="lost">❌ Проиграли</option>
        </select>
        <select id="leads-source-filter" v-model="f.source" style="font-size: 13px">
            <option value="all">Все источники</option>
            <option v-for="(label, key) in props.sources" :key="key" :value="key">{{ label }}</option>
        </select>
        <input id="leads-budget-min" v-model="f.budgetMin" type="number" placeholder="Бюджет от ₸" style="font-size: 13px; width: 120px" />
        <div style="display: flex; border: 1px solid var(--border); border-radius: 6px; overflow: hidden">
            <button
                id="leads-view-cards"
                style="padding: 5px 10px; font-size: 13px; border: none; cursor: pointer"
                :style="view === 'cards' ? 'background:var(--accent);color:#fff' : 'background:var(--bg2);color:var(--text2)'"
                @click="view = 'cards'"
            >
                ⊞ Плитка
            </button>
            <button
                id="leads-view-table"
                style="padding: 5px 10px; font-size: 13px; border: none; cursor: pointer"
                :style="view === 'table' ? 'background:var(--accent);color:#fff' : 'background:var(--bg2);color:var(--text2)'"
                @click="view = 'table'"
            >
                ☰ Таблица
            </button>
        </div>
        <button class="btn btn-primary btn-sm" @click="modal?.openLeadModal(null)">+ Новый лид</button>
        <span id="leads-count-label" style="font-size: 12px; color: var(--text3)">Показано: {{ shown.length }} из {{ loaded.length }}</span>
    </div>
    <div id="leads-body">
        <div v-if="!shown.length" style="text-align: center; padding: 40px; color: var(--text3)">
            {{ loaded.length === 0 ? 'Лидов пока нет. Нажмите «+ Новый лид».' : 'Ничего не найдено по фильтрам.' }}
        </div>
        <div v-else-if="view === 'table'" class="card" style="padding: 0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Имя</th>
                        <th>Телефон</th>
                        <th>Статус</th>
                        <th>Интерес</th>
                        <th style="text-align: right">Бюджет</th>
                        <th>Источник</th>
                        <th>Дата</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in shown" :key="l.id" v-memo="[l]">
                        <td style="font-weight: 600">{{ l.name }}</td>
                        <td style="font-family: monospace; font-size: 12px">{{ l.phone || '—' }}</td>
                        <td>
                            <span style="font-size: 11px; padding: 2px 8px; border-radius: 10px" :style="{ background: status(l).color + '22', color: status(l).color }">{{ status(l).label }}</span>
                        </td>
                        <td style="font-size: 13px; color: var(--text2)">{{ l.interest || '—' }}</td>
                        <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(l.budget) }}</td>
                        <td style="font-size: 12px; color: var(--text3)">{{ (l.source && props.sources[l.source]) || l.source || '—' }}</td>
                        <td style="font-size: 12px; color: var(--text3)">{{ createdLabel(l) || '—' }}</td>
                        <td style="display: flex; gap: 4px; align-items: center">
                            <select
                                :value="l.status"
                                style="font-size: 11px; padding: 2px 4px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg2); color: var(--text1); cursor: pointer"
                                @change="quickChangeLeadStatus(l, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = l.status; ($event.target as HTMLSelectElement).blur()"
                            >
                                <option v-for="(s, key) in props.statuses" :key="key" :value="key">{{ s.label }}</option>
                            </select>
                            <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="modal?.openLeadModal(l)">✏️</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 12px">
            <div v-for="l in shown" :key="l.id" v-memo="[l]" class="card" style="padding: 0; overflow: hidden">
                <div style="padding: 12px 14px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center">
                    <div style="font-weight: 600">{{ l.name }}</div>
                    <span style="font-size: 11px; padding: 2px 8px; border-radius: 10px" :style="{ background: status(l).color + '22', color: status(l).color }">{{ status(l).label }}</span>
                </div>
                <div style="padding: 12px 14px; font-size: 13px">
                    <div v-if="l.phone" style="margin-bottom: 4px">📞 {{ l.phone }}</div>
                    <div v-if="l.interest" style="margin-bottom: 4px; color: var(--text2)">💡 {{ l.interest }}</div>
                    <div v-if="l.budget" style="margin-bottom: 4px; color: var(--green)">💰 {{ fmt(l.budget) }}</div>
                    <div v-if="l.source" style="font-size: 11px; color: var(--text3)">📍 {{ props.sources[l.source] || l.source }}</div>
                    <div v-if="l.comment" style="font-size: 12px; color: var(--text3); margin-top: 4px">💬 {{ l.comment }}</div>
                </div>
                <div style="padding: 8px 14px; border-top: 1px solid var(--border); display: flex; gap: 6px; justify-content: flex-end">
                    <button class="btn btn-ghost btn-sm" @click="modal?.openLeadModal(l)">✏️ Изменить</button>
                    <button v-if="l.status !== 'won' && l.status !== 'lost'" class="btn btn-primary btn-sm" @click="convertLeadToClient(l)">→ Клиент</button>
                </div>
            </div>
        </div>
    </div>
    <LeadModal ref="modal" />
</template>
