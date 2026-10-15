<script setup lang="ts">
// Tab «📐 Проекты» of CRM — legacy orders-projects (c917d37 L4265–4281), prjOpenTab/prjFillExecFilter/renderProjects
// (L31180–31252). Requests for a calculation of non-standard furniture: search, status (open by default), customer and executor
// filters, status chips, the table. The customer (a salon or dealer account) sees only its own projects and neither the customer
// nor the executor filter. The projects are loaded when the tab is first opened; the card is ProjectModal.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ProjectModal from '@/components/crm/ProjectModal.vue';
import { badgeStyle, isOpen, PRJ_STATUSES, prjDate, prjFmt, toast, type ProjectMeta, type ProjectRow } from '@/lib/projects';

const props = defineProps<{ projects: ProjectRow[] | undefined; meta: ProjectMeta; timezone: string; active: boolean }>();

watch(
    () => props.active,
    (active) => {
        if (active && props.projects === undefined) router.reload({ only: ['projects'] });
    },
    { immediate: true },
);

const list = computed(() => props.projects ?? []);
const chName = (code: string) => props.meta.channels.find((c) => c.code === code)?.name ?? code ?? '—';
const execNames = (p: ProjectRow) => (p.executors.length ? p.executors.map((e) => e.name) : p.responsible_name ? [p.responsible_name] : []);

const f = reactive({ q: '', st: 'open', ch: '', ex: '' });
const counts = computed(() => {
    const c: Record<string, number> = {};
    list.value.forEach((p) => (c[p.status] = (c[p.status] || 0) + 1));
    return c;
});

// Legacy prjFillExecFilter: staff users and everyone named in a project, the busiest first (open projects).
const staffName = (login: string) => props.meta.staff.find((s) => s.login === login)?.name;
const execOptions = computed(() => {
    const names = new Map<string, string>();
    props.meta.staff.forEach((s) => names.set(s.login, s.name));
    list.value.forEach((p) => p.executors.forEach((e) => names.has(e.login) || names.set(e.login, staffName(e.login) ?? e.name)));
    const cnt: Record<string, number> = {};
    list.value.filter((p) => isOpen(p.status)).forEach((p) => p.executors.forEach((e) => (cnt[e.login] = (cnt[e.login] || 0) + 1)));
    return [...names.keys()]
        .sort((a, b) => (cnt[b] || 0) - (cnt[a] || 0) || names.get(a)!.localeCompare(names.get(b)!, 'ru'))
        .map((l) => ({ login: l, label: names.get(l)! + (cnt[l] ? ' (' + cnt[l] + ')' : '') }));
});

const rows = computed(() => {
    const q = f.q.toLowerCase().trim();
    return list.value.filter((p) => {
        if (f.st === 'open' && !isOpen(p.status)) return false;
        if (f.st !== 'open' && f.st !== 'all' && p.status !== f.st) return false;
        if (f.ch && p.channel !== f.ch) return false;
        if (f.ex) {
            const l = p.executors.map((e) => e.login);
            if (f.ex === '__me__') {
                if (!l.includes(props.meta.login)) return false;
            } else if (f.ex === '__none__') {
                if (l.length || (p.responsible_name || '').trim()) return false;
            } else if (!l.includes(f.ex)) return false;
        }
        if (q && !((p.num || '') + ' ' + (p.title || '') + ' ' + (p.customer_name || '') + ' ' + (p.responsible_name || '') + ' ' + (p.order_num || '')).toLowerCase().includes(q))
            return false;
        return true;
    });
});

const modal = ref<InstanceType<typeof ProjectModal> | null>(null);
defineExpose({ openCount: computed(() => list.value.filter((p) => isOpen(p.status)).length) });
</script>

<template>
    <div class="section-header" style="margin-bottom: 12px">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap">
            <input id="prj-q" v-model="f.q" type="search" placeholder="🔍 Поиск: номер, название, заказчик…" style="font-size: 13px; min-width: 230px" />
            <select id="prj-st" v-model="f.st" style="font-size: 13px; width: auto">
                <option value="open">Активные</option>
                <option value="all">Все</option>
                <option value="request">Запрос</option>
                <option value="calc">В расчёте</option>
                <option value="sent">Отправлен заказчику</option>
                <option value="approved">Согласован</option>
                <option value="order">Стал заказом</option>
                <option value="closed">Закрыт</option>
            </select>
            <select id="prj-ch" v-model="f.ch" style="font-size: 13px; width: auto" :style="props.meta.client ? { display: 'none' } : undefined">
                <option value="">Все заказчики</option>
                <option v-for="c in props.meta.channels.filter((c) => c.group !== 'direct')" :key="c.code" :value="c.code">{{ c.name }}</option>
                <option value="office">Офис (прямые)</option>
            </select>
            <select id="prj-ex" v-model="f.ex" style="font-size: 13px; width: auto" title="Фильтр по исполнителю" :style="props.meta.client ? { display: 'none' } : undefined">
                <option value="">Все исполнители</option>
                <option value="__me__">👤 Мои проекты</option>
                <option value="__none__">— не назначен —</option>
                <option v-for="o in execOptions" :key="o.login" :value="o.login">{{ o.label }}</option>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" @click="modal?.prjOpenNew()">+ Новый проект</button>
    </div>
    <div id="prj-chips" style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px">
        <template v-if="props.projects !== undefined">
            <span
                v-for="(d, k) in PRJ_STATUSES"
                :key="k"
                :style="{ cursor: 'pointer', padding: '4px 10px', borderRadius: '14px', fontSize: '12px', background: d.color + '22', color: d.color, fontWeight: '600' }"
                @click="f.st = k"
                >{{ d.label }}: {{ counts[k] || 0 }}</span
            >
        </template>
    </div>
    <div id="prj-list">
        <div v-if="props.projects === undefined" style="padding: 24px; color: var(--text3)">Загрузка…</div>
        <div v-else-if="!rows.length" style="padding: 30px; text-align: center; color: var(--text3)">Проектов нет. Нажмите «+ Новый проект».</div>
        <div v-else style="overflow-x: auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Проект</th>
                        <th v-if="!props.meta.client">Заказчик</th>
                        <th>Статус</th>
                        <th>Исполнители</th>
                        <th>Срок</th>
                        <th style="text-align: right">Сумма</th>
                        <th style="text-align: center">Файлы</th>
                        <th>Обновлён</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in rows" :key="p.id" style="cursor: pointer" @click="modal?.prjOpen(p.id)">
                        <td style="font-family: monospace">{{ p.num || '#' + p.id }}</td>
                        <td>
                            <b>{{ p.title }}</b>
                            <div v-if="p.order_num" style="font-size: 11px; color: var(--green)">Заказ {{ p.order_num }}</div>
                        </td>
                        <td v-if="!props.meta.client">{{ p.customer_name || chName(p.channel) }}</td>
                        <td>
                            <span :style="badgeStyle((PRJ_STATUSES[p.status] ?? { color: '#888' }).color)">{{ PRJ_STATUSES[p.status]?.label ?? p.status }}</span>
                        </td>
                        <td style="font-size: 12px">
                            <div v-for="(n, i) in execNames(p)" :key="i">👤 {{ n }}</div>
                            <span v-if="!execNames(p).length" style="color: var(--text3)">не назначен</span>
                        </td>
                        <td>{{ p.due_date || '' }}</td>
                        <td style="text-align: right; font-weight: 600">{{ Number(p.total_amount) ? prjFmt(p.total_amount) : '—' }}</td>
                        <td style="text-align: center; font-size: 12px">
                            {{ p.files.length ? '📎' + p.files.length : '' }}{{ ' ' }}{{ p.comments.length ? '💬' + p.comments.length : '' }}
                        </td>
                        <td style="font-size: 12px; color: var(--text3)">{{ prjDate(p.updated_at || p.created_at, props.timezone) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <ProjectModal ref="modal" :projects="list" :meta="props.meta" :timezone="props.timezone" />
    <div
        v-if="toast.text"
        id="prj-toast"
        :style="{
            position: 'fixed',
            left: '50%',
            bottom: '28px',
            transform: 'translateX(-50%)',
            zIndex: 99999,
            padding: '10px 18px',
            borderRadius: '8px',
            fontSize: '13px',
            color: '#fff',
            boxShadow: '0 4px 18px rgba(0,0,0,.4)',
            transition: 'opacity .3s',
            maxWidth: '90vw',
            background: toast.error ? '#d44444' : '#2ab573',
            opacity: toast.visible ? 1 : 0,
        }"
    >
        {{ toast.text }}
    </div>
</template>
