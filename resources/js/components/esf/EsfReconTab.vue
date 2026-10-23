<script setup lang="ts">
// Tab «Сверка с оплатами» of «Входящие ЭСФ» — legacy esf-tab-recon (L3168–3240), initEsfRecon/esfReconRenderAll/
// esfReconSetSourceTab/esfReconGetPaymentRows/esfReconRenderPayments/esfReconToggleChecked/esfReconRecomputeActiveSource/
// esfReconUpdatePoolBar/esfReconClearPool/esfReconGetEsfCandidates/esfReconRenderEsfCandidates/esfReconSelectedSum/
// esfReconUpdateSumInfo/esfReconOnToggleEsf/esfReconClearSelection/esfReconAuditAdvanceLinks (L11872–12330): the payments
// (expense operations of every account, advance reports) with what is linked to them, the invoices still to reconcile, the
// choice of a payment or a group and of its invoices, the check of the links to advance reports. The data load with the tab.
// «Сохранить связи» and «🗑 Удалить связь» save on the server (esfReconSaveLinks/esfReconDeleteAuditLink, D-10 — ADR-0048);
// the automatic matching and distribution wait for the answer to question № 53 (ADR-0043).
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { fmtCurrency } from '@/lib/sales';
import type { SharedProps } from '@/types';

export interface ReconInvoice {
    id: number;
    num: string | null;
    reg_num: string | null;
    date: string;
    supplier_name: string | null;
    total_with_nds: number;
}
export interface ReconData {
    payments: { id: number; date: string; counterparty: string | null; amount: number }[];
    advances: { id: number; date: string; emp_name: string | null; issued: number; status: string | null; lineInvoices: number[] }[];
    links: { id: number; invoice: number; type: string; source: number | null; amount: number }[];
}
type Source = { type: 'money_tx' | 'advance_report'; id: number; date: string; label: string; amount: number };
type Pool = { type: 'pool'; items: { type: string; id: number; amount: number }[]; amount: number; label: string; date: string };

const props = defineProps<{ invoices: ReconInvoice[]; data: ReconData | undefined; active: boolean }>();
watch(
    () => props.active,
    (a) => {
        if (a && props.data === undefined) router.reload({ only: ['recon'] });
    },
    { immediate: true },
);

const TOLERANCE = 100; // legacy ESF_RECON_TOLERANCE, ₸
const LEGAL_RE = /(^|[^А-Яа-яЁёA-Za-z])(ТОО|АО|ИП|ГП|ПК|КХ|TOO)([^А-Яа-яЁёA-Za-z]|$)/i;
const LATER = 'Автоматический подбор связей появится после ответа на вопрос № 53: подбор списком и сохранение одной кнопкой (ERP-048, решение D-10).';
const later = () => window.alert(LATER);
const norm = (s: string | null | undefined) =>
    (s || '')
        .toLowerCase()
        .replace(/[«»"']/g, '')
        .replace(/\s+/g, ' ')
        .trim();

// ── Links (legacy esfLinksData): linked to an invoice, to a source ──
const links = computed(() => props.data?.links ?? []);
const linkedTo = computed(() => {
    const byInvoice = new Map<number, number>();
    const bySource = new Map<string, number>();
    links.value.forEach((l) => {
        byInvoice.set(l.invoice, (byInvoice.get(l.invoice) ?? 0) + (+l.amount || 0));
        if (l.source !== null) bySource.set(l.type + ':' + l.source, (bySource.get(l.type + ':' + l.source) ?? 0) + (+l.amount || 0));
    });
    return { byInvoice, bySource };
});
const linked = (invoiceId: number) => linkedTo.value.byInvoice.get(invoiceId) ?? 0;
const remaining = (inv: ReconInvoice) => (+inv.total_with_nds || 0) - linked(inv.id);
const sourceLinked = (type: string, id: number) => linkedTo.value.bySource.get(type + ':' + id) ?? 0;

// ── KPI (legacy esfReconRenderAll) ──
const kpis = computed(() => {
    const total = props.invoices.length;
    const full = props.invoices.filter((i) => remaining(i) <= TOLERANCE).length;
    const partial = props.invoices.filter((i) => linked(i.id) > 0 && remaining(i) > TOLERANCE).length;
    return [
        ['Всего ЭСФ', total, ''],
        ['Полностью сверено', full, 'var(--green)'],
        ['Частично', partial, 'var(--amber)'],
        ['Не сверено', total - full - partial, 'var(--red)'],
    ] as const;
});

// ── Payments (legacy esfReconGetPaymentRows/esfReconRenderPayments) ──
const f = reactive({ sourceTab: 'money_tx' as 'money_tx' | 'advance_report', paymentSearch: '', showAll: false, esfSearch: '' });
const checked = ref(new Set<string>());
const sourceOf = (type: string, id: number): Source | null => {
    if (type === 'money_tx') {
        const t = props.data?.payments.find((x) => x.id === id);
        return t ? { type: 'money_tx', id: t.id, date: t.date, label: t.counterparty || '(без контрагента)', amount: +t.amount || 0 } : null;
    }
    const a = props.data?.advances.find((x) => x.id === id);
    return a ? { type: 'advance_report', id: a.id, date: a.date, label: a.emp_name || '(без имени)', amount: +a.issued || 0 } : null;
};
const paymentRows = computed(() => {
    const q = norm(f.paymentSearch);
    let rows: Source[];
    if (f.sourceTab === 'money_tx') {
        let list = (props.data?.payments ?? []).filter((t) => f.showAll || LEGAL_RE.test(t.counterparty || ''));
        if (q) list = list.filter((t) => norm(t.counterparty).includes(q));
        rows = list.map((t) => ({ type: 'money_tx', id: t.id, date: t.date, label: t.counterparty || '(без контрагента)', amount: +t.amount || 0 }));
    } else {
        let list = props.data?.advances ?? [];
        if (q) list = list.filter((a) => norm(a.emp_name).includes(q));
        rows = list.map((a) => ({
            type: 'advance_report',
            id: a.id,
            date: a.date,
            label: (a.emp_name || '(без имени)') + (a.status === 'closed' ? ' 🔒' : ''),
            amount: +a.issued || 0,
        }));
    }
    return rows
        .slice()
        .sort((a, b) => (b.date || '').localeCompare(a.date || ''))
        .map((r) => {
            const l = sourceLinked(r.type, r.id);
            return { ...r, linked: l, rest: r.amount - l, pct: r.amount ? Math.min(100, Math.round((l / r.amount) * 100)) : 0, key: r.type + ':' + r.id };
        });
});

// ── The payment or group being reconciled (legacy esfReconRecomputeActiveSource) and its invoices ──
const active = ref<Source | Pool | null>(null);
const selected = ref(new Set<number>());
function recomputeActive(): void {
    const items = [...checked.value].map((key) => sourceOf(key.split(':')[0], +key.split(':')[1])).filter((x): x is Source => x !== null);
    if (!items.length) {
        active.value = null;
        selected.value = new Set();
    } else if (items.length === 1) {
        active.value = items[0];
        selected.value = new Set(links.value.filter((l) => l.type === items[0].type && l.source === items[0].id).map((l) => l.invoice));
    } else {
        const dates = items.map((it) => it.date).sort();
        active.value = {
            type: 'pool',
            items: items.map((it) => ({ type: it.type, id: it.id, amount: it.amount })),
            amount: items.reduce((s, it) => s + it.amount, 0),
            label: items.map((it) => it.label).join(' + '),
            date: dates[dates.length - 1],
        };
        const ids = items.map((it) => it.id);
        selected.value = new Set(links.value.filter((l) => l.type === 'money_tx' && l.source !== null && ids.includes(l.source)).map((l) => l.invoice));
    }
    render();
}
function toggleChecked(key: string, on: boolean): void {
    const next = new Set(checked.value);
    if (on) next.add(key);
    else next.delete(key);
    checked.value = next;
    recomputeActive();
}
function setSourceTab(tab: 'money_tx' | 'advance_report'): void {
    f.sourceTab = tab;
    active.value = null;
    selected.value = new Set();
    checked.value = new Set();
    render();
}
function clearPool(): void {
    checked.value = new Set();
    recomputeActive();
}
const poolInfo = computed(() => {
    const n = checked.value.size;
    return n === 0
        ? 'Отметьте один платёж галочкой — или несколько, чтобы объединить в группу'
        : n === 1
          ? 'Отмечен 1 платёж — работаете с ним'
          : `Отмечено ${n} платежей — объединены в группу`;
});

// ── Invoices (legacy esfReconGetEsfCandidates): the list is drawn on the events legacy draws it on; ticking an invoice changes
// only its box and the sum, as in legacy ──
const drawn = ref({ selected: new Set<number>(), tick: 0 });
function render(): void {
    drawn.value = { selected: new Set(selected.value), tick: drawn.value.tick + 1 };
}
watch(() => f.esfSearch, render);
watch(() => props.data, render);
const candidates = computed(() => {
    void drawn.value.tick;
    const sel = drawn.value.selected;
    const src = active.value;
    const q = norm(f.esfSearch);
    let rows = props.invoices.filter((inv) => remaining(inv) > TOLERANCE || (src && sel.has(inv.id)));
    const matches = (sup: string, cp: string) => sup.includes(cp) || cp.includes(sup);
    if (src && src.type === 'money_tx') {
        const cp = norm(src.label);
        rows = rows.slice().sort((a, b) => {
            const am = matches(norm(a.supplier_name), cp) ? 0 : 1;
            const bm = matches(norm(b.supplier_name), cp) ? 0 : 1;
            return am !== bm ? am - bm : (b.date || '').localeCompare(a.date || '');
        });
    } else if (src && src.type === 'pool') {
        const cps = src.label.split(' + ').map(norm);
        rows = rows.slice().sort((a, b) => {
            const am = cps.some((c) => matches(norm(a.supplier_name), c)) ? 0 : 1;
            const bm = cps.some((c) => matches(norm(b.supplier_name), c)) ? 0 : 1;
            return am !== bm ? am - bm : (b.date || '').localeCompare(a.date || '');
        });
    } else if (src) {
        const at = new Date(src.date).getTime();
        rows = rows.slice().sort((a, b) => Math.abs(new Date(a.date).getTime() - at) - Math.abs(new Date(b.date).getTime() - at));
    }
    if (q) rows = rows.filter((inv) => norm(inv.supplier_name).includes(q) || norm(inv.num).includes(q) || norm(inv.reg_num).includes(q));
    if (src) rows = rows.slice().sort((a, b) => (sel.has(a.id) ? 0 : 1) - (sel.has(b.id) ? 0 : 1));
    return rows;
});
function toggleEsf(id: number, on: boolean): void {
    const next = new Set(selected.value);
    if (on) next.add(id);
    else next.delete(id);
    selected.value = next;
}
function clearSelection(): void {
    selected.value = new Set();
    render();
}
const activeInfo = computed(() => {
    const src = active.value;
    if (!src) return null;
    return src.type === 'pool'
        ? { strong: 'Группа: ' + src.label, rest: ` · ${src.items.length} платежей · ${fmtCurrency(src.amount)}` }
        : { strong: src.label, rest: ` · ${src.date} · ${fmtCurrency(src.amount)}` };
});
// ── Saving (legacy esfReconSaveLinks): the server links, unlinks and writes the lines of the log; then the choice is read
// again from the links (esfReconSyncSelectedFromLinks) and the lists are drawn ──
const page = usePage<SharedProps>();
const log = ref('');
const saving = ref(false);
function logLines(lines: string[] | undefined): void {
    if (lines?.length) log.value += lines.join('\n') + '\n';
}
function saveLinks(): void {
    const src = active.value;
    if (!src) {
        logLines(['⚠️ Нет выбранного платежа — нечего сохранять.']);
        return;
    }
    const sources = src.type === 'pool' ? src.items.map((it) => ({ type: it.type, id: it.id })) : [{ type: src.type, id: src.id }];
    router.post(
        '/esf/links',
        { sources, invoices: [...selected.value] },
        {
            only: ['recon', 'flash'],
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => {
                logLines((page.props.flash.result as unknown as { esfLog?: string[] } | null)?.esfLog);
                recomputeActive();
            },
            onError: (e) => logLines(['❌ Ошибка при сохранении: ' + (Object.values(e)[0] || '')]),
        },
    );
}
const sum = computed(() => [...selected.value].reduce((s, id) => s + (+(props.invoices.find((i) => i.id === id)?.total_with_nds ?? 0) || 0), 0));
const over = computed(() => active.value !== null && sum.value > active.value.amount + TOLERANCE);

// ── Links to advance reports whose report no longer names the invoice (legacy esfReconAuditAdvanceLinks) ──
const audit = reactive<{ text: string; items: { id: number; emp: string; date: string; issued: number; num: string; supplier: string; amount: number }[] }>({ text: '', items: [] });
function auditAdvanceLinks(): void {
    const items: typeof audit.items = [];
    links.value
        .filter((l) => l.type === 'advance_report')
        .forEach((l) => {
            const adv = props.data?.advances.find((a) => a.id === l.source);
            const inv = props.invoices.find((i) => i.id === l.invoice);
            if (!adv || !inv) return;
            if (!adv.lineInvoices.includes(l.invoice))
                items.push({ id: l.id, emp: adv.emp_name || '—', date: adv.date, issued: adv.issued, num: inv.num || '', supplier: inv.supplier_name || '', amount: l.amount });
        });
    audit.items = items;
    audit.text = items.length
        ? `Найдено ${items.length} связей, где в текущих строках авансового отчёта эта ЭСФ не числится — проверьте, актуальны ли они:`
        : 'Подозрительных связей не найдено (либо во всех отчётах есть соответствующая строка).';
}
// Legacy esfReconDeleteAuditLink: asks, deletes the one link, checks again.
function deleteAuditLink(s: (typeof audit.items)[number]): void {
    if (!window.confirm(`Удалить связь ЭСФ №${s.num} с авансовым отчётом «${s.emp}»?`)) return;
    router.delete(`/esf/links/${s.id}`, {
        only: ['recon', 'flash'],
        preserveScroll: true,
        onSuccess: () => {
            logLines([`🗑 Удалена устаревшая связь: ЭСФ №${s.num} ↔ ${s.emp}`]);
            auditAdvanceLinks();
            recomputeActive();
        },
        onError: (e) => window.alert('Ошибка удаления: ' + (Object.values(e)[0] || '')),
    });
}
</script>

<template>
    <div v-if="props.data === undefined" id="esf-recon-status" style="padding: 20px; color: var(--text3); font-size: 13px">Загрузка данных для сверки...</div>
    <template v-else>
        <div id="esf-recon-kpis" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px">
            <div v-for="[label, value, color] in kpis" :key="label" class="card" style="padding: 10px 12px">
                <div style="font-size: 10px; color: var(--text3); text-transform: uppercase; margin-bottom: 4px">{{ label }}</div>
                <div :style="'font-size:18px;font-weight:700;' + (color ? 'color:' + color : '')">{{ value }}</div>
            </div>
        </div>

        <div id="esf-recon-main" style="display: block">
            <div style="font-size: 12px; color: var(--text3); margin-bottom: 10px">
                Слева — платежи (расчётный счёт, касса, авансовые отчёты). Справа — ЭСФ. Выбираете платёж → подбираете к нему ЭСФ.
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 8px; align-items: center; flex-wrap: wrap">
                <button id="esf-recon-automatch-btn" class="btn btn-primary btn-sm" @click="later">🔍 Найти очевидные совпадения (авто)</button>
                <span id="esf-recon-automatch-result" style="font-size: 12px; color: var(--text3)"></span>
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 14px; align-items: center; flex-wrap: wrap">
                <button id="esf-recon-autodist-btn" class="btn btn-primary btn-sm" @click="later">🤖 Разнести свободные ЭСФ (платежи → авансовые отчёты)</button>
                <span id="esf-recon-autodist-result" style="font-size: 12px; color: var(--text3)"></span>
            </div>

            <div style="display: flex; gap: 10px; margin-bottom: 14px; align-items: center; flex-wrap: wrap">
                <button class="btn btn-ghost btn-sm" @click="auditAdvanceLinks">🧹 Проверить связи с авансовыми отчётами</button>
                <span id="esf-recon-audit-result" style="font-size: 12px; color: var(--text3)">{{ audit.text }}</span>
            </div>
            <div id="esf-recon-audit-body" :style="{ display: audit.items.length ? 'block' : 'none', marginBottom: '14px' }">
                <div v-for="(s, i) in audit.items" :key="i" class="card" style="padding: 10px 14px; margin-bottom: 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                    <span style="flex: 1; font-size: 12px">
                        <strong>{{ s.emp }}</strong> ({{ s.date }}, {{ fmtCurrency(s.issued) }}) ↔ ЭСФ <strong>№{{ s.num }}</strong>, {{ s.supplier }} ({{ fmtCurrency(s.amount) }})
                    </span>
                    <button class="btn btn-ghost btn-sm" style="font-size: 11px; color: var(--red)" @click="deleteAuditLink(s)">🗑 Удалить связь</button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px">
                <!-- LEFT: payments -->
                <div class="card">
                    <div class="tabs" style="margin-bottom: 10px">
                        <div id="esf-recon-tab-moneytx" class="tab" :class="{ active: f.sourceTab === 'money_tx' }" @click="setSourceTab('money_tx')">Платежи РС/касса</div>
                        <div id="esf-recon-tab-advance" class="tab" :class="{ active: f.sourceTab === 'advance_report' }" @click="setSourceTab('advance_report')">
                            Авансовые отчёты
                        </div>
                    </div>
                    <input
                        id="esf-recon-payment-search"
                        v-model="f.paymentSearch"
                        type="search"
                        placeholder="🔍 Поиск по контрагенту/сотруднику..."
                        style="width: 100%; font-size: 13px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1); margin-bottom: 8px"
                    />
                    <label style="font-size: 11px; color: var(--text3); display: flex; align-items: center; gap: 5px; margin-bottom: 8px">
                        <input id="esf-recon-show-all" v-model="f.showAll" type="checkbox" style="width: auto" /> показывать все расходные платежи, не только ТОО/АО/ИП
                    </label>
                    <div style="max-height: 40vh; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px">
                        <table class="data-table" style="font-size: 12px">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Дата</th>
                                    <th>Кто</th>
                                    <th style="text-align: right">Сумма</th>
                                    <th>Привязано</th>
                                </tr>
                            </thead>
                            <tbody id="esf-recon-payments-body">
                                <tr v-for="r in paymentRows" :key="r.key" :style="checked.has(r.key) ? 'background:rgba(79,142,247,.12)' : ''">
                                    <td style="text-align: center">
                                        <input type="checkbox" style="width: auto" :checked="checked.has(r.key)" @change="toggleChecked(r.key, ($event.target as HTMLInputElement).checked)" />
                                    </td>
                                    <td style="white-space: nowrap">{{ r.date || '' }}</td>
                                    <td style="max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ r.label }}</td>
                                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ fmtCurrency(r.amount) }}</td>
                                    <td>
                                        <span v-if="r.rest <= TOLERANCE" class="badge badge-green">✓</span>
                                        <span v-else-if="r.linked > 0" class="badge badge-amber">…</span>
                                        <span v-else class="badge" style="background: var(--bg3); color: var(--text3)">—</span>
                                        <div style="height: 4px; border-radius: 2px; background: var(--bg3); overflow: hidden; margin-top: 3px; width: 60px">
                                            <div :style="{ height: '100%', width: r.pct + '%', background: 'var(--green)' }"></div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 8px; align-items: center">
                        <span id="esf-recon-pool-info" style="font-size: 11px; color: var(--text3)">{{ poolInfo }}</span>
                        <span style="flex: 1"></span>
                        <button class="btn btn-ghost btn-sm" @click="clearPool">Снять отметки</button>
                    </div>
                </div>

                <!-- RIGHT: ESF candidates -->
                <div class="card">
                    <div id="esf-recon-active-info" style="margin-bottom: 8px; font-size: 12px; color: var(--text3)">
                        <template v-if="activeInfo"
                            ><strong>{{ activeInfo.strong }}</strong>{{ activeInfo.rest }}</template
                        ><template v-else>Выберите платёж слева</template>
                    </div>
                    <input
                        id="esf-recon-esf-search"
                        v-model="f.esfSearch"
                        type="search"
                        placeholder="🔍 Поиск ЭСФ по поставщику/номеру..."
                        style="width: 100%; font-size: 13px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1); margin-bottom: 8px"
                    />
                    <div style="max-height: 40vh; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px">
                        <table class="data-table" style="font-size: 12px">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Дата</th>
                                    <th>Поставщик</th>
                                    <th>№ ЭСФ</th>
                                    <th style="text-align: right">Сумма</th>
                                </tr>
                            </thead>
                            <tbody id="esf-recon-esf-body">
                                <tr v-for="inv in candidates" :key="inv.id" :style="drawn.selected.has(inv.id) ? 'background:rgba(79,142,247,.12)' : ''">
                                    <td style="text-align: center">
                                        <input
                                            type="checkbox"
                                            style="width: auto"
                                            :checked="selected.has(inv.id)"
                                            :disabled="!active"
                                            @change="toggleEsf(inv.id, ($event.target as HTMLInputElement).checked)"
                                        />
                                    </td>
                                    <td style="white-space: nowrap">{{ inv.date || '' }}</td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ inv.supplier_name || '' }}</td>
                                    <td style="font-family: 'IBM Plex Mono', monospace; font-size: 11px">{{ inv.num || '' }}</td>
                                    <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ fmtCurrency(inv.total_with_nds || 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 8px; align-items: center">
                        <span id="esf-recon-sum-info" style="font-size: 12px"
                            ><template v-if="active"
                                >Выбрано: <strong :style="over ? 'color:var(--red)' : ''">{{ fmtCurrency(sum) }}</strong> из {{ fmtCurrency(active.amount)
                                }}<template v-if="over"> <span style="color: var(--red)">— превышает сумму платежа, но можно всё равно связать</span></template></template
                            ></span
                        >
                        <span style="flex: 1"></span>
                        <button class="btn btn-ghost btn-sm" @click="clearSelection">Очистить выбор</button>
                        <button id="esf-recon-save-btn" class="btn btn-primary btn-sm" :disabled="!active || saving" @click="saveLinks">Сохранить связи</button>
                    </div>
                </div>
            </div>

            <div
                id="esf-recon-log"
                style="
                    background: var(--bg3);
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    padding: 8px;
                    font-family: 'IBM Plex Mono', monospace;
                    font-size: 11px;
                    max-height: 140px;
                    overflow-y: auto;
                    white-space: pre-wrap;
                    margin-top: 10px;
                "
            >{{ log }}</div>
        </div>
    </template>
</template>
