<script setup lang="ts">
// Tab «Загрузка цехов» of «Производство» — legacy renderWorkshopLoad (L19752–19820): every line with a stage not posted yet, by
// workshop and by urgency of its date — overdue, due within 7 days, in time, without a date. «Today» is the business date (legacy
// took the date in UTC: in Almaty a day early before 05:00 — ADR-0039).
import { computed } from 'vue';
import PosBadge from '@/components/PosBadge.vue';
import { PIECE_WORKSHOPS, type WorkData } from './work';

const props = defineProps<{ data: WorkData | undefined; today: string }>();

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const load = computed(() => {
    const [y, m, d] = props.today.split('-').map(Number);
    const week = iso(new Date(y, m - 1, d + 7));
    const pending = (props.data?.items ?? [])
        .map((item) => {
            const open = item.stages.filter((s) => !s.posted);
            if (!open.length) return null;
            const workshops = [...new Set(open.map((s) => s.workshop).filter(Boolean))];
            const dl = item.deadline || '';
            let urgency = 'none';
            let label = 'Без срока';
            let cls = 'badge-blue';
            if (dl) {
                if (dl < props.today) [urgency, label, cls] = ['overdue', 'Просрочено', 'badge-red'];
                else if (dl <= week) [urgency, label, cls] = ['soon', 'Горит (7 дней)', 'badge-amber'];
                else [urgency, label, cls] = ['ok', 'В порядке', 'badge-green'];
            }
            return { item, workshops, urgency, label, cls };
        })
        .filter((p) => p !== null);
    const by = PIECE_WORKSHOPS.map((w) => ({ ...w, total: 0, overdue: 0, soon: 0 }));
    pending.forEach((p) =>
        p.workshops.forEach((wid) => {
            const w = by.find((x) => x.id === wid);
            if (!w) return;
            w.total++;
            if (p.urgency === 'overdue') w.overdue++;
            if (p.urgency === 'soon') w.soon++;
        }),
    );
    const order: Record<string, number> = { overdue: 0, soon: 1, ok: 2, none: 3 };
    return { by, rows: [...pending].sort((a, b) => order[a.urgency] - order[b.urgency]) };
});
const wsName = (id: string) => PIECE_WORKSHOPS.find((w) => w.id === id)?.name || id;
</script>

<template>
    <div class="section-sub" style="margin-bottom: 14px">Незавершённые этапы (не проведённые) по цехам — только то, что реально назначено на Рабочем листе производства</div>
    <div id="workload-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 20px">
        <template v-if="data !== undefined">
            <div v-for="w in load.by" :key="w.id" class="card" style="padding: 14px 16px">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px">
                    <span style="font-size: 13px; font-weight: 600">{{ w.icon }} {{ w.name }}</span>
                    <span style="font-size: 22px; font-weight: 700; color: var(--accent)">{{ w.total }}</span>
                </div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap">
                    <span v-if="w.overdue" class="badge badge-red" style="font-size: 10px">{{ w.overdue }} просрочено</span>
                    <span v-if="w.soon" class="badge badge-amber" style="font-size: 10px">{{ w.soon }} горит</span>
                    <span v-if="!w.overdue && !w.soon && w.total > 0" class="badge badge-green" style="font-size: 10px">Всё в порядке</span>
                    <span v-if="w.total === 0" style="font-size: 11px; color: var(--text3)">Пусто</span>
                </div>
            </div>
        </template>
    </div>

    <div class="section-sub" style="margin-bottom: 10px">Все незавершённые позиции по срочности отправки</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Срочность</th>
                <th>Заказ</th>
                <th>Товар</th>
                <th>Цех(а)</th>
                <th>Срок</th>
            </tr>
        </thead>
        <tbody id="workload-tbody">
            <template v-if="data !== undefined">
                <tr v-for="(p, i) in load.rows" :key="i">
                    <td>
                        <span class="badge" :class="p.cls" style="font-size: 11px">{{ p.label }}</span>
                    </td>
                    <td style="font-family: monospace; color: var(--accent)">{{ p.item.num }}</td>
                    <td><PosBadge :n="p.item.pos" />{{ p.item.name }}</td>
                    <td>{{ p.workshops.map(wsName).join(', ') || '—' }}</td>
                    <td>{{ p.item.deadline ? p.item.date : '—' }}</td>
                </tr>
                <tr v-if="!load.rows.length">
                    <td colspan="5" style="text-align: center; color: var(--text3); padding: 20px">Нет незавершённых этапов</td>
                </tr>
            </template>
        </tbody>
    </table>
</template>
