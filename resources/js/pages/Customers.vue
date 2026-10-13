<script setup lang="ts">
// M08 «Покупатели» — legacy page-customers (L3640–3826), tab «Контрагенты»: renderPersons (L18856), openPersonModal (L18925),
// savePerson (L18950), showCustTab (L19061), personModal (L8417). Same markup and texts; the list, the customer's channel
// and saving are decided by the server. Other tabs are migrated in later parts (docs/adr/0006-customers-and-channels.md).
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';

interface Person {
    id: number;
    name: string;
    type: string;
    is_legal: boolean;
    inn: string | null;
    phone: string | null;
    source: string;
    lock_version: number;
}

const props = defineProps<{ persons: Person[] }>();

type Tab = 'persons' | 'dealers' | 'salons' | 'reconcile-counterparties';
const tab = ref<Tab>('persons');
const tabs: { id: Tab; domId: string; label: string }[] = [
    { id: 'persons', domId: 'cust-tab-counterparties', label: '🏢 Контрагенты' },
    { id: 'dealers', domId: 'cust-tab-dealers', label: '🚚 Дилеры' },
    { id: 'salons', domId: 'cust-tab-salons', label: '🏬 Салоны' },
    { id: 'reconcile-counterparties', domId: 'cust-tab-reconcile', label: '📊 Сверки' },
];
// Legacy recalculates dealer/salon turnover for the period; the «Контрагенты» tab does not depend on it.
const period = ref('year');
const search = ref('');

const shown = computed(() => {
    const q = search.value.toLowerCase();
    return props.persons.filter((p) => !q || p.name.toLowerCase().includes(q) || (p.phone ?? '').includes(q));
});
const legalCount = computed(() => shown.value.filter((p) => p.is_legal).length);

// ── personModal ───────────────────────────────────────────────────────────
const modal = reactive({
    open: false,
    id: null as number | null,
    title: 'Новое лицо',
    name: '',
    type: 'Юр. лицо',
    inn: '',
    phone: '',
    source: '',
    lockVersion: 0,
    error: '',
    saved: false,
    busy: false,
});
const idLabel = computed(() => (modal.type === 'Юр. лицо' ? 'БИН (12 цифр)' : 'ИИН (12 цифр)'));

function openPersonModal(p: Person | null): void {
    modal.id = p?.id ?? null;
    modal.title = p ? 'Изменить лицо' : 'Новое лицо';
    modal.name = p?.name ?? '';
    // Older legacy rows have the types «client»/«company», which the form does not offer: it shows the type of the list.
    modal.type = p ? (p.is_legal ? 'Юр. лицо' : 'Физ. лицо') : 'Юр. лицо';
    modal.inn = p?.inn ?? '';
    modal.phone = p?.phone ?? '';
    modal.source = p ? 'Заведён: ' + (p.source || '—') : '';
    modal.lockVersion = p?.lock_version ?? 0;
    modal.error = '';
    modal.saved = false;
    modal.open = true;
}

function closeModal(): void {
    modal.open = false;
    modal.error = '';
    modal.saved = false;
}

const options = {
    preserveScroll: true,
    preserveState: true,
    onStart: () => (modal.busy = true),
    onFinish: () => (modal.busy = false),
    onError: (errors: Record<string, string>) => (modal.error = Object.values(errors)[0] ?? 'Ошибка сохранения'),
    onSuccess: () => {
        // Legacy: «✓ Сохранено!» in green, the modal closes after 900 ms — here only after the server confirmed (QA-011).
        modal.saved = true;
        modal.error = '✓ Сохранено!';
        window.setTimeout(closeModal, 900);
    },
};

function savePerson(): void {
    const name = modal.name.trim();
    const inn = modal.inn.trim();
    if (!name) return void (modal.error = 'Укажите название/ФИО');
    if (inn && !/^\d{12}$/.test(inn)) return void (modal.error = (modal.type === 'Юр. лицо' ? 'БИН' : 'ИИН') + ' должен содержать ровно 12 цифр');
    modal.error = '';
    const data = { name, type: modal.type, inn: inn || null, phone: modal.phone.trim() || null };
    if (modal.id === null) {
        router.post('/customers', data, options);
    } else {
        router.put(`/customers/${modal.id}`, { ...data, lock_version: modal.lockVersion }, options);
    }
}
</script>

<template>
    <ErpLayout>
        <div id="page-customers" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Покупатели</div>
                    <div class="section-sub">Юридические лица — ТОО, ИП, АО · Дилеры · Салоны · Сверки</div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px">
                    <select id="cust-period" v-model="period" style="font-size: 13px; padding: 5px 10px; width: auto">
                        <option value="all">Всё время</option>
                        <option value="year">Текущий год</option>
                        <option value="quarter">Текущий квартал</option>
                        <option value="month">Текущий месяц</option>
                    </select>
                </div>
            </div>

            <div class="tabs">
                <div v-for="t in tabs" :id="t.domId" :key="t.id" class="tab" :class="{ active: tab === t.id }" @click="tab = t.id">{{ t.label }}</div>
            </div>

            <!-- TAB: Контрагенты (прямые покупатели офиса) -->
            <div v-show="tab === 'persons'" id="cust-persons" class="cust-tab active">
                <div style="display: flex; gap: 8px; margin-bottom: 14px; align-items: center; flex-wrap: wrap">
                    <div style="font-size: 13px; color: var(--text3); flex: 1">Прямые покупатели офиса — физ. и юр. лица купившие напрямую у PromTech</div>
                    <input id="persons-search" v-model="search" type="search" placeholder="🔍 Поиск..." style="font-size: 13px; width: 200px" />
                    <button class="btn btn-primary btn-sm" @click="openPersonModal(null)">+ Новый контрагент</button>
                </div>
                <div style="display: flex; gap: 12px; margin-bottom: 16px">
                    <div class="kpi" style="flex: 1">
                        <div class="kpi-label">Прямых покупателей</div>
                        <div id="person-count" class="kpi-value" style="font-size: 20px">{{ shown.length }}</div>
                    </div>
                    <div class="kpi" style="flex: 1">
                        <div class="kpi-label">Юр. лица</div>
                        <div id="person-legal" class="kpi-value" style="font-size: 20px">{{ legalCount }}</div>
                    </div>
                    <div class="kpi" style="flex: 1">
                        <div class="kpi-label">Физ. лица</div>
                        <div id="person-individual" class="kpi-value" style="font-size: 20px">{{ shown.length - legalCount }}</div>
                    </div>
                </div>
                <div class="card" style="padding: 0">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Название / ФИО</th>
                                <th>Тип</th>
                                <th>ИИН / БИН</th>
                                <th>Телефон</th>
                                <th style="text-align: right">Оборот</th>
                                <th style="text-align: right">Заказов</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="persons-tbody">
                            <tr v-for="p in shown" :key="p.id">
                                <td style="font-weight: 500; cursor: pointer" @click="openPersonModal(p)">{{ p.name }}</td>
                                <td>
                                    <span class="badge" :class="p.is_legal ? 'badge-blue' : 'badge-purple'">{{ p.is_legal ? 'Юр. лицо' : 'Физ. лицо' }}</span>
                                </td>
                                <td style="font-family: monospace; font-size: 12px">{{ p.inn || '—' }}</td>
                                <td style="font-family: monospace; font-size: 12px">{{ p.phone || '—' }}</td>
                                <!-- Turnover and order count come from office orders: shown after the orders are migrated (M09). -->
                                <td style="text-align: right; font-family: monospace; color: var(--green); font-size: 12px">—</td>
                                <td style="text-align: right; font-size: 12px">—</td>
                                <td><button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openPersonModal(p)">✏️</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-for="t in tabs.filter((x) => x.id !== 'persons')" v-show="tab === t.id" :id="'cust-' + t.id" :key="t.id" class="cust-tab">
                <div class="card">
                    <div style="font-size: 13px; color: var(--text3); line-height: 1.6">
                        Вкладка ещё не перенесена в новую систему. Перенос идёт по плану в трекере; данные этой вкладки здесь пока не
                        показываются.
                    </div>
                </div>
            </div>
        </div>

        <ErpModal id="personModal" :open="modal.open" @close="closeModal">
            <div class="modal-header">
                <div id="person-modal-title" class="modal-title">{{ modal.title }}</div>
                <button class="modal-close" @click="closeModal">✕</button>
            </div>
            <div id="person-form-error" style="font-size: 13px; margin-bottom: 8px; min-height: 18px" :style="{ color: modal.saved ? 'var(--green)' : 'var(--red)' }">{{ modal.error }}</div>
            <div class="form-row cols-2">
                <div><label class="label">Название / ФИО</label><input id="prs-name" v-model="modal.name" type="text" placeholder="ТОО «...» или Иванов И.И." /></div>
                <div>
                    <label class="label">Тип</label>
                    <select id="prs-type" v-model="modal.type">
                        <option value="Юр. лицо">Юр. лицо</option>
                        <option value="Физ. лицо">Физ. лицо</option>
                    </select>
                </div>
            </div>
            <div class="form-row cols-2">
                <div>
                    <label id="prs-id-label" class="label">{{ idLabel }}</label><input id="prs-idnum" v-model="modal.inn" type="text" maxlength="12" placeholder="123456789012" />
                </div>
                <div><label class="label">Телефон</label><input id="prs-phone" v-model="modal.phone" type="text" placeholder="+7 7XX XXX XXXX" /></div>
            </div>
            <div id="person-source-label" style="font-size: 12px; color: var(--text3); margin-bottom: 8px">{{ modal.source }}</div>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
                <button class="btn btn-ghost" @click="closeModal">Отмена</button>
                <button class="btn btn-primary" :disabled="modal.busy || modal.saved" @click="savePerson">Сохранить</button>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
