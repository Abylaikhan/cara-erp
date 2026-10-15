<script setup lang="ts">
// Project window — legacy prjModal (c917d37 L31254–31720): prjOpenNew/prjCreate (new project or, for the customer, «Запрос на
// расчёт»), prjRenderCard (head, executors, status, order number), prjCalcHtml and prjCalc* (calculation versions, markup,
// sending, printing), prjFilesCol/prjFileCard/prjUpload*/prjDrop/prjOnPaste/prjDeleteFile (files of both sides), the discussion
// (prjAddComment), prjClientDecide and prjDeleteProject. Same texts and layout; every action is checked and saved by the server.
// «Создать заказ из расчёта» waits for the order form (ERP-029).
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import {
    badgeStyle,
    firstError,
    PRJ_STATUSES,
    PROJECT_RELOAD,
    prjCompressImage,
    prjCostTotal,
    prjDate,
    prjFmt,
    prjToast,
    prjTotal,
    type CalcItem,
    type ProjectFileRow,
    type ProjectMeta,
    type ProjectRow,
} from '@/lib/projects';
import type { SharedProps } from '@/types';

const props = defineProps<{ projects: ProjectRow[]; meta: ProjectMeta; timezone: string }>();

const open = ref(false);
const mode = ref<'new' | 'card'>('card');
const curId = ref<number | null>(null);
const client = computed(() => props.meta.client);
const p = computed(() => props.projects.find((x) => x.id === curId.value) ?? null);
const chName = (code: string) => props.meta.channels.find((c) => c.code === code)?.name ?? code ?? '—';

function prjClose(): void {
    open.value = false;
    curId.value = null;
}

// ── New project (legacy prjOpenNew / prjCreate) ──
const ownChannels = computed(() => props.meta.channels.filter((c) => c.own));
const n = reactive({ title: '', channel: '', customer: '', desc: '', due: '', busy: false, label: 'Создать' });
const newFiles = ref<HTMLInputElement | null>(null);
function prjOpenNew(): void {
    Object.assign(n, { title: '', channel: ownChannels.value[0]?.code ?? '', customer: '', desc: '', due: '', busy: false, label: 'Создать' });
    if (newFiles.value) newFiles.value.value = '';
    curId.value = null;
    mode.value = 'new';
    open.value = true;
}
function prjCreate(): void {
    const title = n.title.trim();
    if (!title) return prjToast('Введите название проекта', true);
    const files = [...(newFiles.value?.files ?? [])];
    Object.assign(n, { busy: true, label: 'Сохраняю…' });
    router.post(
        '/crm/projects',
        { title, channel: client.value ? null : n.channel, customer_name: client.value ? null : n.customer, description: n.desc, due_date: n.due || null },
        {
            ...visit,
            onSuccess: async (pg) => {
                const id = (pg.props as unknown as SharedProps).flash.created;
                if (!id) return;
                if (files.length) {
                    n.label = 'Загружаю файлы…';
                    await uploadFiles(id, client.value ? 'customer' : 'designer', files, false).catch((e: Error) => prjToast(e.message, true));
                }
                prjToast('Проект создан');
                prjOpen(id);
            },
            onError: (errors) => {
                Object.assign(n, { busy: false, label: 'Создать' });
                prjToast('Ошибка: ' + firstError(errors), true);
            },
        },
    );
}

// ── Card (legacy prjOpen / prjRenderCard) ──
const curVer = ref<number | null>(null);
const edit = ref<CalcItem[]>([]);
const editKey = ref('');
const markup = ref('30');
const head = reactive({ title: '', desc: '', due: '', status: '', order: '' });
let headBase = '';
let headId: number | null = null;
let headLock = 0;

function prjOpen(id: number): void {
    curId.value = id;
    curVer.value = null;
    editKey.value = '';
    headId = null;
    mode.value = 'card';
    open.value = true;
}

const vis = computed(() => p.value?.calcs ?? []);
const cur = computed(() => vis.value.find((c) => c.version === curVer.value) ?? null);
const canHead = computed(() => !!p.value && (!client.value || p.value.status === 'request'));
const execNames = computed(() => (!p.value ? [] : p.value.executors.length ? p.value.executors.map((e) => e.name) : p.value.responsible_name ? [p.value.responsible_name] : []));
const staffOpts = computed(() => props.meta.staff.filter((s) => !p.value?.executors.some((e) => e.login === s.login)));
const showCalc = computed(() => !client.value || vis.value.length > 0);

watch(
    [p, open, curVer],
    () => {
        if (!open.value || mode.value !== 'card') return;
        if (!p.value) return prjClose();
        // The version shown: the chosen one while it exists, otherwise the last.
        if (curVer.value == null || !vis.value.some((c) => c.version === curVer.value)) curVer.value = vis.value.length ? vis.value[vis.value.length - 1].version : null;
        const key = p.value.id + ':' + curVer.value;
        if (key !== editKey.value) {
            // Numbers as legacy shows them (1, 2.5), not as stored (1.000, 2.50).
            const num = (v: string | number | undefined) => (v === undefined ? undefined : Number(v));
            edit.value = cur.value ? cur.value.items.map((i) => ({ ...i, qty: num(i.qty)!, cost: num(i.cost), price: num(i.price)! })) : [];
            markup.value = String(cur.value && cur.value.markup_pct != null ? Number(cur.value.markup_pct) : 30);
            editKey.value = key;
        }
        // The head follows the project unless the user has changed it (legacy prjRefreshCalc keeps what was typed).
        const fresh = { title: p.value.title, desc: p.value.description || '', due: (p.value.due_date || '').slice(0, 10), status: p.value.status, order: p.value.order_num || '' };
        if (headId !== p.value.id || JSON.stringify(head) === headBase) {
            Object.assign(head, fresh);
            headBase = JSON.stringify(fresh);
            headId = p.value.id;
            headLock = p.value.lock_version;
        }
    },
    { immediate: true, deep: true },
);

const visit = { preserveScroll: true, preserveState: true, only: PROJECT_RELOAD };
const fail = (errors: Record<string, string>) => prjToast('Ошибка: ' + firstError(errors), true);

// ── Head (legacy prjSaveHead) ──
function prjSaveHead(): void {
    if (!p.value) return;
    router.put(
        `/crm/projects/${p.value.id}`,
        {
            title: head.title,
            description: head.desc,
            due_date: head.due || null,
            status: client.value ? null : head.status,
            order_num: client.value ? null : head.order,
            lock_version: headLock,
        },
        {
            ...visit,
            onSuccess: () => {
                headId = null; // take the saved values
                prjToast('Сохранено');
            },
            onError: fail,
        },
    );
}

// ── Executors (legacy prjExecAdd / prjExecRemove) ──
const execSel = ref('');
function prjExecAdd(login: string): void {
    execSel.value = '';
    if (!p.value || !login) return;
    router.post(`/crm/projects/${p.value.id}/executors`, { login }, { ...visit, onError: fail });
}
function prjExecRemove(login: string): void {
    if (!p.value) return;
    router.delete(`/crm/projects/${p.value.id}/executors/${encodeURIComponent(login)}`, { ...visit, onError: fail });
}

// ── Client decision (legacy prjClientDecide) ──
function prjClientDecide(ok: boolean): void {
    if (!p.value) return;
    let reason = '';
    if (!ok) {
        const r0 = window.prompt('Причина отказа (необязательно):');
        if (r0 === null) return;
        reason = r0;
    }
    router.post(`/crm/projects/${p.value.id}/decision`, { accept: ok, reason }, { ...visit, onSuccess: () => prjToast(ok ? 'Расчёт принят' : 'Проект закрыт'), onError: fail });
}

function prjToOrder(): void {
    window.alert('Создание заказа из расчёта появится вместе с формой заказа (ERP-029, решения D-02 и D-12).');
}

function prjDeleteProject(): void {
    const pr = p.value;
    if (!pr || !window.confirm(`Удалить проект ${pr.num || pr.id} «${pr.title}» со всеми расчётами, файлами и сообщениями?`)) return;
    router.delete(`/crm/projects/${pr.id}`, {
        ...visit,
        data: { lock_version: pr.lock_version },
        onSuccess: () => {
            prjClose();
            prjToast('Проект удалён');
        },
        onError: fail,
    });
}

// ── Calculation (legacy prjCalcEdit/Add/Del, prjApplyMarkup, prjCalcSave, prjCalcSend, prjCalcPrint) ──
const total = computed(() => prjTotal(edit.value));
const cost = computed(() => prjCostTotal(edit.value));
const cols = computed(() => 6 + (client.value ? 0 : 1) + (client.value ? 0 : 1));
const calcEdit = (i: number, k: keyof CalcItem, v: string) => {
    if (edit.value[i]) (edit.value[i] as unknown as Record<string, string>)[k] = v;
};
const prjCalcAdd = () => edit.value.push({ name: '', attrs: '', qty: 1, cost: '', price: '' });
const prjCalcDel = (i: number) => edit.value.splice(i, 1);
function prjApplyMarkup(): void {
    const m = parseFloat(markup.value);
    if (isNaN(m)) return prjToast('Введите наценку, %', true);
    edit.value.forEach((it) => {
        if (+(it.cost ?? 0) > 0) it.price = Math.round(+(it.cost ?? 0) * (1 + m / 100));
    });
}
const kept = () => edit.value.filter((i) => (i.name || '').trim() || +i.price > 0);
const payload = (asNew: boolean) => ({
    version: curVer.value,
    as_new: asNew,
    markup: markup.value === '' || isNaN(parseFloat(markup.value)) ? null : String(markup.value),
    items: edit.value.map((i) => ({ name: i.name ?? '', attrs: i.attrs ?? '', qty: String(i.qty ?? ''), cost: String(i.cost ?? ''), price: String(i.price ?? '') })),
});
function afterSave(pg: { props: unknown }, msg: (v: number) => string): void {
    const v = (pg.props as SharedProps).flash.created;
    if (v && p.value) {
        curVer.value = v;
        editKey.value = p.value.id + ':' + v; // keep the lines as entered
        prjToast(msg(v));
    }
}
function prjCalcSave(asNew: boolean): void {
    if (!p.value) return;
    if (!kept().length) return prjToast('Добавьте хотя бы одну позицию', true);
    router.post(`/crm/projects/${p.value.id}/calcs`, payload(asNew), { ...visit, onSuccess: (pg) => afterSave(pg, (v) => 'Расчёт сохранён (v' + v + ')'), onError: fail });
}
function prjCalcSend(): void {
    if (!p.value) return;
    const items = kept();
    if (!items.length) return prjToast('Добавьте хотя бы одну позицию', true);
    const ver = cur.value && !cur.value.sent_at ? cur.value.version : Math.max(0, ...(p.value.calcs.map((c) => c.version) ?? [])) + 1;
    if (!window.confirm(`Отправить заказчику расчёт v${ver} на сумму ${prjFmt(prjTotal(items))}? Заказчик увидит только цены (без себестоимости).`)) return;
    router.post(`/crm/projects/${p.value.id}/calcs/send`, payload(false), { ...visit, onSuccess: (pg) => afterSave(pg, () => 'Расчёт отправлен заказчику'), onError: fail });
}
const esc = (v: unknown) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
function prjCalcPrint(): void {
    const pr = p.value;
    if (!pr) return;
    const items = kept();
    if (!items.length) return prjToast('Нет позиций для печати', true);
    const rows = items
        .map(
            (i, k) =>
                `<tr><td>${k + 1}</td><td>${esc(i.name)}</td><td>${esc(i.attrs)}</td><td style="text-align:right">${+i.qty || 0}</td><td style="text-align:right">${prjFmt(i.price)}</td><td style="text-align:right">${prjFmt((+i.qty || 0) * (+i.price || 0))}</td></tr>`,
        )
        .join('');
    const w = window.open('', '_blank');
    if (!w) return prjToast('Разрешите всплывающие окна для печати', true);
    w.document.write(`<!doctype html><meta charset="utf-8"><title>Расчёт ${esc(pr.num || '')}</title>
  <style>body{font-family:Arial,sans-serif;font-size:13px;margin:24px;color:#111}h2{margin:0 0 4px}table{border-collapse:collapse;width:100%;margin-top:14px}td,th{border:1px solid #888;padding:5px 7px;vertical-align:top}th{background:#eee}.t{margin-top:12px;text-align:right;font-size:15px;font-weight:700}</style>
  <h2>Расчёт стоимости ${esc(pr.num || '')}</h2><div>${esc(pr.title)}</div><div style="color:#555">Заказчик: ${esc(pr.customer_name || chName(pr.channel))} · Дата: ${new Date().toLocaleDateString('ru-RU')}</div>
  <table><thead><tr><th>#</th><th>Наименование</th><th>Описание / размеры</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>${rows}</tbody></table>
  <div class="t">ИТОГО: ${prjFmt(prjTotal(items))}</div><script>setTimeout(function(){window.print()},300)<\/script>`);
    w.document.close();
}

// ── Files (legacy prjUploadFiles / prjUploadTo / prjPickFiles / prjDrop / prjOnPaste / prjDeleteFile) ──
const internal = reactive({ customer: false, designer: false });
const dragOver = reactive({ customer: false, designer: false });
function postFile(id: number, side: string, isInternal: boolean, file: File): Promise<void> {
    return new Promise((resolve, reject) =>
        router.post(
            `/crm/projects/${id}/files`,
            { side, internal: isInternal, file },
            { ...visit, forceFormData: true, onSuccess: () => resolve(), onError: (errors) => reject(new Error(firstError(errors))) },
        ),
    );
}
async function uploadFiles(id: number, side: string, list: File[], isInternal: boolean): Promise<number> {
    let count = 0;
    for (let f of list) {
        f = await prjCompressImage(f);
        if (f.size > 25 * 1048576) {
            prjToast(`«${f.name}» больше 25 МБ — пропущен`, true);
            continue;
        }
        await postFile(id, side, isInternal, f);
        count++;
    }
    return count;
}
async function prjUploadTo(side: 'customer' | 'designer', files: File[]): Promise<void> {
    if (!p.value || !files.length) return;
    prjToast('Загружаю ' + files.length + ' файл(ов)…');
    try {
        const count = await uploadFiles(p.value.id, side, files, internal[side]);
        if (count) prjToast('Загружено: ' + count);
    } catch (e) {
        prjToast((e as Error).message, true);
    }
}
function prjPickFiles(ev: Event, side: 'customer' | 'designer'): void {
    const inp = ev.target as HTMLInputElement;
    const fs = [...(inp.files ?? [])];
    inp.value = '';
    prjUploadTo(side, fs);
}
function prjDrop(ev: DragEvent, side: 'customer' | 'designer'): void {
    dragOver[side] = false;
    prjUploadTo(side, [...(ev.dataTransfer?.files ?? [])]);
}
function prjOnPaste(ev: ClipboardEvent): void {
    if (!open.value || mode.value !== 'card' || !curId.value) return;
    const files = [...(ev.clipboardData?.files ?? [])].filter((f) => f.type.startsWith('image/'));
    if (!files.length) return;
    ev.preventDefault();
    prjUploadTo(
        client.value ? 'customer' : 'designer',
        files.map((f, i) => new File([f], 'screenshot_' + Date.now() + '_' + i + '.png', { type: f.type })),
    );
}
document.addEventListener('paste', prjOnPaste);
onBeforeUnmount(() => document.removeEventListener('paste', prjOnPaste));

const filesOf = (side: string) => (p.value ? p.value.files.filter((f) => f.side === side) : []);
const canUp = (side: string) => !client.value || side === 'customer';
const canDel = (f: ProjectFileRow) => props.meta.admin || f.uploaded_by_login === props.meta.login || !client.value;
const fileUrl = (f: ProjectFileRow) => `/crm/projects/files/${f.id}`;
const kb = (size: number) => (size ? (size > 1048576 ? (size / 1048576).toFixed(1) + ' МБ' : Math.round(size / 1024) + ' КБ') : '');
const ext = (name: string) => ((name || '').split('.').pop() || '').slice(0, 4).toUpperCase();
function prjDeleteFile(f: ProjectFileRow): void {
    if (!window.confirm('Удалить файл «' + f.file_name + '»?')) return;
    router.delete(`/crm/projects/files/${f.id}`, { ...visit, onError: fail });
}

// ── Discussion (legacy prjAddComment) ──
const comment = reactive({ text: '', internal: false });
const commentsEl = ref<HTMLElement | null>(null);
const comments = computed(() => p.value?.comments ?? []);
function prjAddComment(): void {
    const text = comment.text.trim();
    if (!p.value || !text) return;
    router.post(`/crm/projects/${p.value.id}/comments`, { text, internal: comment.internal }, {
        ...visit,
        onSuccess: async () => {
            comment.text = '';
            await nextTick();
            if (commentsEl.value) commentsEl.value.scrollTop = commentsEl.value.scrollHeight;
        },
        onError: fail,
    });
}
watch(open, async (o) => {
    if (o) {
        await nextTick();
        if (commentsEl.value) commentsEl.value.scrollTop = commentsEl.value.scrollHeight;
    }
});

const status = (s: string) => PRJ_STATUSES[s] ?? { label: s, color: '#888' };
const label = { fontSize: '12px', color: 'var(--text3)' };
defineExpose({ prjOpenNew, prjOpen });
</script>

<template>
    <ErpModal id="prjModal" :open="open" :modal-style="{ maxWidth: '1040px', width: '97vw', maxHeight: '94vh', overflow: 'auto' }" @close="prjClose">
        <div id="prjModalBody">
            <template v-if="mode === 'new'">
                <div class="modal-header">
                    <div class="modal-title">{{ client ? 'Запрос на расчёт' : 'Новый проект' }}</div>
                    <button class="modal-close" @click="prjClose">✕</button>
                </div>
                <div style="display: grid; gap: 10px">
                    <div>
                        <label :style="label">Название *</label>
                        <input id="prjn-title" v-model="n.title" type="text" placeholder="Напр.: Кухня для ЖК «Нурлы», угловая 3,2×2,4 м" style="width: 100%" />
                    </div>
                    <template v-if="!client">
                        <div>
                            <label :style="label">Заказчик (канал) *</label>
                            <select id="prjn-ch" v-model="n.channel" style="width: 100%">
                                <option v-for="c in ownChannels" :key="c.code" :value="c.code">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label :style="label">Название клиента / примечание</label>
                            <input id="prjn-cust" v-model="n.customer" type="text" style="width: 100%" />
                        </div>
                    </template>
                    <div>
                        <label :style="label">Описание, размеры, пожелания</label>
                        <textarea id="prjn-desc" v-model="n.desc" rows="5" style="width: 100%" placeholder="Что нужно сделать: материалы, цвета, фурнитура, габариты, срок…"></textarea>
                    </div>
                    <div>
                        <label :style="label">Желаемый срок (ответа / готовности)</label>
                        <input id="prjn-due" v-model="n.due" type="date" />
                    </div>
                    <div>
                        <label :style="label">Чертежи, фото, файлы (можно несколько)</label>
                        <input
                            id="prjn-files"
                            ref="newFiles"
                            type="file"
                            multiple
                            accept="image/*,.pdf,.dwg,.dxf,.xls,.xlsx,.doc,.docx,.zip,.rar,.skp,.pro,.bsolid"
                            style="width: 100%"
                        />
                    </div>
                </div>
                <div style="margin-top: 14px; display: flex; gap: 8px; justify-content: flex-end">
                    <button class="btn btn-ghost" @click="prjClose">Отмена</button>
                    <button id="prjn-save" class="btn btn-primary" :disabled="n.busy" @click="prjCreate">{{ n.label }}</button>
                </div>
            </template>

            <template v-else-if="p">
                <div class="modal-header">
                    <div class="modal-title">
                        <span style="font-family: monospace; color: var(--text3)">{{ p.num || '#' + p.id }}</span> {{ p.title }}{{ ' ' }}<span :style="badgeStyle(status(p.status).color)">{{
                            status(p.status).label
                        }}</span>
                    </div>
                    <button class="modal-close" @click="prjClose">✕</button>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 10px">
                    <div style="grid-column: 1/-1">
                        <label :style="label">Название</label>
                        <input id="prjc-title" v-model="head.title" type="text" style="width: 100%" :disabled="!canHead" />
                    </div>
                    <div>
                        <label :style="label">Заказчик</label>
                        <div style="padding: 6px 0; font-weight: 600">{{ p.customer_name || chName(p.channel) }}</div>
                    </div>
                    <div style="grid-column: 1/-1">
                        <label :style="label">Исполнители проекта (кто делает расчёт)</label>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 4px 0">
                            <template v-if="p.executors.length">
                                <span
                                    v-for="e in p.executors"
                                    :key="e.login"
                                    style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 14px; background: var(--bg3); border: 1px solid var(--border); font-size: 12px"
                                    >👤 {{ e.name
                                    }}<template v-if="!client"
                                        >{{ ' ' }}<a href="javascript:void(0)" title="Убрать" style="color: var(--red); text-decoration: none" @click="prjExecRemove(e.login)">✕</a></template
                                    ></span
                                >
                            </template>
                            <template v-else-if="execNames.length">
                                <span v-for="(nm, i) in execNames" :key="i" style="padding: 3px 10px; border-radius: 14px; background: var(--bg3); font-size: 12px">👤 {{ nm }}</span>
                            </template>
                            <span v-else style="font-size: 12px; color: var(--text3)">пока не назначены</span>
                            <select v-if="!client" v-model="execSel" style="font-size: 12px; width: auto" @change="prjExecAdd(execSel)">
                                <option value="">＋ Добавить исполнителя…</option>
                                <option v-for="s in staffOpts" :key="s.login" :value="s.login">{{ s.name }} — {{ s.label }}</option>
                            </select>
                        </div>
                    </div>
                    <template v-if="!client">
                        <div>
                            <label :style="label">Статус</label>
                            <select id="prjc-status" v-model="head.status" style="width: 100%">
                                <option v-for="(d, k) in PRJ_STATUSES" :key="k" :value="k">{{ d.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :style="label">№ заказа (когда станет заказом)</label>
                            <input id="prjc-order" v-model="head.order" type="text" style="width: 100%" />
                        </div>
                    </template>
                    <div>
                        <label :style="label">Срок</label>
                        <input id="prjc-due" v-model="head.due" type="date" :disabled="!(canHead || !client)" />
                    </div>
                </div>
                <div style="margin-bottom: 10px">
                    <label :style="label">Описание / задание</label>
                    <textarea id="prjc-desc" v-model="head.desc" rows="4" style="width: 100%" :disabled="!canHead"></textarea>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px">
                    <button v-if="canHead || !client" class="btn btn-primary btn-sm" @click="prjSaveHead">💾 Сохранить</button>
                    <template v-if="client && p.status === 'sent'">
                        <button class="btn btn-primary btn-sm" @click="prjClientDecide(true)">✅ Принять расчёт</button>
                        <button class="btn btn-ghost btn-sm" @click="prjClientDecide(false)">✖ Отказаться</button>
                    </template>
                    <button v-if="!client && ['approved', 'sent', 'calc'].includes(p.status)" class="btn btn-ghost btn-sm" @click="prjToOrder">➡ Создать заказ из расчёта</button>
                    <button v-if="!client && props.meta.admin" class="btn btn-ghost btn-sm" style="color: var(--red)" @click="prjDeleteProject">🗑 Удалить проект</button>
                </div>

                <div v-if="showCalc" class="card" style="padding: 12px; margin-bottom: 14px">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px">
                        <b>💰 Расчёт стоимости</b>
                        <select v-if="vis.length > 1" v-model.number="curVer" style="font-size: 12px; width: auto">
                            <option v-for="c in vis" :key="c.version" :value="c.version">
                                v{{ c.version }}{{ !client && c.created_by_name ? ' — ' + c.created_by_name : '' }}{{ c.sent_at ? ' (отправлена)' : '' }}
                            </option>
                        </select>
                    </div>
                    <template v-if="cur || !client">
                        <table class="data-table" style="font-size: 12px">
                            <thead>
                                <tr>
                                    <th style="width: 28px">#</th>
                                    <th>Наименование</th>
                                    <th>Описание / размеры</th>
                                    <th style="width: 70px">Кол-во</th>
                                    <th v-if="!client" style="width: 105px">Себест. за ед.</th>
                                    <th style="width: 105px">Цена за ед.</th>
                                    <th style="width: 110px; text-align: right">Сумма</th>
                                    <th v-if="!client" style="width: 28px"></th>
                                </tr>
                            </thead>
                            <tbody id="prj-calc-body">
                                <tr v-if="!edit.length">
                                    <td :colspan="cols" style="color: var(--text3); text-align: center; padding: 14px">Позиций нет</td>
                                </tr>
                                <tr v-for="(it, i) in edit" :key="i">
                                    <td>{{ i + 1 }}</td>
                                    <template v-if="client">
                                        <td><span>{{ it.name }}</span></td>
                                        <td><span>{{ it.attrs }}</span></td>
                                        <td><span>{{ it.qty }}</span></td>
                                        <td><span>{{ it.price }}</span></td>
                                    </template>
                                    <template v-else>
                                        <td><input type="text" :value="it.name" style="width: 100%; font-size: 12px; padding: 3px 5px" @input="calcEdit(i, 'name', ($event.target as HTMLInputElement).value)" /></td>
                                        <td><input type="text" :value="it.attrs" style="width: 100%; font-size: 12px; padding: 3px 5px" @input="calcEdit(i, 'attrs', ($event.target as HTMLInputElement).value)" /></td>
                                        <td><input type="number" :value="it.qty" style="width: 60px; font-size: 12px; padding: 3px 5px" @input="calcEdit(i, 'qty', ($event.target as HTMLInputElement).value)" /></td>
                                        <td><input type="number" :value="it.cost" style="width: 95px; font-size: 12px; padding: 3px 5px" @input="calcEdit(i, 'cost', ($event.target as HTMLInputElement).value)" /></td>
                                        <td><input type="number" :value="it.price" style="width: 95px; font-size: 12px; padding: 3px 5px" @input="calcEdit(i, 'price', ($event.target as HTMLInputElement).value)" /></td>
                                    </template>
                                    <td :id="'prj-sum-' + i" style="text-align: right">{{ prjFmt((+it.qty || 0) * (+it.price || 0)) }}</td>
                                    <td v-if="!client"><button class="btn btn-ghost btn-sm" style="padding: 0 6px" @click="prjCalcDel(i)">✕</button></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td :colspan="cols - (client ? 1 : 2)" style="text-align: right; font-weight: 700">ИТОГО</td>
                                    <td id="prj-total" style="text-align: right; font-weight: 700">{{ prjFmt(total) }}</td>
                                    <td v-if="!client"></td>
                                </tr>
                                <tr v-if="!client">
                                    <td :colspan="cols - 2" style="text-align: right; color: var(--text3)">Себестоимость / маржа</td>
                                    <td id="prj-margin" style="text-align: right; color: var(--text3)">{{ prjFmt(cost) }} / {{ total > 0 ? Math.round(((total - cost) / total) * 100) : 0 }}%</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <div v-if="cur && !client" style="font-size: 11px; color: var(--text3); margin-top: 4px">
                            Версия {{ cur.version }}{{ cur.sent_at ? ' · отправлена ' + prjDate(cur.sent_at, props.timezone) : ' · не отправлена' }}{{ cur.created_by_name ? ' · ' + cur.created_by_name : '' }}
                        </div>
                        <div v-if="!client" style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; align-items: center">
                            <button class="btn btn-ghost btn-sm" @click="prjCalcAdd">+ Позиция</button>
                            <span style="font-size: 12px; color: var(--text3)">Наценка %</span>
                            <input id="prj-markup" v-model="markup" type="number" style="width: 70px; font-size: 12px; padding: 3px 5px" />
                            <button class="btn btn-ghost btn-sm" title="Цена = себестоимость × (1 + наценка)" @click="prjApplyMarkup">Применить к ценам</button>
                            <span style="flex: 1"></span>
                            <button class="btn btn-primary btn-sm" @click="prjCalcSave(false)">💾 Сохранить расчёт</button>
                            <button class="btn btn-ghost btn-sm" title="Сохранить как новую версию" @click="prjCalcSave(true)">＋ Новая версия</button>
                            <button class="btn btn-ghost btn-sm" @click="prjCalcSend">📤 Отправить заказчику</button>
                            <button class="btn btn-ghost btn-sm" @click="prjCalcPrint">🖨 Печать</button>
                        </div>
                    </template>
                    <div v-if="!cur && !client" style="font-size: 12px; color: var(--text3); margin-top: 6px">Добавьте позиции и нажмите «Сохранить расчёт» — создастся версия v1.</div>
                </div>
                <div v-else style="color: var(--text3); font-size: 13px; margin-bottom: 14px">Расчёт стоимости пока не готов. Мы сообщим, когда он будет отправлен.</div>

                <div class="card" style="padding: 12px; margin-bottom: 14px">
                    <b>📎 Файлы и чертежи</b>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px">
                        <div
                            v-for="col in [
                                { side: 'customer', title: 'От заказчика (салон / дилер)' },
                                { side: 'designer', title: 'От дизайнера / производства' },
                            ] as const"
                            :key="col.side"
                            :style="{ flex: 1, minWidth: '280px', padding: '8px', borderRadius: '8px', outline: dragOver[col.side] ? '2px dashed var(--accent)' : '' }"
                            @dragover.prevent="dragOver[col.side] = true"
                            @dragleave="dragOver[col.side] = false"
                            @drop.prevent="prjDrop($event, col.side)"
                        >
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px">
                                <b style="font-size: 13px">{{ col.title }}</b><span style="font-size: 11px; color: var(--text3)">{{ filesOf(col.side).length }}</span><span style="flex: 1"></span>
                                <label v-if="canUp(col.side)" class="btn btn-ghost btn-sm" style="cursor: pointer"
                                    >＋ Файлы<input type="file" multiple style="display: none" @change="prjPickFiles($event, col.side)"
                                /></label>
                            </div>
                            <label v-if="canUp(col.side) && !client" style="font-size: 11px; color: var(--text3); display: block; margin-bottom: 6px"
                                ><input :id="'prj-int-' + col.side" v-model="internal[col.side]" type="checkbox" /> 🔒 внутренний (не виден заказчику)</label
                            >
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px">
                                <div
                                    v-for="f in filesOf(col.side)"
                                    :key="f.id"
                                    style="border: 1px solid var(--border); border-radius: 8px; overflow: hidden; background: var(--bg3); display: flex; flex-direction: column"
                                >
                                    <a
                                        :href="fileUrl(f)"
                                        target="_blank"
                                        rel="noopener"
                                        style="display: block; height: 96px; background: var(--bg2); text-align: center; line-height: 96px; text-decoration: none; color: var(--text2); font-weight: 700; font-size: 15px"
                                    >
                                        <img v-if="f.image" :src="fileUrl(f)" loading="lazy" style="width: 100%; height: 96px; object-fit: cover" alt="" />
                                        <template v-else>📄 {{ ext(f.file_name) }}</template>
                                    </a>
                                    <div style="padding: 5px 7px; font-size: 11px">
                                        <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap" :title="f.file_name">{{ f.internal ? '🔒 ' : '' }}{{ f.file_name }}</div>
                                        <div style="color: var(--text3)">{{ f.uploaded_by_name || '' }} · {{ prjDate(f.created_at, props.timezone) }} {{ kb(f.size) ? '· ' + kb(f.size) : '' }}</div>
                                        <a v-if="canDel(f)" href="javascript:void(0)" style="color: var(--red)" @click="prjDeleteFile(f)">удалить</a>
                                    </div>
                                </div>
                                <div v-if="!filesOf(col.side).length" style="color: var(--text3); font-size: 12px; grid-column: 1/-1">
                                    {{ canUp(col.side) ? 'Перетащите файлы сюда или нажмите «＋ Файлы». Фото из буфера — Ctrl+V.' : 'Пока нет файлов' }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" style="padding: 12px">
                    <b>💬 Обсуждение</b>
                    <div id="prj-comments" ref="commentsEl" style="margin: 8px 0; max-height: 240px; overflow: auto">
                        <div
                            v-for="c in comments"
                            :key="c.id"
                            :style="{
                                marginBottom: '8px',
                                padding: '7px 10px',
                                borderRadius: '8px',
                                background: c.side === 'client' ? 'var(--bg3)' : 'var(--bg2)',
                                borderLeft: '3px solid ' + (c.side === 'client' ? 'var(--purple)' : 'var(--accent)'),
                            }"
                        >
                            <div style="font-size: 11px; color: var(--text3)">{{ c.internal ? '🔒 ' : '' }}<b>{{ c.author_name }}</b> · {{ prjDate(c.created_at, props.timezone) }}</div>
                            <div style="font-size: 13px; white-space: pre-wrap">{{ c.text }}</div>
                        </div>
                        <div v-if="!comments.length" style="color: var(--text3); font-size: 12px">Сообщений пока нет</div>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: flex-end">
                        <textarea id="prj-newcomment" v-model="comment.text" rows="2" style="flex: 1" placeholder="Написать сообщение…"></textarea>
                        <div style="display: flex; flex-direction: column; gap: 4px">
                            <label v-if="!client" style="font-size: 11px; color: var(--text3)"><input id="prj-cm-int" v-model="comment.internal" type="checkbox" /> 🔒 внутр.</label>
                            <button class="btn btn-primary btn-sm" @click="prjAddComment">Отправить</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </ErpModal>
</template>
