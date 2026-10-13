<script setup lang="ts">
// M14 «Статьи расходов» — legacy page-expense_cats (L6786–6875) and renderExpCats/openExpCatModal/saveExpCat/
// deleteExpCat/mergeExpCats (L11068–11243). Same markup, texts and flow; data is saved and validated by the server.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import type { SharedProps } from '@/types';

interface Category {
    id: number;
    code: string;
    name: string;
    parent_id: number | null;
    parent_code: string | null;
    is_fixed: boolean;
    sort_order: number;
    lock_version: number;
}

const props = defineProps<{ categories: Category[] }>();
const page = usePage<SharedProps>();

const search = ref('');
const filterParent = ref('');
const onlyFixed = ref(false);

const bySort = (a: Category, b: Category) => a.sort_order - b.sort_order;
const topCats = computed(() => props.categories.filter((c) => c.parent_id === null).sort(bySort));
const shownTops = computed(() => topCats.value.filter((t) => !filterParent.value || t.code === filterParent.value));

function subsOf(top: Category): Category[] {
    const q = search.value.trim().toLowerCase();
    return props.categories
        .filter((c) => c.parent_id === top.id)
        .filter((c) => !onlyFixed.value || c.is_fixed)
        .filter((c) => !q || c.name.toLowerCase().includes(q) || c.code.toLowerCase().includes(q))
        .sort(bySort);
}

function firstError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Ошибка сохранения';
}

// ── Edit / create modal (expCatModal) ─────────────────────────────────────
const edit = reactive({
    open: false,
    title: '',
    isTop: false,
    id: null as number | null,
    lockVersion: 0,
    name: '',
    code: '',
    parentId: null as number | null,
    isFixed: false,
    sort: '50' as string,
    error: '',
    busy: false,
});

function openExpCatModal(cat: Category | null, defaultParent: Category | null = null, isTop?: boolean): void {
    const isTopLevel = isTop !== undefined ? isTop : cat ? cat.parent_id === null : false;
    edit.title = cat ? (isTopLevel ? 'Редактировать категорию' : 'Редактировать статью') : isTopLevel ? 'Новая категория' : 'Новая статья';
    edit.isTop = isTopLevel;
    edit.id = cat?.id ?? null;
    edit.lockVersion = cat?.lock_version ?? 0;
    edit.name = cat?.name ?? '';
    edit.code = cat?.code ?? '';
    edit.isFixed = cat?.is_fixed ?? false;
    edit.sort = String(cat?.sort_order ?? 50);
    edit.parentId = isTopLevel ? null : (cat?.parent_id ?? defaultParent?.id ?? topCats.value[0]?.id ?? null);
    edit.error = '';
    edit.open = true;
}

const requestOptions = (target: { error: string; busy: boolean; open: boolean }) => ({
    preserveScroll: true,
    preserveState: true,
    onStart: () => (target.busy = true),
    onFinish: () => (target.busy = false),
    onError: (errors: Record<string, string>) => (target.error = firstError(errors)),
    onSuccess: () => (target.open = false),
});

function saveExpCat(): void {
    const name = edit.name.trim();
    const code = edit.code.trim();
    if (!name) return void (edit.error = 'Введите название');
    if (!edit.id) {
        if (!code) return void (edit.error = 'Введите код');
        if (!/^[a-z0-9_]+$/.test(code)) return void (edit.error = 'Код — только латиница и цифры');
        if (props.categories.some((c) => c.code === code)) return void (edit.error = 'Такой код уже существует');
    }
    const fields = {
        name,
        parent_id: edit.isTop ? null : edit.parentId,
        is_fixed: edit.isFixed,
        sort_order: edit.sort === '' ? null : Number(edit.sort),
    };
    if (edit.id) {
        router.put(`/expense_cats/${edit.id}`, { ...fields, lock_version: edit.lockVersion }, requestOptions(edit));
    } else {
        router.post('/expense_cats', { ...fields, code, is_top: edit.isTop }, requestOptions(edit));
    }
}

function deleteExpCat(): void {
    if (!edit.id) return;
    const subs = props.categories.filter((c) => c.parent_id === edit.id);
    if (subs.length) return void (edit.error = `Нельзя удалить — есть ${subs.length} подстатей`);
    if (!window.confirm(`Удалить статью "${edit.code}"?`)) return;
    router.delete(`/expense_cats/${edit.id}`, requestOptions(edit));
}

// ── Merge modal (expCatMergeModal) ────────────────────────────────────────
const merge = reactive({ open: false, from: null as number | null, to: null as number | null, error: '', busy: false });
const mergeOptions = computed(() => props.categories.filter((c) => c.parent_id !== null).sort((a, b) => a.name.localeCompare(b.name)));

function openExpCatMergeModal(): void {
    merge.from = mergeOptions.value[0]?.id ?? null;
    merge.to = mergeOptions.value[0]?.id ?? null;
    merge.error = '';
    merge.open = true;
}

function mergeExpCats(): void {
    if (merge.from === merge.to) return void (merge.error = 'Выберите разные статьи');
    const from = props.categories.find((c) => c.id === merge.from);
    const to = props.categories.find((c) => c.id === merge.to);
    if (!from || !to) return;
    if (!window.confirm(`Перенести все транзакции из "${from.code}" в "${to.code}" и удалить "${from.code}"?`)) return;
    router.post(
        '/expense_cats/merge',
        { from_id: from.id, to_id: to.id },
        {
            ...requestOptions(merge),
            onSuccess: () => {
                merge.open = false;
                if (page.props.flash.alert) window.alert(page.props.flash.alert);
            },
        },
    );
}
</script>

<template>
    <ErpLayout>
        <div id="page-expense_cats" class="page active">
            <div class="page-header">
                <div class="page-title">📋 Справочник статей расходов</div>
                <button class="btn btn-primary btn-sm" @click="openExpCatModal(null)">+ Добавить статью</button>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center">
                <input
                    id="ec-search"
                    v-model="search"
                    type="search"
                    placeholder="🔍 Поиск по названию или коду..."
                    style="font-size: 13px; flex: 1; min-width: 200px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--text1)"
                />
                <select id="ec-filter-parent" v-model="filterParent" style="font-size: 13px">
                    <option value="">Все категории</option>
                    <option v-for="c in topCats" :key="c.id" :value="c.code">{{ c.name }}</option>
                </select>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px">
                    <input id="ec-show-fixed" v-model="onlyFixed" type="checkbox" /> Только постоянные
                </label>
            </div>

            <div id="ec-table-wrap">
                <div v-if="!topCats.length" style="text-align: center; padding: 40px; color: var(--text3)">Нет данных.</div>
                <template v-else>
                    <div style="margin-bottom: 24px">
                        <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 10px">Категории первого уровня</h3>
                        <table class="data-table" style="width: 100%">
                            <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Код</th>
                                    <th>Порядок</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in topCats" :key="c.id">
                                    <td style="font-weight: 600">{{ c.name }}</td>
                                    <td style="font-family: monospace; font-size: 12px; color: var(--text3)">{{ c.code }}</td>
                                    <td style="text-align: center; color: var(--text3)">{{ c.sort_order }}</td>
                                    <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openExpCatModal(c, null, true)">✏️</button></td>
                                </tr>
                            </tbody>
                        </table>
                        <button class="btn btn-ghost btn-sm" style="margin-top: 8px" @click="openExpCatModal(null, null, true)">+ Добавить категорию</button>
                    </div>

                    <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 10px">Статьи второго уровня</h3>

                    <div v-for="top in shownTops" :key="top.id" style="margin-bottom: 20px">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px">
                            <span style="font-weight: 600; font-size: 13px; color: var(--accent)">{{ top.name }}</span>
                            <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openExpCatModal(null, top, false)">+ Добавить</button>
                        </div>
                        <table class="data-table" style="width: 100%">
                            <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Код</th>
                                    <th>📌 Постоянный</th>
                                    <th>Порядок</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-if="subsOf(top).length">
                                    <tr v-for="s in subsOf(top)" :key="s.id">
                                        <td>{{ s.name }}</td>
                                        <td style="font-family: monospace; font-size: 12px; color: var(--text3)">{{ s.code }}</td>
                                        <td style="text-align: center">{{ s.is_fixed ? '📌 Да' : '—' }}</td>
                                        <td style="text-align: center; color: var(--text3)">{{ s.sort_order }}</td>
                                        <td style="white-space: nowrap">
                                            <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openExpCatModal(s, null, false)">✏️</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-else>
                                    <td colspan="5" style="text-align: center; color: var(--text3); padding: 8px">Нет статей</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 16px">
                        <button class="btn btn-ghost btn-sm" @click="openExpCatMergeModal">🔀 Объединить две статьи</button>
                    </div>
                </template>
            </div>

            <ErpModal id="expCatModal" :open="edit.open" max-width="480px" @close="edit.open = false">
                <div class="modal-header">
                    <div id="ec-modal-title" class="modal-title">{{ edit.title }}</div>
                    <button class="modal-close" @click="edit.open = false">✕</button>
                </div>
                <div class="modal-body" style="display: grid; gap: 12px">
                    <div v-show="!edit.isTop" id="ec-parent-row">
                        <label class="label">Категория первого уровня</label>
                        <select id="ec-parent" v-model="edit.parentId" style="font-size: 13px; width: 100%">
                            <option v-for="c in topCats" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Название статьи</label>
                        <input id="ec-name" v-model="edit.name" type="text" class="input" placeholder="Например: Электроэнергия" />
                    </div>
                    <div>
                        <label class="label">Код (латиница, уникальный)</label>
                        <input id="ec-code" v-model="edit.code" type="text" class="input" placeholder="gen_electricity" :disabled="edit.id !== null" />
                    </div>
                    <div style="display: flex; gap: 16px; align-items: center">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer">
                            <input id="ec-is-fixed" v-model="edit.isFixed" type="checkbox" />
                            📌 Постоянный расход (в точку безубыточности)
                        </label>
                    </div>
                    <div>
                        <label class="label">Порядок сортировки</label>
                        <input id="ec-sort" v-model="edit.sort" type="number" class="input" style="width: 100px" />
                    </div>
                    <div id="ec-error" style="color: var(--red); font-size: 13px">{{ edit.error }}</div>
                </div>
                <!-- Inline buttons are separated by a space, as the line breaks of the legacy markup render. -->
                <div class="modal-footer">
                    <button class="btn btn-ghost" @click="edit.open = false">Отмена</button>{{ ' ' }}
                    <button v-if="edit.id !== null" id="ec-delete-btn" class="btn btn-ghost" style="color: var(--red)" :disabled="edit.busy" @click="deleteExpCat">
                        🗑 Удалить</button
                    >{{ ' ' }}
                    <button class="btn btn-primary" :disabled="edit.busy" @click="saveExpCat">💾 Сохранить</button>
                </div>
            </ErpModal>

            <ErpModal id="expCatMergeModal" :open="merge.open" max-width="480px" @close="merge.open = false">
                <div class="modal-header">
                    <div class="modal-title">🔀 Объединить статьи</div>
                    <button class="modal-close" @click="merge.open = false">✕</button>
                </div>
                <div class="modal-body" style="display: grid; gap: 12px">
                    <div>
                        <label class="label">Исходная статья (будет удалена)</label>
                        <select id="ec-merge-from" v-model="merge.from" style="font-size: 13px; width: 100%">
                            <option v-for="c in mergeOptions" :key="c.id" :value="c.id">{{ c.name }} ({{ c.parent_code }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Целевая статья (останется)</label>
                        <select id="ec-merge-to" v-model="merge.to" style="font-size: 13px; width: 100%">
                            <option v-for="c in mergeOptions" :key="c.id" :value="c.id">{{ c.name }} ({{ c.parent_code }})</option>
                        </select>
                    </div>
                    <div style="font-size: 12px; color: var(--text3)">Все транзакции исходной статьи будут перенесены в целевую, затем исходная удалится.</div>
                    <div id="ec-merge-error" style="color: var(--red); font-size: 13px">{{ merge.error }}</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" @click="merge.open = false">Отмена</button>{{ ' ' }}
                    <button class="btn btn-primary" :disabled="merge.busy" @click="mergeExpCats">🔀 Объединить</button>
                </div>
            </ErpModal>
        </div>
    </ErpLayout>
</template>
