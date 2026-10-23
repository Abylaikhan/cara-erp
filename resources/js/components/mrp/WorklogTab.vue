<script setup lang="ts">
// Tab «Журнал работ» of «Производство» — legacy collectWorkLogEntries/renderWorkLog (L19627–19730): one line per posted stage
// and executor, for the chosen month, with the filters of legacy. The month list of legacy was the text of a template (only one
// option «${m}», so only the current month could be seen); here it has the twelve months (ADR-0039). «📥 Excel» comes with the
// exports (ERP-067).
import { computed, reactive } from 'vue';
import { PL_MONTH_LABELS } from '@/lib/sales';
import { PIECE_WORKSHOPS, type WorkData } from './work';

const props = defineProps<{ data: WorkData | undefined; today: string }>();
const later = () => window.alert('Выгрузка в Excel появится вместе с остальными выгрузками (ERP-067).');

const entries = computed(() =>
    (props.data?.items ?? []).flatMap((item) =>
        item.stages
            .filter((s) => s.posted && s.postedDate)
            .flatMap((s) =>
                (s.assignees.length ? s.assignees : [{ name: '—', ktu: 1 }]).map((a) => ({
                    date: s.postedDate,
                    workshop: s.workshop || '',
                    exec: a.name,
                    orderNum: item.num,
                    itemName: '№' + item.pos + ' · ' + item.name,
                    category: item.category || '—',
                    stage: s.stage || '(без названия)',
                })),
            ),
    ),
);
const f = reactive({ month: String(+props.today.slice(5, 7)), year: props.today.slice(0, 4), ws: '', exec: '', order: '', cat: '', item: '', stage: '' });
const years = computed(() => {
    const ys = [...new Set(entries.value.map((e) => e.date.slice(0, 4)))].sort().reverse();
    if (!ys.includes(props.today.slice(0, 4))) ys.unshift(props.today.slice(0, 4));
    return ys;
});
const cats = computed(() => [...new Set(entries.value.map((e) => e.category).filter(Boolean))].sort());
const stages = computed(() => [...new Set(entries.value.map((e) => e.stage).filter(Boolean))].sort());
const rows = computed(() => {
    const prefix = `${f.year}-${String(f.month).padStart(2, '0')}`;
    const order = f.order.toLowerCase();
    const item = f.item.toLowerCase();
    return entries.value
        .filter(
            (e) =>
                e.date.startsWith(prefix) &&
                (!f.ws || e.workshop === f.ws) &&
                (!f.exec || e.exec === f.exec) &&
                (!order || e.orderNum.toLowerCase().includes(order)) &&
                (!f.cat || e.category === f.cat) &&
                (!item || e.itemName.toLowerCase().includes(item)) &&
                (!f.stage || e.stage === f.stage),
        )
        .sort((a, b) => a.date.localeCompare(b.date));
});
const ws = (id: string) => PIECE_WORKSHOPS.find((x) => x.id === id);
</script>

<template>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
        <select id="wl-month" v-model="f.month" style="font-size: 12px; width: auto">
            <option v-for="(m, i) in PL_MONTH_LABELS" :key="m" :value="String(i + 1)">{{ m }}</option>
        </select>
        <select id="wl-year" v-model="f.year" style="font-size: 12px; width: auto">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <select id="wl-workshop" v-model="f.ws" style="font-size: 12px; width: auto">
            <option value="">Все цеха</option>
            <option v-for="w in PIECE_WORKSHOPS" :key="w.id" :value="w.id">{{ w.icon }} {{ w.name }}</option>
        </select>
        <select id="wl-exec" v-model="f.exec" style="font-size: 12px; width: auto">
            <option value="">Все исполнители</option>
            <option v-for="n in data?.execs ?? []" :key="n" :value="n">{{ n }}</option>
        </select>
        <input id="wl-order" v-model="f.order" type="text" placeholder="№ заказа..." style="width: 100px; font-size: 12px" />
        <select id="wl-category" v-model="f.cat" style="font-size: 12px; width: auto">
            <option value="">Все категории</option>
            <option v-for="c in cats" :key="c" :value="c">{{ c }}</option>
        </select>
        <input id="wl-item" v-model="f.item" type="text" placeholder="Изделие..." style="width: 130px; font-size: 12px" />
        <select id="wl-stage" v-model="f.stage" style="font-size: 12px; width: auto">
            <option value="">Все работы</option>
            <option v-for="s in stages" :key="s" :value="s">{{ s }}</option>
        </select>
        <button class="btn btn-ghost btn-sm" @click="later">📥 Excel</button>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Дата</th>
                <th>Цех</th>
                <th>Исполнитель</th>
                <th>Заказ</th>
                <th>Изделие</th>
                <th>Категория</th>
                <th>Работа</th>
            </tr>
        </thead>
        <tbody id="wl-tbody">
            <template v-if="data !== undefined">
                <tr v-for="(e, i) in rows" :key="i">
                    <td style="font-family: monospace; font-size: 12px">{{ e.date.split('-').reverse().join('.') }}</td>
                    <td style="font-size: 12px">{{ ws(e.workshop) ? ws(e.workshop)!.icon + ' ' + ws(e.workshop)!.name : '—' }}</td>
                    <td style="font-size: 12px">{{ e.exec }}</td>
                    <td style="font-family: monospace; color: var(--accent)">{{ e.orderNum }}</td>
                    <td>{{ e.itemName }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ e.category }}</td>
                    <td style="font-size: 12px">{{ e.stage }}</td>
                </tr>
                <tr v-if="!rows.length">
                    <td colspan="7" style="text-align: center; color: var(--text3); padding: 24px">Нет проведённых работ за выбранный период</td>
                </tr>
            </template>
        </tbody>
    </table>
</template>
