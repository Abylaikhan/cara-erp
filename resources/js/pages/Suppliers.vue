<script setup lang="ts">
// M06 «Поставщики» — legacy page-suppliers (L3238–3342) and supplierCardModal (L3345–3450): renderSupplierKPIs,
// renderSupplierList, renderSupCategories, renderSupRating, renderSupHistory, showSupTab, openSupplierCard, saveSupplierCard,
// deleteSupplier (L18347–18780), importSuppliersCSV (L17729), openMergeSuppliersModal/renderMergeGroups/mergeSupplierGroup
// (L18007–18105). Same markup, texts and calculations; saving, the duplicate check, the CSV plan and merging are done by the
// server. Tools that work on payments and ESF wait for those modules (docs/adr/0007-suppliers.md).
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { findSupplierDuplicateGroups, pickBestCanonicalId, type DuplicateGroup } from '@/lib/supplierDuplicates';
import type { SharedProps } from '@/types';

interface Supplier {
    id: number;
    name: string;
    category: string | null;
    status: string;
    contact: string | null;
    phone: string | null;
    email: string | null;
    bin: string | null;
    address: string | null;
    lead_time_days: number | null;
    turnover: number | null;
    defect_pct: number | null;
    comment: string | null;
    expense_category_id: number | null;
    expense_article_id: number | null;
    expense_source: string;
    lock_version: number;
}

interface ExpenseOption {
    id: number;
    name: string;
    deleted: boolean;
    articles: { id: number; name: string; deleted: boolean }[];
}

const props = defineProps<{ suppliers: Supplier[]; expenseCategories: ExpenseOption[] }>();

const STATUS_LABEL: Record<string, string> = { active: 'Активен', pause: 'На паузе', blocked: 'Заблокирован' };
const statusLabel = (s: string) => STATUS_LABEL[s] ?? 'Активен';
const statusCls = (s: string) => (s === 'active' ? 'badge-green' : s === 'pause' ? 'badge-amber' : 'badge-red');

// Legacy calcSupRating: lead time, defect share and turnover, 0–5.
function rating(s: Supplier): string {
    const lt = Math.max(0, 5 - (s.lead_time_days || 7) / 5);
    const df = Math.max(0, 5 - (s.defect_pct || 0) * 2);
    const tv = Math.min(5, (s.turnover || 0) / 1000000);
    return ((lt + df + tv) / 3).toFixed(1);
}
const stars = (r: string) => '★'.repeat(Math.round(parseFloat(r))) + '☆'.repeat(5 - Math.round(parseFloat(r)));
const money = (n: number | null) => (n ? '₸' + n.toLocaleString('ru-RU') : '—');
const leadTime = (s: Supplier) => (s.lead_time_days ? s.lead_time_days + ' дн' : '—');

// ── KPIs (renderSupplierKPIs) ──
const kpi = computed(() => {
    const turnover = props.suppliers.reduce((sum, s) => sum + (s.turnover || 0), 0);
    const withLead = props.suppliers.filter((s) => (s.lead_time_days ?? 0) > 0);
    const avg = withLead.length ? Math.round(withLead.reduce((sum, s) => sum + (s.lead_time_days ?? 0), 0) / withLead.length) : 0;
    return {
        count: props.suppliers.length,
        active: props.suppliers.filter((s) => s.status === 'active').length,
        turnover: turnover >= 1000000 ? '₸' + (turnover / 1000000).toFixed(1) + 'М' : '₸' + Math.round(turnover / 1000).toLocaleString('ru-RU') + 'К',
        leadTime: avg + ' дн',
    };
});

// ── Tabs (showSupTab) ──
type Tab = 'list' | 'categories' | 'rating' | 'history';
const tab = ref<Tab>('list');
const tabs: { id: Tab; label: string }[] = [
    { id: 'list', label: '📋 Все поставщики' },
    { id: 'categories', label: '🗂 По категориям' },
    { id: 'rating', label: '⭐ Рейтинг' },
    { id: 'history', label: '📦 История закупок' },
];

// ── List (renderSupplierList) ──
const search = ref('');
const statusFilter = ref('all');
const categoryFilter = ref('all');
const categories = computed(() => [...new Set(props.suppliers.map((s) => s.category).filter((c): c is string => !!c))]);
const rows = computed(() => {
    const q = search.value.toLowerCase();
    return props.suppliers.filter(
        (s) =>
            (!q || (s.name + ' ' + (s.category || '') + ' ' + (s.contact || '')).toLowerCase().includes(q)) &&
            (statusFilter.value === 'all' || s.status === statusFilter.value) &&
            (categoryFilter.value === 'all' || s.category === categoryFilter.value),
    );
});

// ── By category (renderSupCategories), rating (renderSupRating), history (renderSupHistory) ──
const byCategory = computed(() => {
    const groups = new Map<string, Supplier[]>();
    props.suppliers.forEach((s) => {
        const c = s.category || 'Прочее';
        groups.set(c, [...(groups.get(c) ?? []), s]);
    });
    return [...groups.entries()];
});
const ranked = computed(() => [...props.suppliers].sort((a, b) => parseFloat(rating(b)) - parseFloat(rating(a))));
const leadColor = (d: number) => (d <= 5 ? 'var(--green)' : d <= 10 ? 'var(--amber)' : 'var(--red)');
const defectColor = (d: number) => (d === 0 ? 'var(--green)' : d < 1 ? 'var(--amber)' : 'var(--red)');
// Legacy reads the history from purchasesData, which its loader always leaves empty (loadPurchasesFromDB, L24978).
const historySupplier = ref('all');

// ── supplierCardModal (openSupplierCard / saveSupplierCard / deleteSupplier) ──
const card = reactive({
    open: false,
    id: null as number | null,
    title: 'Новый поставщик',
    name: '',
    category: '',
    status: 'active',
    contact: '',
    phone: '',
    email: '',
    bin: '',
    leadTime: '' as string | number,
    turnover: '' as string | number,
    defect: '' as string | number,
    address: '',
    comment: '',
    expenseCategoryId: '' as number | '',
    expenseArticleId: '' as number | '',
    expenseSource: 'payment',
    lockVersion: 0,
    message: '',
    color: 'var(--red)',
    busy: false,
    saved: false,
});
const articles = computed(() => props.expenseCategories.find((c) => c.id === card.expenseCategoryId)?.articles ?? []);
const deletedSuffix = (deleted: boolean) => (deleted ? ' (удалена)' : '');

function openSupplierCard(s: Supplier | null): void {
    card.id = s?.id ?? null;
    card.title = s ? s.name : 'Новый поставщик';
    card.name = s?.name ?? '';
    card.category = s?.category ?? '';
    card.status = s?.status ?? 'active';
    card.contact = s?.contact ?? '';
    card.phone = s?.phone ?? '';
    card.email = s?.email ?? '';
    card.bin = s?.bin ?? '';
    card.leadTime = s?.lead_time_days || '';
    card.turnover = s?.turnover || '';
    card.defect = s?.defect_pct || '';
    card.address = s?.address ?? '';
    card.comment = s?.comment ?? '';
    card.expenseCategoryId = s?.expense_category_id ?? '';
    card.expenseArticleId = s?.expense_article_id ?? '';
    card.expenseSource = s?.expense_source ?? 'payment';
    card.lockVersion = s?.lock_version ?? 0;
    card.message = '';
    card.color = 'var(--red)';
    card.saved = false;
    card.open = true;
}

function closeCard(): void {
    card.open = false;
    card.message = '';
    card.saved = false;
}

function onExpenseCategoryChange(): void {
    // Legacy updateSupCardExpSubcat keeps the article only if it belongs to the new category.
    if (!articles.value.some((a) => a.id === card.expenseArticleId)) card.expenseArticleId = '';
}

const fail = (errors: Record<string, string>) => {
    card.color = 'var(--red)';
    card.message = Object.values(errors)[0] ?? 'Ошибка сохранения';
};

function saveSupplierCard(): void {
    const name = card.name.trim();
    if (!name) {
        card.color = 'var(--red)';
        card.message = 'Укажите название поставщика';
        return;
    }
    card.color = 'var(--text3)';
    card.message = 'Сохранение...';
    const data = {
        name,
        category: card.category,
        status: card.status,
        contact: card.contact,
        phone: card.phone,
        email: card.email,
        bin: card.bin,
        lead_time_days: card.leadTime,
        turnover: card.turnover,
        defect_pct: card.defect,
        address: card.address,
        comment: card.comment,
        expense_category_id: card.expenseCategoryId,
        expense_article_id: card.expenseArticleId,
        expense_source: card.expenseSource,
    };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (card.busy = true),
        onFinish: () => (card.busy = false),
        onError: fail,
        onSuccess: () => {
            card.saved = true;
            card.color = 'var(--green)';
            card.message = '✓ Сохранено!';
            window.setTimeout(closeCard, 900);
        },
    };
    if (card.id === null) router.post('/suppliers', data, options);
    else router.put(`/suppliers/${card.id}`, { ...data, lock_version: card.lockVersion }, options);
}

function deleteSupplier(): void {
    if (card.id === null || !window.confirm('Удалить поставщика?')) return;
    router.delete(`/suppliers/${card.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: closeCard,
        onError: (errors: Record<string, string>) => window.alert('Не удалось удалить поставщика: ' + (Object.values(errors)[0] ?? '')),
    });
}

// ── 📥 Импорт CSV (importSuppliersCSV): the server answers with the legacy alert or confirmation; «OK» applies the plan ──
const page = usePage<SharedProps>();
const fileInput = ref<HTMLInputElement | null>(null);
let pendingFile: File | null = null;
const resetFile = () => {
    pendingFile = null;
    if (fileInput.value) fileInput.value.value = '';
};
function sendCsv(apply: boolean): void {
    if (!pendingFile) return;
    router.post('/suppliers/import', { file: pendingFile, apply: apply ? 1 : 0 }, { forceFormData: true, preserveScroll: true, preserveState: true });
}
function importSuppliersCSV(e: Event): void {
    pendingFile = (e.target as HTMLInputElement).files?.[0] ?? null;
    sendCsv(false);
}
watch(
    () => page.props.flash.dialog,
    (d) => {
        if (!d) return;
        if (d.type === 'confirm' && window.confirm(d.text)) return sendCsv(true);
        if (d.type === 'alert') window.alert(d.text);
        resetFile();
    },
);

// ── 🔗 Объединить (findSupplierDuplicateGroups / mergeSupplierGroup); «Это разные поставщики» lasts until reload, as in legacy ──
const mergeOpen = ref(false);
const dismissed = ref(new Set<string>());
const chosen = reactive<Record<string, number>>({});
const groups = computed<DuplicateGroup<Supplier>[]>(() => (mergeOpen.value ? findSupplierDuplicateGroups(props.suppliers, dismissed.value) : []));
const canonicalOf = (g: DuplicateGroup<Supplier>) => chosen[g.key] ?? pickBestCanonicalId(g.suppliers);
const extra = (s: Supplier) => [s.category, s.contact, s.phone, s.email].filter(Boolean).join(' · ') || 'нет доп. данных';
function dismissMergeGroup(g: DuplicateGroup<Supplier>): void {
    dismissed.value = new Set([...dismissed.value, g.key]);
}
function mergeSupplierGroup(g: DuplicateGroup<Supplier>): void {
    const canonId = canonicalOf(g);
    const canonical = g.suppliers.find((s) => s.id === canonId);
    const dupes = g.suppliers.filter((s) => s.id !== canonId);
    if (!canonical || !dupes.length) return;
    if (!window.confirm(`Объединить ${dupes.length} запис${dupes.length === 1 ? 'ь' : 'и'} в «${canonical.name}»?\nОстальные записи будут удалены.`)) return;
    router.post(
        '/suppliers/merge',
        { supplier_id: canonical.id, merged_ids: dupes.map((d) => d.id) },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors: Record<string, string>) => window.alert('Ошибка при объединении: ' + (Object.values(errors)[0] ?? '')),
        },
    );
}

// ── Tools that need modules not migrated yet ──
const later = (text: string) => () => window.alert(text);
const importEsf = later('Импорт из ЭСФ появится после переноса раздела «Входящие ЭСФ».');
const orphans = later('Поиск осиротевших названий появится после переноса раздела «Деньги»: он сверяет поставщиков с денежными операциями.');
const spellings = later('Разночтения написаний появятся после переноса раздела «Деньги»: они ищутся в денежных операциях.');
const legalForms = later('Сокращение юрформ появится после переноса разделов «Деньги» и «Входящие ЭСФ»: оно меняет и их записи.');
</script>

<template>
    <ErpLayout>
        <div id="page-suppliers" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Поставщики</div>
                    <div class="section-sub">База контрагентов-поставщиков</div>
                </div>
                <div style="display: flex; gap: 8px">
                    <input id="supplier-file" ref="fileInput" type="file" accept=".csv" style="display: none" @change="importSuppliersCSV" />
                    <button class="btn btn-ghost btn-sm" @click="fileInput?.click()">📥 Импорт CSV</button>
                    <button class="btn btn-ghost btn-sm" @click="importEsf">📄 Импорт из ЭСФ</button>
                    <button class="btn btn-ghost btn-sm" @click="orphans">🕵️ Осиротевшие в операциях</button>
                    <button class="btn btn-ghost btn-sm" @click="spellings">🔤 Разночтения написаний</button>
                    <button class="btn btn-ghost btn-sm" @click="mergeOpen = true">🔗 Объединить</button>
                    <button class="btn btn-ghost btn-sm" @click="legalForms">🇰🇿 Сократить юрформы</button>
                    <button class="btn btn-primary btn-sm" @click="openSupplierCard(null)">+ Добавить</button>
                </div>
            </div>

            <!-- KPI -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Всего поставщиков</div>
                    <div id="sup-count" class="kpi-value" style="font-size: 22px">{{ kpi.count }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Активных</div>
                    <div id="sup-active" class="kpi-value" style="font-size: 22px; color: var(--green)">{{ kpi.active }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Оборот за год</div>
                    <div id="sup-turnover" class="kpi-value" style="font-size: 22px">{{ kpi.turnover }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Ср. срок поставки</div>
                    <div id="sup-leadtime-avg" class="kpi-value" style="font-size: 22px">{{ kpi.leadTime }}</div>
                </div>
            </div>

            <!-- Вкладки -->
            <div class="tabs" style="margin-bottom: 16px">
                <div v-for="t in tabs" :key="t.id" class="tab" :class="{ active: tab === t.id }" @click="tab = t.id">{{ t.label }}</div>
            </div>

            <!-- ВСЕ ПОСТАВЩИКИ -->
            <div v-show="tab === 'list'" id="sup-tab-list">
                <div style="display: flex; gap: 10px; margin-bottom: 12px">
                    <input id="sup-search" v-model="search" type="text" placeholder="Поиск по названию, категории, контакту..." style="flex: 1; max-width: 340px" />
                    <select id="sup-filter-status" v-model="statusFilter" style="font-size: 13px; width: auto">
                        <option value="all">Все статусы</option>
                        <option value="active">Активен</option>
                        <option value="pause">На паузе</option>
                        <option value="blocked">Заблокирован</option>
                    </select>
                    <select id="sup-filter-cat" v-model="categoryFilter" style="font-size: 13px; width: auto">
                        <option value="all">Все категории</option>
                        <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>БИН</th>
                            <th>Категория</th>
                            <th>Контакт</th>
                            <th>Телефон</th>
                            <th style="text-align: right">Оборот (год)</th>
                            <th>Срок поставки</th>
                            <th>Рейтинг</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="sup-list-tbody">
                        <tr v-for="s in rows" :key="s.id" style="cursor: pointer" @click="openSupplierCard(s)">
                            <td>
                                <div style="font-weight: 600">{{ s.name }}</div>
                                <div style="font-size: 11px; color: var(--text3)">{{ s.address || '' }}</div>
                            </td>
                            <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px">
                                <template v-if="s.bin">{{ s.bin }}</template>
                                <span v-else style="color: var(--red)" title="БИН не заполнен">⚠️ нет</span>
                            </td>
                            <td style="font-size: 12px; color: var(--text2)">{{ s.category || '—' }}</td>
                            <td>
                                <div style="font-size: 13px">{{ s.contact || '—' }}</div>
                                <div style="font-size: 11px; color: var(--text3)">{{ s.email || '' }}</div>
                            </td>
                            <td style="font-family: 'IBM Plex Mono', monospace; font-size: 12px">{{ s.phone || '—' }}</td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', monospace">{{ money(s.turnover) }}</td>
                            <td style="text-align: center; font-size: 12px">{{ leadTime(s) }}</td>
                            <td style="text-align: center; font-size: 12px; color: var(--amber)" :title="rating(s) + '/5'">{{ stars(rating(s)) }}</td>
                            <td><span class="badge" :class="statusCls(s.status)">{{ statusLabel(s.status) }}</span></td>
                            <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click.stop="openSupplierCard(s)">✏️</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ПО КАТЕГОРИЯМ -->
            <div v-show="tab === 'categories'" id="sup-tab-categories">
                <div id="sup-categories-body">
                    <div v-for="[cat, sups] in byCategory" :key="cat" class="card" style="margin-bottom: 14px; padding: 0; overflow: hidden">
                        <div style="padding: 10px 16px; background: var(--bg3); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center">
                            <span style="font-weight: 600; font-size: 13px">{{ cat }}</span>
                            <span style="font-size: 12px; color: var(--text3)">{{ sups.length }} поставщик{{ sups.length === 1 ? '' : 'а' }}</span>
                        </div>
                        <div style="padding: 12px 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px">
                            <div
                                v-for="s in sups"
                                :key="s.id"
                                style="background: var(--bg3); border: 1px solid var(--border); border-radius: 8px; padding: 10px; cursor: pointer"
                                @click="openSupplierCard(s)"
                            >
                                <div style="font-weight: 600; font-size: 13px; margin-bottom: 2px">{{ s.name }}</div>
                                <div style="font-size: 11px; color: var(--text3)">{{ s.contact || '' }} {{ s.phone ? '· ' + s.phone : '' }}</div>
                                <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 11px">
                                    <span style="color: var(--text3)">{{ leadTime(s) }}</span>
                                    <span class="badge" :class="statusCls(s.status)" style="font-size: 10px">{{ statusLabel(s.status) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- РЕЙТИНГ -->
            <div v-show="tab === 'rating'" id="sup-tab-rating">
                <div style="margin-bottom: 12px; font-size: 13px; color: var(--text2)">Рейтинг рассчитывается по: сроку поставки, доле брака и обороту.</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Поставщик</th>
                            <th>Категория</th>
                            <th style="text-align: center">Срок поставки</th>
                            <th style="text-align: center">% брака</th>
                            <th style="text-align: right">Оборот</th>
                            <th style="text-align: center">Рейтинг</th>
                        </tr>
                    </thead>
                    <tbody id="sup-rating-tbody">
                        <tr v-for="(s, i) in ranked" :key="s.id" style="cursor: pointer" @click="openSupplierCard(s)">
                            <td style="text-align: center; font-weight: 600" :style="{ color: i < 3 ? 'var(--amber)' : 'var(--text3)' }">{{ i + 1 }}</td>
                            <td><span style="font-weight: 600">{{ s.name }}</span></td>
                            <td style="font-size: 12px; color: var(--text2)">{{ s.category || '—' }}</td>
                            <td style="text-align: center; font-size: 12px">
                                <span v-if="s.lead_time_days" :style="{ color: leadColor(s.lead_time_days) }">{{ s.lead_time_days }} дн</span>
                                <template v-else>—</template>
                            </td>
                            <td style="text-align: center; font-size: 12px">
                                <span :style="{ color: defectColor(s.defect_pct || 0) }">{{ s.defect_pct || 0 }}%</span>
                            </td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px">{{ money(s.turnover) }}</td>
                            <td style="text-align: center">
                                <span style="color: var(--amber)">{{ '★'.repeat(Math.round(parseFloat(rating(s)))) }}</span><span style="color: var(--border)">{{
                                    '☆'.repeat(5 - Math.round(parseFloat(rating(s))))
                                }}</span>
                                {{ ' ' }}<span style="font-size: 11px; color: var(--text3)">{{ rating(s) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ИСТОРИЯ ЗАКУПОК -->
            <div v-show="tab === 'history'" id="sup-tab-history">
                <div style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center">
                    <select id="sup-history-filter" v-model="historySupplier" style="font-size: 13px; width: auto">
                        <option value="all">Все поставщики</option>
                        <option v-for="s in props.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Поставщик</th>
                            <th>Материалы</th>
                            <th style="text-align: right">Сумма</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody id="sup-history-tbody">
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text3); padding: 20px">Нет данных</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL: Объединение дублей поставщиков -->
        <ErpModal id="mergeSuppliersModal" :open="mergeOpen" max-width="640px" :modal-style="{ maxHeight: '85vh', overflowY: 'auto' }" @close="mergeOpen = false">
            <div class="modal-header">
                <span class="modal-title">🔗 Объединение поставщиков</span>
                <button class="modal-close" @click="mergeOpen = false">✕</button>
            </div>
            <div style="font-size: 12px; color: var(--text3); margin-bottom: 12px">
                Автоматически найдены поставщики с одинаковым БИН или похожим названием. Выберите основную запись в каждой группе — остальные будут
                удалены, а их данные (БИН, контакты, оборот) перенесены в основную.
            </div>
            <div id="merge-groups-body">
                <div v-if="!groups.length" style="padding: 24px; text-align: center; color: var(--text3)">✅ Явных дублей не найдено</div>
                <div v-for="(g, gi) in groups" :key="g.key" class="card" style="margin-bottom: 14px; padding: 14px" :data-merge-group="g.key">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px">
                        <div style="font-size: 12px; color: var(--accent); font-weight: 600">{{ g.reasons.join(' · ') || 'Похожие записи' }}</div>
                        <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="dismissMergeGroup(g)">Это разные поставщики</button>
                    </div>
                    <label
                        v-for="s in g.suppliers"
                        :key="s.id"
                        style="display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; border-bottom: 1px dashed var(--border); cursor: pointer"
                    >
                        <input type="radio" :name="'merge-canon-' + gi" :value="s.id" :checked="s.id === canonicalOf(g)" style="width: auto; margin-top: 3px" @change="chosen[g.key] = s.id" />
                        <span style="flex: 1">
                            <div style="font-weight: 600; font-size: 13px">
                                {{ s.name }}{{ ' ' }}<span v-if="s.bin" style="font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--text3)">БИН {{ s.bin }}</span
                                ><span v-else style="color: var(--red); font-size: 11px">без БИН</span>
                            </div>
                            <div style="font-size: 11px; color: var(--text3)">{{ extra(s) }}</div>
                        </span>
                    </label>
                    <div style="display: flex; justify-content: flex-end; margin-top: 10px">
                        <button class="btn btn-primary btn-sm" @click="mergeSupplierGroup(g)">🔗 Объединить в выбранного</button>
                    </div>
                </div>
            </div>
        </ErpModal>

        <!-- MODAL: Карточка поставщика -->
        <ErpModal id="supplierCardModal" :open="card.open" max-width="580px" :modal-style="{ maxHeight: '90vh', overflowY: 'auto' }" @close="closeCard">
            <div class="modal-header">
                <span id="sup-card-title" class="modal-title">{{ card.title }}</span>
                <button class="modal-close" @click="closeCard">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 0 4px">
                <div class="form-row" style="grid-column: 1/-1">
                    <label class="label">Название организации *</label>
                    <input id="sup-card-name" v-model="card.name" type="text" placeholder="ТОО «МебельСнаб»" />
                </div>
                <div class="form-row">
                    <label class="label">Категория</label>
                    <input id="sup-card-cat" v-model="card.category" type="text" placeholder="ЛДСП, ткань, фурнитура..." />
                </div>
                <div class="form-row">
                    <label class="label">Статус</label>
                    <select id="sup-card-status" v-model="card.status">
                        <option value="active">Активен</option>
                        <option value="pause">На паузе</option>
                        <option value="blocked">Заблокирован</option>
                    </select>
                </div>
                <div class="form-row">
                    <label class="label">Контактное лицо</label>
                    <input id="sup-card-contact" v-model="card.contact" type="text" placeholder="Иванов И.И." />
                </div>
                <div class="form-row">
                    <label class="label">Телефон</label>
                    <input id="sup-card-phone" v-model="card.phone" type="text" placeholder="+7 701 000 0000" />
                </div>
                <div class="form-row">
                    <label class="label">Email</label>
                    <input id="sup-card-email" v-model="card.email" type="email" placeholder="info@supplier.kz" />
                </div>
                <div class="form-row">
                    <label class="label">БИН / ИИН</label>
                    <input id="sup-card-bin" v-model="card.bin" type="text" placeholder="000000000000" />
                </div>
                <div class="form-row">
                    <label class="label">Срок поставки (дней)</label>
                    <input id="sup-card-leadtime" v-model="card.leadTime" type="number" min="0" placeholder="7" />
                </div>
                <div class="form-row">
                    <label class="label">Оборот за год (₸)</label>
                    <input id="sup-card-turnover" v-model="card.turnover" type="number" min="0" placeholder="0" />
                </div>
                <div class="form-row">
                    <label class="label">% брака</label>
                    <input id="sup-card-defect" v-model="card.defect" type="number" min="0" max="100" step="0.1" placeholder="0" />
                </div>
                <div class="form-row" style="grid-column: 1/-1">
                    <label class="label">Адрес</label>
                    <input id="sup-card-addr" v-model="card.address" type="text" placeholder="г. Алматы, ул. ..." />
                </div>
                <div class="form-row" style="grid-column: 1/-1">
                    <label class="label">Комментарий / Условия работы</label>
                    <input id="sup-card-comment" v-model="card.comment" type="text" placeholder="Условия оплаты, скидки, особенности..." />
                </div>
            </div>
            <div id="sup-card-error" style="font-size: 12px; margin-top: 8px; min-height: 16px" :style="{ color: card.color }">{{ card.message }}</div>

            <!-- Учёт расходов по этому поставщику (для отчёта "Расходы по категориям и статьям") -->
            <div style="margin-top: 16px; border-top: 1px solid var(--border); padding-top: 12px">
                <div style="font-weight: 600; font-size: 13px; margin-bottom: 8px">📊 Учёт расходов (для отчёта P&amp;L)</div>
                <div style="font-size: 11px; color: var(--text3); margin-bottom: 8px">
                    Если этот поставщик оказывает одну постоянную услугу (например, заточка, аренда) — укажите статью расходов здесь, и все его
                    ЭСФ/оплаты будут попадать в P&amp;L под этой статьёй, а не общей строкой "Поставщик".
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div class="form-row">
                        <label class="label">Категория расхода</label>
                        <select id="sup-card-exp-cat" v-model="card.expenseCategoryId" style="font-size: 13px" @change="onExpenseCategoryChange">
                            <option value="">— не задано —</option>
                            <option v-for="c in props.expenseCategories" :key="c.id" :value="c.id">{{ c.name + deletedSuffix(c.deleted) }}</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label class="label">Статья расхода</label>
                        <select id="sup-card-exp-subcat" v-model="card.expenseArticleId" style="font-size: 13px">
                            <option value="">— не задано —</option>
                            <option v-for="a in articles" :key="a.id" :value="a.id">{{ a.name + deletedSuffix(a.deleted) }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-row" style="margin-top: 8px">
                    <label class="label">Считать расходы по:</label>
                    <div style="display: flex; gap: 16px; font-size: 13px; margin-top: 4px">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer">
                            <input v-model="card.expenseSource" type="radio" name="sup-card-exp-source" value="payment" style="width: auto" /> Оплате (по умолчанию)
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer">
                            <input v-model="card.expenseSource" type="radio" name="sup-card-exp-source" value="esf" style="width: auto" /> ЭСФ (счёт-фактуре)
                        </label>
                    </div>
                </div>
            </div>

            <!-- ЭСФ поставщика: legacy loads them from incoming_invoices; that module is not migrated yet. -->
            <div v-show="card.id !== null" id="sup-card-esf-section" style="margin-top: 16px; border-top: 1px solid var(--border); padding-top: 12px">
                <div style="font-weight: 600; font-size: 13px; margin-bottom: 8px">📄 Входящие ЭСФ</div>
                <div id="sup-card-esf-list" style="font-size: 12px; max-height: 200px; overflow-y: auto">
                    <div style="color: var(--text3); padding: 8px">ЭСФ поставщика появятся после переноса раздела «Входящие ЭСФ»</div>
                </div>
            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
                <button
                    v-show="card.id !== null"
                    id="sup-card-delete"
                    class="btn btn-ghost btn-sm"
                    style="margin-right: auto; color: var(--red)"
                    :disabled="card.busy"
                    @click="deleteSupplier"
                >
                    🗑 Удалить
                </button>
                <button class="btn btn-ghost" @click="closeCard">Отмена</button>
                <button class="btn btn-primary" :disabled="card.busy || card.saved" @click="saveSupplierCard">Сохранить</button>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
