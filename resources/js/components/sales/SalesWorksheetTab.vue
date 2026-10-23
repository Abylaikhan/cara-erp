<script setup lang="ts">
// Tab «Рабочий лист реализации» of «Продажи» — legacy orders-worksheet (L3924–3960), swOpen/swQuick/swReload/swFiltered/swRender/
// swRowCheck/swToggleAll/swPrintDoc/swPrintJournal (L30930–31170): the lines of the delivery notes of a period, grouped by note,
// with search, status and driver filters, selection and printing. The period filters the lines here (legacy asked the server
// for it). Printing works as in legacy; a new note, editing, posting into «Реализация», delivering, taking a line for
// restoration and deleting come with posting realizations (ERP-049/050, D-08) — ADR-0040. A driver account (legacy
// swIsDriverUser) gets only the lines of its notes from the server.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import PosBadge from '@/components/PosBadge.vue';
import { driverMatches, isDriverUser, localISODate } from '@/lib/sales';
import type { SharedProps } from '@/types';

export interface WorksheetRow {
    id: string;
    doc_id: string;
    doc_num: string;
    doc_date: string;
    line_no: number;
    line_kind: string;
    order_num: string | null;
    customer: string | null;
    item_name: string;
    item_no: number | null;
    attrs: string | null;
    attrs_short: boolean;
    unit: string | null;
    qty: number;
    price: number;
    amount: number;
    driver: string | null;
    note: string | null;
    posted: boolean;
    delivered: boolean;
    delivered_by: string | null;
    delivered_at: string | null;
    mine: boolean;
    created_day: string | null;
}
const props = defineProps<{ rows: WorksheetRow[] | undefined; active: boolean; admin: boolean; today: string }>();
const user = computed(() => usePage<SharedProps>().props.auth.user);
const driverUser = computed(() => isDriverUser(user.value));
watch(
    () => props.active,
    (a) => {
        if (a && props.rows === undefined) router.reload({ only: ['worksheet'] });
    },
    { immediate: true },
);

const LATER = 'Накладные, проведение в «Реализацию», доставка и реставрации появятся вместе с проведением реализации (ERP-049/050, решение D-08).';
const later = () => window.alert(LATER);
const KINDS: Record<string, string> = { sale: 'Продажа', restore_pickup: '🔧 Забор на реставрацию', restore_return: '🔧 Возврат после реставрации' };

// ── Period (legacy swOpen: today; swQuick) ──
const f = reactive({ from: props.today, to: props.today, search: '', status: '', driver: '' });
function swQuick(kind: 'today' | 'yesterday' | 'week' | 'month'): void {
    const [y, m, d] = props.today.split('-').map(Number);
    let a = props.today;
    let b = props.today;
    if (kind === 'yesterday') a = b = localISODate(y, m - 1, d - 1);
    if (kind === 'week') a = localISODate(y, m - 1, d - 6);
    if (kind === 'month') [a, b] = [localISODate(y, m - 1, 1), localISODate(y, m, 0)];
    Object.assign(f, { from: a, to: b });
}
// The lines of the period (legacy swRows), in the order legacy asks for them.
const period = computed(() => {
    const from = f.from || props.today;
    const to = f.to || from;
    return (props.rows ?? []).filter((r) => r.doc_date >= from && r.doc_date <= to);
});
const drivers = computed(() => [...new Set(period.value.map((r) => r.driver).filter((d): d is string => !!d))].sort());
watch(drivers, (list) => {
    if (f.driver && !list.includes(f.driver)) f.driver = '';
});
const docRows = (docId: string) => period.value.filter((r) => r.doc_id === docId).sort((a, b) => (a.line_no || 0) - (b.line_no || 0));

// ── Who may do what (legacy swIsAdmin/swIsAuthor/swCreatedToday/swCan*) — the buttons as legacy shows them ──
const isAdmin = computed(() => props.admin);
const isAuthor = (rows: WorksheetRow[]) => rows.length > 0 && rows[0].mine;
const createdToday = (r: WorksheetRow) => !r.created_day || r.created_day === props.today;
const canEdit = (rows: WorksheetRow[]) => rows.length > 0 && !rows[0].posted && (isAdmin.value || (isAuthor(rows) && createdToday(rows[0])));
const canPost = (rows: WorksheetRow[]) => rows.length > 0 && !rows[0].posted && (isAdmin.value || isAuthor(rows));
const canUnpost = (rows: WorksheetRow[]) => rows.length > 0 && rows[0].posted && (isAdmin.value || (isAuthor(rows) && createdToday(rows[0])));
const canDeliver = (rows: WorksheetRow[]) => rows.length > 0 && (isAdmin.value || isAuthor(rows) || driverMatches(rows[0].driver, user.value?.name));

// ── Filters (legacy swFiltered) and the lines as legacy draws them ──
const filtered = computed(() => {
    const q = f.search.toLowerCase().trim();
    return period.value.filter((r) => {
        if (f.driver && (r.driver || '') !== f.driver) return false;
        if (f.status === 'draft' && r.posted) return false;
        if (f.status === 'posted' && !r.posted) return false;
        if (f.status === 'delivered' && !r.delivered) return false;
        if (f.status === 'undelivered' && r.delivered) return false;
        if (q && ![r.doc_num, r.order_num, r.customer, r.item_name, r.attrs, r.driver].join(' ').toLowerCase().includes(q)) return false;
        return true;
    });
});
const lines = computed(() => {
    const seen = new Set<string>();
    return filtered.value.map((r, i) => {
        const first = !seen.has(r.doc_id);
        seen.add(r.doc_id);
        const doc = docRows(r.doc_id);
        const next = filtered.value[i + 1];
        return {
            r,
            i,
            first,
            doc,
            subtotal: !(next && next.doc_id === r.doc_id) && doc.length > 1 ? doc.reduce((t, x) => t + (+x.amount || 0), 0) : null,
        };
    });
});
const total = computed(() => filtered.value.reduce((t, x) => t + (+x.amount || 0), 0));
const fmt = (n: number) => Math.round(+n || 0).toLocaleString('ru-RU');
const ru = (iso: string | null) => {
    const p = String(iso || '')
        .slice(0, 10)
        .split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : iso || '';
};
const attrsShort = (full: string | null) => {
    const txt = String(full || '')
        .split(';')
        .map((x) => x.trim())
        .filter(Boolean)
        .map((x) => {
            const i = x.indexOf(':');
            return i >= 0 ? x.slice(i + 1).trim() : x;
        })
        .join(', ');
    return txt.length > 40 ? txt.slice(0, 40).trim() + '…' : txt;
};
const attrsView = (r: WorksheetRow) => (r.attrs_short ? attrsShort(r.attrs) : r.attrs || '');

// ── Selection (legacy swSel, shift-click) ──
const sel = ref(new Set<string>());
let lastClicked: string | null = null;
function rowCheck(ev: MouseEvent, id: string): void {
    const ids = filtered.value.map((r) => r.id);
    const target = ev.target as HTMLInputElement;
    const next = new Set(sel.value);
    if (ev.shiftKey && lastClicked) {
        const a = ids.indexOf(lastClicked);
        const b = ids.indexOf(id);
        if (a >= 0 && b >= 0) for (let k = Math.min(a, b); k <= Math.max(a, b); k++) next.add(ids[k]);
    } else if (target.checked) next.add(id);
    else next.delete(id);
    lastClicked = id;
    sel.value = next;
}
function toggleAll(on: boolean): void {
    const next = new Set(sel.value);
    filtered.value.forEach((r) => (on ? next.add(r.id) : next.delete(r.id)));
    sel.value = next;
}
watch(period, () => (sel.value = new Set()));
const allChecked = computed(() => filtered.value.length > 0 && filtered.value.every((r) => sel.value.has(r.id)));

// ── Printing (legacy swPrintHtml/swPrintDoc/swPrintJournal) ──
const esc = (v: unknown) => String(v == null ? '' : v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] as string);
const PRINT_CSS =
    '<style>@page{size:A4;margin:12mm}body{font-family:Arial,sans-serif;font-size:11px;color:#111}h2{margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #444;padding:4px 6px;vertical-align:top}th{background:#eee;text-align:left}td.r,th.r{text-align:right}.sign{display:flex;justify-content:space-between;margin-top:28px;font-size:12px}.sign div{width:45%;border-top:1px solid #000;padding-top:4px}</style>';
function printHtml(html: string): void {
    document.getElementById('sw-print-frame')?.remove();
    const frame = document.createElement('iframe');
    frame.id = 'sw-print-frame';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(frame);
    const d = frame.contentWindow!.document;
    d.open();
    d.write(html);
    d.close();
    setTimeout(() => {
        frame.contentWindow!.focus();
        frame.contentWindow!.print();
    }, 300);
}
const noText = (r: WorksheetRow) => (r.item_no ? `<b>№${r.item_no}</b> ` : '');
function printDoc(docId: string): void {
    const rows = docRows(docId);
    if (!rows.length) return;
    const h = rows[0];
    const sum = rows.reduce((t, x) => t + (+x.amount || 0), 0);
    const orders = [...new Set(rows.map((r) => r.order_num).filter(Boolean))];
    const body = rows
        .map(
            (r, i) =>
                `<tr><td>${i + 1}</td><td>${noText(r)}${r.line_kind === 'restore_pickup' ? '[Забор на реставрацию] ' : r.line_kind === 'restore_return' ? '[Возврат после реставрации] ' : ''}${esc(r.item_name)}${r.order_num ? `<div style="font-size:9px;color:#555">заказ ${esc(r.order_num)}</div>` : ''}</td><td>${esc(attrsView(r))}</td><td>${esc(r.unit || 'шт')}</td><td class="r">${esc(r.qty)}</td><td class="r">${fmt(r.price)}</td><td class="r">${fmt(r.amount)}</td></tr>`,
        )
        .join('');
    printHtml(`<html><head><meta charset="utf-8">${PRINT_CSS}</head><body>
    <h2>Накладная на отгрузку № ${esc(h.doc_num)} от ${esc(ru(h.doc_date))}</h2>
    <div>Покупатель: <b>${esc(h.customer)}</b></div>
    ${orders.length ? `<div>Основание: заказ ${orders.map(esc).join(', ')}</div>` : ''}
    ${h.driver ? `<div>Водитель: ${esc(h.driver)}</div>` : ''}
    ${h.note ? `<div>Примечание: ${esc(h.note)}</div>` : ''}
    <table><thead><tr><th>№</th><th>Наименование</th><th>Характеристики</th><th>Ед.</th><th class="r">Кол-во</th><th class="r">Цена, ₸</th><th class="r">Сумма, ₸</th></tr></thead>
    <tbody>${body}</tbody>
    <tfoot><tr><td colspan="6" class="r"><b>Итого:</b></td><td class="r"><b>${fmt(sum)}</b></td></tr></tfoot></table>
    <div class="sign"><div>Отпустил: ____________________</div><div>Получил: ____________________</div></div>
    </body></html>`);
}
function printJournal(): void {
    let rows = filtered.value;
    const chosen = rows.filter((r) => sel.value.has(r.id));
    if (chosen.length) rows = chosen;
    if (!rows.length) {
        window.alert('Нет строк для печати');
        return;
    }
    const sum = rows.reduce((t, x) => t + (+x.amount || 0), 0);
    const body = rows
        .map(
            (r) =>
                `<tr><td>${esc(r.doc_num)}</td><td>${esc(ru(r.doc_date))}</td><td>${esc(r.order_num || '')}</td><td>${esc(r.customer)}</td><td>${noText(r)}${esc(r.item_name)}</td><td>${esc(attrsView(r))}</td><td class="r">${esc(r.qty)}</td><td class="r">${fmt(r.price)}</td><td class="r">${fmt(r.amount)}</td><td>${esc(r.driver || '')}</td><td>${r.delivered ? '✓' : ''}</td></tr>`,
        )
        .join('');
    printHtml(`<html><head><meta charset="utf-8">${PRINT_CSS}<style>@page{size:A4 landscape;margin:10mm}body{font-size:10px}</style></head><body>
    <h2>Журнал реализации${chosen.length ? ' (выбранные строки)' : ''}</h2>
    <div>Период: ${esc(ru(f.from))} — ${esc(ru(f.to))} · строк: ${rows.length}</div>
    <table><thead><tr><th>№ накл.</th><th>Дата</th><th>Заказ</th><th>Покупатель</th><th>Товар</th><th>Характеристики</th><th class="r">Кол-во</th><th class="r">Цена</th><th class="r">Сумма</th><th>Водитель</th><th>Дост.</th></tr></thead>
    <tbody>${body}</tbody><tfoot><tr><td colspan="8" class="r"><b>Итого:</b></td><td class="r"><b>${fmt(sum)}</b></td><td colspan="2"></td></tr></tfoot></table></body></html>`);
}
const deliveredTitle = (r: WorksheetRow) => (r.delivered_by || '') + ' ' + (r.delivered_at ? new Date(r.delivered_at).toLocaleString('ru-RU') : '');
</script>

<template>
    <div style="display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: flex-end">
        <div><label class="label">С</label><input id="sw-from" v-model="f.from" type="date" style="font-size: 13px" /></div>
        <div><label class="label">По</label><input id="sw-to" v-model="f.to" type="date" style="font-size: 13px" /></div>
        <button class="btn btn-ghost btn-sm" @click="swQuick('today')">Сегодня</button>
        <button class="btn btn-ghost btn-sm" @click="swQuick('yesterday')">Вчера</button>
        <button class="btn btn-ghost btn-sm" @click="swQuick('week')">Неделя</button>
        <button class="btn btn-ghost btn-sm" @click="swQuick('month')">Месяц</button>
        <input id="sw-search" v-model="f.search" type="search" placeholder="🔍 № накладной, заказ, покупатель, товар" style="font-size: 13px; width: 260px" />
        <select id="sw-status" v-model="f.status" style="font-size: 13px; width: auto">
            <option value="">Все статусы</option>
            <option value="draft">Черновик</option>
            <option value="posted">Проведено</option>
            <option value="undelivered">Не доставлено</option>
            <option value="delivered">Доставлено</option>
        </select>
        <select id="sw-driver-filter" v-model="f.driver" style="font-size: 13px; width: auto">
            <option value="">Все водители</option>
            <option v-for="d in drivers" :key="d" :value="d">{{ d }}</option>
        </select>
        <div style="margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap">
            <button class="btn btn-ghost btn-sm" title="Печатает отмеченные строки, а если ничего не отмечено — все строки по фильтру" @click="printJournal">
                🖨 Печать журнала
            </button>
            <button id="sw-new-btn" class="btn btn-primary btn-sm" :style="driverUser ? { display: 'none' } : undefined" @click="later">+ Новая накладная</button>
        </div>
    </div>
    <div id="sw-msg" style="font-size: 12px; color: var(--text3); margin-bottom: 6px">{{ rows === undefined ? '⏳ Загрузка...' : '' }}</div>
    <div style="overflow: auto; max-height: calc(100vh - 300px)">
        <table id="sw-table" class="data-table">
            <thead>
                <tr>
                    <th style="width: 26px"><input id="sw-check-all" type="checkbox" :checked="allChecked" @change="toggleAll(($event.target as HTMLInputElement).checked)" /></th>
                    <th>№ накл.</th>
                    <th>Дата</th>
                    <th>Заказ</th>
                    <th>Покупатель</th>
                    <th>Товар</th>
                    <th>Характеристики</th>
                    <th style="text-align: right">Кол-во</th>
                    <th style="text-align: right">Цена</th>
                    <th style="text-align: right">Сумма</th>
                    <th>Водитель</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="sw-tbody">
                <template v-if="rows !== undefined">
                    <template v-for="l in lines" :key="l.r.id">
                        <tr :style="l.first && l.i > 0 ? 'border-top:2px solid var(--border)' : ''">
                            <td><input type="checkbox" :checked="sel.has(l.r.id)" @click="rowCheck($event, l.r.id)" /></td>
                            <td style="font-weight: 600; white-space: nowrap">{{ l.r.doc_num }}</td>
                            <td style="white-space: nowrap; font-size: 12px">{{ ru(l.r.doc_date) }}</td>
                            <td style="font-size: 12px; color: var(--accent)">{{ l.r.order_num || '—' }}</td>
                            <td>{{ l.first ? l.r.customer : '' }}</td>
                            <td style="font-weight: 500">
                                <PosBadge :n="l.r.item_no" /><template v-if="l.r.line_kind && l.r.line_kind !== 'sale'"
                                    ><span class="badge badge-amber" :title="KINDS[l.r.line_kind] || ''">🔧 {{ l.r.line_kind === 'restore_pickup' ? 'забор' : 'возврат' }}</span>{{ ' ' }}</template
                                >{{ l.r.item_name }}
                            </td>
                            <td style="font-size: 12px; color: var(--text2); max-width: 260px">{{ attrsView(l.r) }}</td>
                            <td style="text-align: right; font-family: monospace">{{ l.r.qty }} {{ l.r.unit || '' }}</td>
                            <td style="text-align: right; font-family: monospace">{{ fmt(l.r.price) }}</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600">{{ fmt(l.r.amount) }}</td>
                            <td>{{ l.first ? l.r.driver || '—' : '' }}</td>
                            <td>
                                <template v-if="l.first"
                                    ><span v-if="l.r.posted" class="badge badge-green">Проведено</span><span v-else class="badge badge-amber">Черновик</span
                                    ><template v-if="l.r.delivered">{{ ' ' }}<span class="badge badge-blue" :title="deliveredTitle(l.r)">🚚 Доставлено</span></template></template
                                >
                            </td>
                            <td>
                                <div v-if="l.first" style="display: flex; gap: 4px; flex-wrap: wrap">
                                    <button class="btn btn-ghost btn-sm" title="Печать накладной" @click="printDoc(l.r.doc_id)">🖨</button>
                                    <button
                                        v-if="canDeliver(l.doc)"
                                        class="btn btn-ghost btn-sm"
                                        :title="l.r.delivered ? 'Снять отметку о доставке' : 'Отметить: доставлено'"
                                        @click="later"
                                    >
                                        {{ l.r.delivered ? '↩🚚' : '🚚' }}
                                    </button>
                                    <button
                                        v-if="!driverUser && l.doc.some((x) => (x.line_kind || 'sale') === 'sale')"
                                        class="btn btn-ghost btn-sm"
                                        title="Принять позицию на реставрацию"
                                        @click="later"
                                    >
                                        🔧
                                    </button>
                                    <button v-if="canEdit(l.doc)" class="btn btn-ghost btn-sm" title="Редактировать" @click="later">✏️</button>
                                    <button v-if="canPost(l.doc)" class="btn btn-primary btn-sm" title="Провести в реестр «Реализация»" @click="later">✅ Провести</button>
                                    <button v-if="canUnpost(l.doc)" class="btn btn-ghost btn-sm" title="Снять с проведения" @click="later">↩ Снять</button>
                                    <button v-if="canEdit(l.doc)" class="btn btn-ghost btn-sm" title="Удалить" @click="later">🗑</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="l.subtotal !== null" style="background: var(--bg3)">
                            <td colspan="9" style="text-align: right; font-size: 12px; color: var(--text3)">Итого по накладной {{ l.r.doc_num }}:</td>
                            <td style="text-align: right; font-family: monospace; font-weight: 700">{{ fmt(l.subtotal) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </template>
                    <tr v-if="!lines.length">
                        <td colspan="13" style="text-align: center; padding: 24px; color: var(--text3)">За выбранный период записей нет</td>
                    </tr>
                </template>
            </tbody>
            <tfoot id="sw-tfoot">
                <tr v-if="filtered.length" style="font-weight: 700; background: var(--bg3)">
                    <td colspan="9" style="text-align: right">ИТОГО ({{ new Set(filtered.map((r) => r.doc_id)).size }} накл., {{ filtered.length }} поз.):</td>
                    <td style="text-align: right; font-family: monospace">{{ fmt(total) }}</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div style="font-size: 11px; color: var(--text3); margin-top: 8px">
        Строки — черновик и проводок не дают. «Провести» переносит накладную в реестр «Реализация» (выручка, P&amp;L, автозавершение заказов). В день создания строки
        редактирует только исполнитель или администратор, на следующий день — только администратор.
    </div>
</template>
