<script setup lang="ts">
// M07 «Каталог» — legacy page-catalog (L3551–3637), renderCatalog (L1145), openProductModal (L1047), saveProduct (L1094),
// deleteProduct (L1134). Same markup, texts and calculations; saving, rights and deletion are checked by the server.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

interface Product {
    id: number;
    name: string;
    category: string;
    price_retail: number;
    price_wholesale: number;
    unit: string;
    sku: string | null;
    description: string | null;
    delete_requested: boolean;
    delete_requested_by: string | null;
    created_by: string | null;
    updated_by: string | null;
    created_at: string | null;
    can_edit: boolean;
    lock_version: number;
}

const props = defineProps<{ products: Product[]; canDelete: boolean }>();

const cat = ref('');
const search = ref('');

const categories = computed(() => [...new Set(props.products.map((p) => p.category))].sort());
const filtered = computed(() => {
    const q = search.value.toLowerCase();
    return props.products.filter((p) => (!cat.value || p.category === cat.value) && (!q || p.name.toLowerCase().includes(q) || p.category.toLowerCase().includes(q)));
});

const money = (n: number) => '₸' + Math.round(n).toLocaleString('ru-RU');
const fmt = (n: number) => (n ? money(n) : '—');
const discount = (p: Product) => (p.price_retail && p.price_wholesale ? Math.round((1 - p.price_wholesale / p.price_retail) * 100) + '%' : '—');

const kpis = computed(() => {
    const priced = filtered.value.filter((p) => p.price_retail > 0).map((p) => p.price_retail);
    // Legacy shows «₸∞ — ₸-∞» when nothing is priced; here a dash (docs/adr/0002-ui-deviations.md).
    const range = priced.length ? money(Math.min(...priced)) + ' — ' + money(Math.max(...filtered.value.map((p) => p.price_retail))) : '—';
    return [
        { label: 'Всего позиций', value: props.products.length, color: 'var(--accent)' },
        { label: 'Показано', value: filtered.value.length, color: 'var(--green)' },
        { label: 'Категорий', value: categories.value.length, color: 'var(--amber)' },
        { label: 'Диапазон цен', value: range, color: 'var(--text2)' },
    ];
});

// ── Product modal (productModal) ──────────────────────────────────────────
const modal = reactive({
    open: false,
    id: null as number | null,
    title: '',
    name: '',
    category: '',
    retail: '0' as string,
    wholesale: '0' as string,
    unit: 'шт',
    sku: '',
    desc: '',
    deleteRequested: false,
    canEdit: true,
    lockVersion: 0,
    meta: '',
    error: '',
    busy: false,
});

function openProductModal(p: Product | null): void {
    modal.id = p?.id ?? null;
    modal.title = p ? 'Редактировать товар' : '+ Новый товар';
    modal.name = p?.name ?? '';
    modal.category = p?.category ?? '';
    modal.retail = String(p?.price_retail || 0);
    modal.wholesale = String(p?.price_wholesale || 0);
    modal.unit = p?.unit || 'шт';
    modal.sku = p?.sku ?? '';
    modal.desc = p?.description ?? '';
    modal.deleteRequested = p?.delete_requested ?? false;
    modal.canEdit = p ? p.can_edit : true;
    modal.lockVersion = p?.lock_version ?? 0;
    modal.error = '';
    const parts: string[] = [];
    if (p?.created_by) parts.push(`Добавил: ${p.created_by}`);
    if (p?.created_at) parts.push(new Date(p.created_at).toLocaleDateString('ru-RU'));
    if (p?.updated_by) parts.push(`Изменил: ${p.updated_by}`);
    if (p?.delete_requested) parts.push(`⚠️ Помечен на удаление (${p.delete_requested_by})`);
    modal.meta = parts.join(' · ');
    modal.open = true;
}

const options = {
    preserveScroll: true,
    preserveState: true,
    onStart: () => (modal.busy = true),
    onFinish: () => (modal.busy = false),
    onError: (errors: Record<string, string>) => (modal.error = Object.values(errors)[0] ?? 'Ошибка сохранения'),
    onSuccess: () => (modal.open = false),
};

function saveProduct(): void {
    if (!modal.name.trim()) return void (modal.error = 'Введите наименование');
    if (!modal.category.trim()) return void (modal.error = 'Введите категорию');
    const data = {
        name: modal.name.trim(),
        category: modal.category.trim(),
        price_retail: parseFloat(modal.retail) || 0,
        price_wholesale: parseFloat(modal.wholesale) || 0,
        unit: modal.unit || 'шт',
        sku: modal.sku.trim() || null,
        description: modal.desc.trim() || null,
        delete_requested: modal.deleteRequested,
    };
    if (modal.id === null) {
        router.post('/catalog', data, options);
    } else {
        router.put(`/catalog/${modal.id}`, { ...data, lock_version: modal.lockVersion }, options);
    }
}

function deleteProduct(): void {
    if (modal.id === null || !window.confirm('Удалить товар из каталога?')) return;
    router.delete(`/catalog/${modal.id}`, { ...options, onError: (errors: Record<string, string>) => window.alert('Ошибка: ' + (Object.values(errors)[0] ?? '')) });
}

function addProductToOrder(): void {
    // Legacy opens the new order form here; creating orders waits for the decisions on the saved price and content.
    window.alert('Создание заказа появится после решений по сохраняемой цене и составу заказа (D-02, D-12).');
}
</script>

<template>
    <ErpLayout>
        <div id="page-catalog" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Каталог продукции</div>
                    <div class="section-sub">Стандартные изделия · розничные и оптовые цены</div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap">
                    <select id="catalog-cat-filter" v-model="cat" style="font-size: 13px; min-width: 160px">
                        <option value="">Все категории</option>
                        <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <input id="catalog-search" v-model="search" type="search" placeholder="🔍 Поиск по названию..." style="font-size: 13px; width: 220px" />
                    <span id="catalog-count" style="font-size: 12px; color: var(--text3)">{{ filtered.length }} позиций</span>
                    <button class="btn btn-primary btn-sm" @click="openProductModal(null)">+ Добавить товар</button>
                </div>
            </div>

            <div id="catalog-kpi" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px">
                <div v-for="k in kpis" :key="k.label" class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">{{ k.label }}</div>
                    <div style="font-size: 16px; font-weight: 700" :style="{ color: k.color }">{{ k.value }}</div>
                </div>
            </div>

            <div class="card" style="padding: 0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Наименование</th>
                            <th>Категория</th>
                            <th style="text-align: right">Розница (₸)</th>
                            <th style="text-align: right">Опт (₸)</th>
                            <th style="text-align: right">Скидка</th>
                            <th>Добавил</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="catalog-tbody">
                        <tr v-for="(p, i) in filtered" :key="p.id">
                            <td style="color: var(--text3); font-size: 12px">{{ i + 1 }}</td>
                            <td style="font-weight: 500">
                                {{ p.name }}<template v-if="p.delete_requested">{{ ' ' }}<span style="color: var(--red); font-size: 10px">🗑</span></template>
                            </td>
                            <td><span class="badge badge-blue" style="font-size: 10px">{{ p.category }}</span></td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600">{{ fmt(p.price_retail) }}</td>
                            <td style="text-align: right; font-family: monospace; color: var(--green)">{{ fmt(p.price_wholesale) }}</td>
                            <td style="text-align: right; font-size: 12px; color: var(--amber)">{{ discount(p) }}</td>
                            <td>
                                <span v-if="p.created_by" style="font-size: 10px; color: var(--text3)">{{ p.created_by }}</span>
                                <template v-else>—</template>
                            </td>
                            <td style="display: flex; gap: 4px">
                                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openProductModal(p)">✏️</button>
                                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="addProductToOrder">+ В заказ</button>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="8" style="text-align: center; padding: 20px; color: var(--text3)">Ничего не найдено</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ErpModal id="productModal" :open="modal.open" max-width="480px" @close="modal.open = false">
            <div class="modal-header">
                <span id="product-modal-title">{{ modal.title }}</span>
                <button class="modal-close" @click="modal.open = false">✕</button>
            </div>
            <div style="padding: 16px; display: flex; flex-direction: column; gap: 12px">
                <div>
                    <label class="label">Наименование *</label>
                    <input id="pm-name" v-model="modal.name" type="text" placeholder="Название товара..." :disabled="!modal.canEdit" />
                </div>
                <div>
                    <label class="label">Категория *</label>
                    <input id="pm-category" v-model="modal.category" type="text" list="pm-cat-list" placeholder="Категория..." :disabled="!modal.canEdit" />
                    <datalist id="pm-cat-list">
                        <option v-for="c in categories.filter(Boolean)" :key="c" :value="c" />
                    </datalist>
                </div>
                <div class="form-row cols-2">
                    <div>
                        <label class="label">Розничная цена (₸)</label>
                        <input id="pm-price-retail" v-model="modal.retail" type="number" min="0" placeholder="0" :disabled="!modal.canEdit" />
                    </div>
                    <div>
                        <label class="label">Оптовая цена (₸)</label>
                        <input id="pm-price-wholesale" v-model="modal.wholesale" type="number" min="0" placeholder="0" :disabled="!modal.canEdit" />
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div>
                        <label class="label">Единица</label>
                        <select id="pm-unit" v-model="modal.unit" :disabled="!modal.canEdit">
                            <option value="шт">шт</option>
                            <option value="м.п.">м.п.</option>
                            <option value="м²">м²</option>
                            <option value="компл">компл</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Артикул</label>
                        <input id="pm-sku" v-model="modal.sku" type="text" placeholder="Необязательно" :disabled="!modal.canEdit" />
                    </div>
                </div>
                <div>
                    <label class="label">Описание</label>
                    <textarea id="pm-desc" v-model="modal.desc" rows="2" style="width: 100%; resize: vertical" placeholder="Необязательно" :disabled="!modal.canEdit"></textarea>
                </div>
                <div id="pm-meta" style="font-size: 11px; color: var(--text3)">{{ modal.meta }}</div>
                <div v-show="modal.id !== null && !canDelete" id="pm-delete-request">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: var(--red)">
                        <input id="pm-delete-check" v-model="modal.deleteRequested" type="checkbox" /> Пометить на удаление
                    </label>
                </div>
                <div id="pm-error" style="color: var(--red); font-size: 12px">{{ modal.error }}</div>
                <div style="display: flex; gap: 8px; justify-content: flex-end">
                    <div v-show="modal.id !== null && canDelete" id="pm-delete-btn-wrap">
                        <button class="btn btn-ghost btn-sm" style="color: var(--red)" :disabled="modal.busy" @click="deleteProduct">🗑 Удалить</button>
                    </div>
                    <button class="btn btn-ghost btn-sm" @click="modal.open = false">Отмена</button>
                    <button class="btn btn-primary" :disabled="modal.busy" @click="saveProduct">Сохранить</button>
                </div>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
