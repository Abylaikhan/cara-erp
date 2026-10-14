<script setup lang="ts">
// Tab «Алиасы» of «Склад» — legacy wh-tab-aliases (L2655–2668), aliasModal (L2672–2725), loadAliasesFromDB/renderAliases/
// deleteAlias/openAliasModalFor/saveAlias/updateAliasPreview (L27389–27540). Same grouping and texts. In legacy neither
// «+ Добавить алиас» (openAliasModal is not defined) nor «+ Алиас» (the #alias-error element is missing) can open the form;
// here both open it, the supplier list is filled and the preview works (docs/adr/0008-warehouse.md).
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { MaterialAliasRow, MaterialRow } from '@/lib/warehouse';

const props = defineProps<{ aliases: MaterialAliasRow[]; materials: MaterialRow[]; suppliers: { id: number; name: string }[] }>();

const search = ref('');

// Grouped by the main material (legacy byMain), groups in Russian alphabetical order, aliases in the loaded order.
const groups = computed(() => {
    const q = search.value.toLowerCase();
    const byMain = new Map<string, { cat: string; aliases: MaterialAliasRow[] }>();
    props.aliases.forEach((a) => {
        if (q && !a.alias_name.toLowerCase().includes(q) && !a.material_name.toLowerCase().includes(q)) return;
        if (!byMain.has(a.material_name)) byMain.set(a.material_name, { cat: a.category || '', aliases: [] });
        byMain.get(a.material_name)!.aliases.push(a);
    });
    return [...byMain.entries()].sort((a, b) => a[0].localeCompare(b[0], 'ru'));
});

function deleteAlias(a: MaterialAliasRow): void {
    if (!window.confirm('Удалить этот алиас?')) return;
    router.delete(`/warehouse/aliases/${a.id}`, {
        preserveScroll: true,
        preserveState: true,
        only: ['aliases'],
        onError: (errors: Record<string, string>) => window.alert('Ошибка: ' + (Object.values(errors)[0] ?? '')),
    });
}

// ── aliasModal ──
const modal = reactive({ open: false, fixed: null as string | null, material: '', name: '', supplier: '' as number | '', coeff: '1', comment: '', error: '', busy: false });
const materialNames = computed(() => (modal.fixed !== null ? [modal.fixed] : [...new Set(props.materials.map((m) => m.name))]));
const material = computed(() => props.materials.find((m) => m.name === modal.material));
const coeffNote = computed(() => (material.value ? `1 ед. из накладной = ${parseFloat(modal.coeff || '1')} ${material.value.unit || 'ед.'} склада` : '1 единица из накладной = 1 единица склада'));

function openAliasModal(mainName: string | null): void {
    modal.fixed = mainName;
    modal.material = mainName ?? '';
    modal.name = '';
    modal.supplier = '';
    modal.coeff = '1';
    modal.comment = '';
    modal.error = '';
    modal.open = true;
}

function saveAlias(): void {
    if (!modal.material) return void (modal.error = 'Выберите основной материал');
    if (!modal.name.trim()) return void (modal.error = 'Введите алиас');
    modal.error = '';
    router.post(
        '/warehouse/aliases',
        { material_name: modal.material, alias_name: modal.name.trim(), supplier_id: modal.supplier || null, coeff: modal.coeff, comment: modal.comment },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['aliases'],
            onStart: () => (modal.busy = true),
            onFinish: () => (modal.busy = false),
            onError: (errors: Record<string, string>) => (modal.error = Object.values(errors)[0] ?? 'Ошибка сохранения'),
            onSuccess: () => (modal.open = false),
        },
    );
}

defineExpose({ openAliasModal });
</script>

<template>
    <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap">
        <div style="font-size: 13px; color: var(--text2)">Сопоставление названий из накладных поставщиков с материалами склада</div>
        <div style="flex: 1"></div>
        <input id="alias-search" v-model="search" type="text" placeholder="Поиск по названию..." style="font-size: 13px; width: 240px" />
        <button class="btn btn-primary btn-sm" @click="openAliasModal(null)">+ Добавить алиас</button>
    </div>

    <!-- По каждому материалу -->
    <div id="wh-aliases-body">
        <template v-if="!groups.length">
            <div v-if="!props.aliases.length" style="text-align: center; padding: 40px; color: var(--text3)">Алиасов пока нет.</div>
            <div v-else style="text-align: center; padding: 20px; color: var(--text3)">Ничего не найдено</div>
        </template>
        <div v-for="[mainName, data] in groups" :key="mainName" class="card" style="padding: 0; overflow: hidden; margin-bottom: 10px">
            <div style="padding: 10px 14px; background: var(--bg3); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between">
                <div>
                    <span style="font-weight: 600; font-size: 13px">{{ mainName }}</span>{{ ' ' }}<span class="badge badge-blue" style="font-size: 10px; margin-left: 8px">{{
                        data.cat
                    }}</span>
                </div>
                <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openAliasModal(mainName)">+ Алиас</button>
            </div>
            <div style="padding: 8px 12px">
                <div v-for="a in data.aliases" :key="a.id" style="display: flex; align-items: center; gap: 10px; padding: 6px 12px; background: var(--bg3); border-radius: 6px; margin-bottom: 4px">
                    <span style="font-size: 12px; color: var(--amber)">↳</span>
                    <span style="flex: 1; font-size: 13px">{{ a.alias_name }}</span>
                    <button class="btn btn-ghost btn-sm" style="font-size: 11px; color: var(--red)" @click="deleteAlias(a)">✕</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Алиас материала -->
    <ErpModal id="aliasModal" :open="modal.open" max-width="500px" @close="modal.open = false">
        <div class="modal-header">
            <span id="alias-modal-title" class="modal-title">Добавить алиас</span>
            <button class="modal-close" @click="modal.open = false">✕</button>
        </div>
        <div style="display: grid; gap: 12px; padding: 0 4px">
            <div class="form-row">
                <label class="label">Основной материал (из справочника склада) *</label>
                <select id="alias-material-id" v-model="modal.material" style="font-size: 13px">
                    <option v-if="modal.fixed === null" value="" disabled>— выберите материал —</option>
                    <option v-for="n in materialNames" :key="n" :value="n">{{ n }}</option>
                </select>
            </div>
            <div class="form-row">
                <label class="label">Название из накладной поставщика *</label>
                <input id="alias-name" v-model="modal.name" type="text" placeholder="Например: ЛДСП Egger 16/2800/2070 W908 ST2" />
            </div>
            <div class="form-row">
                <label class="label">Поставщик (необязательно)</label>
                <select id="alias-supplier" v-model="modal.supplier" style="font-size: 13px">
                    <option value="">— любой поставщик —</option>
                    <option v-for="s in props.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div class="form-row">
                <label class="label">Коэффициент пересчёта</label>
                <div style="display: flex; gap: 8px; align-items: center">
                    <input id="alias-coeff" v-model="modal.coeff" type="number" min="0.001" step="0.001" style="width: 80px" />
                    <span id="alias-coeff-note" style="font-size: 12px; color: var(--text3)">{{ coeffNote }}</span>
                </div>
            </div>
            <div class="form-row">
                <label class="label">Комментарий</label>
                <input id="alias-comment" v-model="modal.comment" type="text" placeholder="Артикул, цвет, уточнение..." />
            </div>
            <!-- Предпросмотр -->
            <div v-show="modal.name && material" id="alias-preview" style="padding: 10px; background: var(--bg3); border-radius: 8px; font-size: 12px; color: var(--text2)">
                <div style="margin-bottom: 6px; font-size: 11px; color: var(--text3)">Предпросмотр сопоставления:</div>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                    <div style="background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; font-size: 12px">
                        📄 <b>{{ modal.name || '...' }}</b><br />
                        <span style="color: var(--text3); font-size: 11px">Название из накладной</span>
                    </div>
                    <div style="font-size: 18px; color: var(--accent)">→</div>
                    <div style="background: var(--bg2); border: 2px solid var(--accent); border-radius: 6px; padding: 6px 10px; font-size: 12px">
                        📦 <b>{{ material?.name || '...' }}</b><br />
                        <span style="color: var(--text3); font-size: 11px">Материал склада · коэф. {{ parseFloat(modal.coeff || '1') }}</span>
                    </div>
                </div>
            </div>
            <div id="alias-error" style="color: var(--red); font-size: 12px; min-height: 16px">{{ modal.error }}</div>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
            <button class="btn btn-ghost" @click="modal.open = false">Отмена</button>
            <button class="btn btn-primary" :disabled="modal.busy" @click="saveAlias">Сохранить</button>
        </div>
    </ErpModal>
</template>
