<script setup lang="ts">
// «+ Материал» of «Склад» — legacy addMaterialModal (L4550–4631), openAddMaterialModal/selectMatCategory/renderCatalogChips/
// selectCatalogName/onAddMatNameInput/showCategoryItems/checkMaterialDuplicates/saveNewMaterial (L28536–28830). Same two steps,
// standard names and duplicate scoring. Legacy kept the new material only in the page and stored the form's category code
// («lumber») instead of a directory category; here the server saves it with a directory category chosen in step 2, and the
// starting quantity comes by a receipt document (docs/adr/0008-warehouse.md).
import { router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import { FORM_UNITS, MAT_CATEGORIES, MAT_NAMES_CATALOG, findMatCategory } from '@/lib/materialCatalog';
import type { MaterialRow } from '@/lib/warehouse';

const props = defineProps<{ materials: MaterialRow[] }>();

const open = ref(false);
const step = ref<1 | 2>(1);
const form = reactive({ catId: null as string | null, category: '', name: '', attrs: '', unit: 'шт', price: '', minQty: '', sku: '' });
const message = reactive({ text: '', color: 'var(--red)' });
const busy = ref(false);
const acOpen = ref(false);
const touched = ref(false); // legacy shows the duplicate panel only after the name is typed or picked
const nameInput = ref<HTMLInputElement | null>(null);

const directoryCategories = computed(() => [...new Set(props.materials.map((m) => m.category).filter(Boolean))].sort());
const cat = computed(() => findMatCategory(form.catId));

function openAddMaterialModal(): void {
    form.catId = null;
    step.value = 1;
    open.value = true;
}

function selectMatCategory(id: string): void {
    form.catId = id;
    form.category = findMatCategory(id)?.directory ?? 'Прочее';
    Object.assign(form, { name: '', attrs: '', unit: 'шт', price: '', minQty: '', sku: '' });
    message.text = '';
    message.color = 'var(--red)';
    acOpen.value = false;
    touched.value = false;
    chipsVisible.value = true;
    step.value = 2;
    void nextTick(() => nameInput.value?.focus());
}

// ── Standard names: chips (≤20) and autocomplete (≤10) from MAT_NAMES_CATALOG ──
const norm = (s: string) => s.toLowerCase();
const catalogNames = computed(() => (form.catId ? MAT_NAMES_CATALOG[form.catId] || [] : []));
const chipsVisible = ref(true);
const chips = computed(() => {
    const q = form.name.trim();
    return (q ? catalogNames.value.filter((n) => norm(n).includes(norm(q))) : catalogNames.value).slice(0, 20);
});
const suggestions = computed(() => {
    const q = form.name.trim();
    return q ? catalogNames.value.filter((n) => norm(n).includes(norm(q))).slice(0, 10) : [];
});
function highlight(n: string): { text: string; bold: boolean }[] {
    const q = form.name.trim();
    if (!q) return [{ text: n, bold: false }];
    const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return n.split(re).filter((t) => t !== '').map((t) => ({ text: t, bold: t.toLowerCase() === q.toLowerCase() }));
}
function onNameInput(): void {
    touched.value = true;
    acOpen.value = suggestions.value.length > 0;
    chipsVisible.value = true;
}
function selectCatalogName(n: string): void {
    touched.value = true;
    form.name = n;
    acOpen.value = false;
    chipsVisible.value = false;
    void nextTick(() => document.getElementById('add-mat-attrs')?.focus());
}

// ── Duplicate check (checkMaterialDuplicates): same category scored ≥ 15, other categories ≥ 50 ──
const dupNorm = (s: string) => s.toLowerCase().replace(/[.,\-()]/g, ' ').replace(/\s+/g, ' ').trim();
const CONTEXT_LIMIT = 10;
const duplicates = computed(() => {
    const input = form.name.trim();
    const same = props.materials.filter((w) => w.category === form.category);
    if (!input || input.length < 2) return { mode: 'context' as const, same, other: [] as { w: MaterialRow; s: number }[] };
    const tokens = dupNorm(input).split(' ').filter((t) => t.length > 1);
    const score = (item: MaterialRow): number => {
        const n = dupNorm(item.name);
        let s = 0;
        if (n === dupNorm(input)) s += 100;
        else if (n.startsWith(dupNorm(input))) s += 60;
        else if (dupNorm(input).startsWith(n)) s += 50;
        tokens.forEach((tok) => {
            if (n.includes(tok)) s += 20;
        });
        n.split(' ')
            .filter((t) => t.length > 1)
            .forEach((tok) => {
                if (dupNorm(input).includes(tok)) s += 15;
            });
        return s;
    };
    const sameC = same.map((w) => ({ w, s: score(w) })).filter((x) => x.s >= 15).sort((a, b) => b.s - a.s);
    const otherC = props.materials
        .filter((w) => w.category !== form.category)
        .map((w) => ({ w, s: score(w) }))
        .filter((x) => x.s >= 50)
        .sort((a, b) => b.s - a.s)
        .slice(0, 3);
    if (!sameC.length && !otherC.length) return { mode: 'context' as const, same, other: [] as { w: MaterialRow; s: number }[] };
    return { mode: 'match' as const, same: sameC.map((x) => x.w), other: otherC, input };
});
const isExact = (w: MaterialRow) => dupNorm(w.name) === dupNorm(form.name.trim());
const priceText = (w: MaterialRow) => '₸' + w.price.toLocaleString('ru-RU');

function saveNewMaterial(): void {
    const base = form.name.trim();
    if (!base) return void Object.assign(message, { text: 'Введите или выберите наименование материала', color: 'var(--red)' });
    if (!form.catId || !form.category) return void Object.assign(message, { text: 'Выберите категорию', color: 'var(--red)' });
    const fullName = form.attrs.trim() ? base + ' ' + form.attrs.trim() : base;
    router.post(
        '/warehouse/materials',
        { name: base, attrs: form.attrs, category: form.category, unit: form.unit, price: form.price, min_qty: form.minQty, sku: form.sku },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['materials', 'stockValue'],
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onError: (errors: Record<string, string>) => Object.assign(message, { text: Object.values(errors)[0] ?? 'Ошибка сохранения', color: 'var(--red)' }),
            onSuccess: () => {
                Object.assign(message, { text: '✓ Материал «' + fullName + '» добавлен на склад!', color: 'var(--green)' });
                window.setTimeout(() => {
                    message.text = '';
                    open.value = false;
                }, 900);
            },
        },
    );
}

defineExpose({ openAddMaterialModal });
</script>

<template>
    <ErpModal id="addMaterialModal" :open="open" max-width="560px" @close="open = false">
        <div class="modal-header">
            <div class="modal-title">Новый материал</div>
            <button class="modal-close" @click="open = false">✕</button>
        </div>

        <!-- STEP 1: Choose category -->
        <div v-show="step === 1" id="add-mat-step1">
            <div style="font-size: 13px; color: var(--text2); margin-bottom: 14px">
                <strong>Шаг 1 из 2.</strong> Выберите категорию материала.<br />
                <span style="color: var(--text3); font-size: 12px">Это поможет программе проверить, нет ли похожего материала уже на складе.</span>
            </div>
            <div id="mat-category-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px">
                <button
                    v-for="c in MAT_CATEGORIES"
                    :key="c.id"
                    class="mat-cat-btn"
                    style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px; background: var(--bg3); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; cursor: pointer; text-align: left; transition: all 0.15s; width: 100%"
                    @click="selectMatCategory(c.id)"
                >
                    <div style="font-size: 20px">{{ c.icon }}</div>
                    <div style="font-size: 12.5px; font-weight: 500; color: var(--text1)">{{ c.label }}</div>
                    <div style="font-size: 11px; color: var(--text3); line-height: 1.35">{{ c.hint }}</div>
                </button>
            </div>
        </div>

        <!-- STEP 2: Fill in details -->
        <div v-show="step === 2" id="add-mat-step2">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px">
                <button class="btn btn-ghost btn-sm" @click="step = 1">← Назад</button>
                <div style="font-size: 13px; color: var(--text2)">
                    <strong>Шаг 2 из 2.</strong> Категория: <span id="add-mat-cat-label" style="color: var(--accent)">{{ cat ? cat.icon + ' ' + cat.label : form.catId }}</span>
                </div>
            </div>

            <!-- Catalog name picker -->
            <div class="form-row">
                <label class="label"
                    >Наименование материала{{ ' ' }}<span style="font-size: 11px; color: var(--text3); font-weight: 400; margin-left: 6px">— выберите из справочника или введите своё</span>
                </label>
                <div style="position: relative">
                    <input
                        id="add-mat-name"
                        ref="nameInput"
                        v-model="form.name"
                        type="text"
                        placeholder="Начните вводить или выберите из списка..."
                        autocomplete="off"
                        @input="onNameInput"
                        @blur="acOpen = false"
                    />
                    <div
                        v-show="acOpen && suggestions.length"
                        id="add-mat-autocomplete"
                        style="position: absolute; top: 100%; left: 0; right: 0; z-index: 100; background: var(--bg2); border: 1px solid var(--border); border-top: none; border-radius: 0 0 8px 8px; max-height: 220px; overflow-y: auto; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.18)"
                    >
                        <div
                            v-for="n in suggestions"
                            :key="n"
                            class="mat-ac-item"
                            style="padding: 8px 12px; font-size: 13px; cursor: pointer; border-bottom: 1px solid var(--border)"
                            @mousedown.prevent="selectCatalogName(n)"
                        >
                            <template v-for="(part, i) in highlight(n)" :key="i"><strong v-if="part.bold">{{ part.text }}</strong><template v-else>{{ part.text }}</template></template>
                        </div>
                    </div>
                </div>
                <!-- Catalog chips — shown when field is empty -->
                <div id="add-mat-catalog-chips" style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px">
                    <template v-if="chipsVisible && chips.length">
                        <div style="font-size: 11px; color: var(--text3); width: 100%; margin-bottom: 4px">
                            {{ form.name.trim() ? 'Совпадения в справочнике:' : 'Стандартные наименования этой категории:' }}
                        </div>
                        <span
                            v-for="n in chips"
                            :key="n"
                            class="mat-chip"
                            style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; background: var(--bg3); border: 1px solid var(--border); cursor: pointer; transition: all 0.12s; white-space: nowrap"
                            @click="selectCatalogName(n)"
                            >{{ n }}</span
                        >
                    </template>
                </div>
            </div>

            <!-- Характеристики — shown after name is chosen -->
            <div v-show="form.name.trim()" id="add-mat-attrs-section">
                <div class="form-row">
                    <label class="label"
                        >Характеристики{{ ' ' }}<span style="font-size: 11px; color: var(--text3); font-weight: 400; margin-left: 4px">(толщина, цвет, размер, декор и т.д.)</span>
                    </label>
                    <input id="add-mat-attrs" v-model="form.attrs" type="text" placeholder="Напр.: 16мм, Дуб молочный, 2750×1830мм" />
                </div>
            </div>

            <!-- Category of the warehouse directory (legacy stored the form's category code instead) -->
            <div class="form-row">
                <label class="label">Категория на складе</label>
                <select id="add-mat-directory-cat" v-model="form.category">
                    <option v-for="c in directoryCategories" :key="c" :value="c">{{ c }}</option>
                    <option v-if="form.category && !directoryCategories.includes(form.category)" :value="form.category">{{ form.category }}</option>
                </select>
            </div>

            <!-- Duplicate warning panel -->
            <div
                v-show="touched && (duplicates.same.length || duplicates.other.length)"
                id="add-mat-dup-panel"
                style="background: rgba(245, 166, 35, 0.1); border: 1px solid var(--amber); border-radius: 8px; padding: 12px; margin-bottom: 14px"
            >
                <div style="font-size: 12.5px; font-weight: 600; color: var(--amber); margin-bottom: 8px">⚠ В этой категории уже есть похожие позиции — проверьте, нет ли дубля:</div>
                <div id="add-mat-dup-list">
                    <template v-if="duplicates.mode === 'context'">
                        <div style="font-size: 11.5px; color: var(--text3); margin-bottom: 6px">Позиции этой категории на складе ({{ duplicates.same.length }} шт.):</div>
                        <div
                            v-for="w in duplicates.same.slice(0, CONTEXT_LIMIT)"
                            :key="w.id"
                            style="display: flex; justify-content: space-between; font-size: 12px; padding: 4px 8px; border-radius: 6px; background: var(--bg2); margin-bottom: 4px"
                        >
                            <span style="font-weight: 500">{{ w.name }}</span>
                            <span style="font-family: 'IBM Plex Mono', monospace; color: var(--text3)">{{ w.qty }} {{ w.unit }} · {{ priceText(w) }}</span>
                        </div>
                        <div v-if="duplicates.same.length > CONTEXT_LIMIT" style="font-size: 11px; color: var(--text3)">… и ещё {{ duplicates.same.length - CONTEXT_LIMIT }}</div>
                    </template>
                    <template v-else>
                        <template v-if="duplicates.same.length">
                            <div style="font-size: 11.5px; font-weight: 600; color: var(--amber); margin-bottom: 6px">Похожие позиции в этой категории:</div>
                            <div
                                v-for="w in duplicates.same"
                                :key="w.id"
                                style="display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 6px; margin-bottom: 4px"
                                :style="{ background: isExact(w) ? 'rgba(245,92,92,.1)' : 'var(--bg2)', border: '1px solid ' + (isExact(w) ? 'var(--red)' : 'var(--border)') }"
                            >
                                <div>
                                    <span style="font-weight: 500; font-size: 12.5px" :style="isExact(w) ? 'color:var(--red)' : ''">{{ w.name }}</span
                                    ><span v-if="isExact(w)" style="font-size: 10.5px; color: var(--red); margin-left: 6px">← возможный дубль!</span>
                                    <div style="font-size: 11px; color: var(--text3)">{{ w.category }} · остаток: {{ w.qty }} {{ w.unit }}</div>
                                </div>
                                <span style="font-family: 'IBM Plex Mono', monospace; font-size: 12px; flex-shrink: 0">{{ priceText(w) }}</span>
                            </div>
                        </template>
                        <template v-if="duplicates.other.length">
                            <div style="font-size: 11px; color: var(--text3); margin-top: 8px; margin-bottom: 4px">Похожее в других категориях:</div>
                            <div
                                v-for="{ w } in duplicates.other"
                                :key="w.id"
                                style="display: flex; justify-content: space-between; font-size: 11.5px; padding: 4px 8px; border-radius: 6px; background: var(--bg3); margin-bottom: 3px; opacity: 0.8"
                            >
                                <span>{{ w.name }} <span style="color: var(--text3)">({{ w.category || '—' }})</span></span>
                                <span style="font-family: 'IBM Plex Mono', monospace; color: var(--text3)">{{ priceText(w) }}</span>
                            </div>
                        </template>
                    </template>
                </div>
            </div>

            <div id="add-mat-form-error" style="font-size: 13px; margin-bottom: 8px; min-height: 18px" :style="{ color: message.color }">{{ message.text }}</div>
            <div class="form-row cols-2">
                <div>
                    <label class="label">Количество</label
                    ><input id="add-mat-qty" type="number" min="0" step="0.1" placeholder="0" disabled title="Начальный остаток вносится приходом на склад" />
                </div>
                <div>
                    <label class="label">Единица измерения</label>
                    <select id="add-mat-unit" v-model="form.unit">
                        <option v-for="u in FORM_UNITS" :key="u">{{ u }}</option>
                    </select>
                </div>
            </div>
            <div class="form-row cols-2">
                <div><label class="label">Цена за единицу (₸)</label><input id="add-mat-price" v-model="form.price" type="number" min="0" placeholder="0" /></div>
                <div><label class="label">Мин. остаток</label><input id="add-mat-min-qty" v-model="form.minQty" type="number" min="0" placeholder="0" /></div>
            </div>
            <div class="form-row">
                <label class="label">SKU / артикул{{ ' ' }}<span style="font-size: 11px; color: var(--text3); font-weight: 400">(необязательно)</span></label>
                <input id="add-mat-sku" v-model="form.sku" type="text" placeholder="SKU-XXXX (или оставьте пустым — присвоится автоматически)" />
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
                <button class="btn btn-ghost" @click="open = false">Отмена</button>
                <button class="btn btn-primary" :disabled="busy" @click="saveNewMaterial">Добавить на склад</button>
            </div>
        </div>
    </ErpModal>
</template>
