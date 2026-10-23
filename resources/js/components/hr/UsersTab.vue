<script setup lang="ts">
// Tab «Пользователи» of «Сотрудники» — legacy hr-tab-users (L7377–7492), renderUsersTable/renderUsersStaffBlock/usrSameName/
// usrPages/usrCreateFor/usrTranslit/openUserModal/applyUserRoleTemplate/saveAppUser/toggleUserActive (L21868–22165): the
// accounts (the built-in roles with their records, and the new ones), their sections and state; the staff with their access.
// The director and HR create and edit accounts and block them; the server checks every rule (UserAccountService, D-13 —
// ADR-0046). Passwords are kept only as hashes and never shown; the PIN codes of the cabinet wait for question № 42.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import type { Employee } from '@/lib/hr';

export interface Account {
    id: number;
    login: string;
    name: string;
    label: string;
    channel: string;
    pages: string[];
    active: boolean;
    source: string;
    lock_version: number;
}
const props = defineProps<{ accounts: Account[]; employees: Employee[]; templates: Record<string, { label: string; channel: string; pages: string[] }> }>();

// Legacy ALL_PAGES (L21855): the sections of the form, in its order.
const ALL_PAGES = [
    { id: 'dashboard', label: 'Дашборд' },
    { id: 'mrp', label: 'Производство' },
    { id: 'warehouse', label: 'Склад' },
    { id: 'purchases', label: 'Закупки' },
    { id: 'suppliers', label: 'Поставщики' },
    { id: 'customers', label: 'Покупатели' },
    { id: 'crm', label: 'CRM / Продажи' },
    { id: 'finance', label: 'Финансы P&L' },
    { id: 'money', label: 'Деньги' },
    { id: 'channels', label: 'Центры прибыли' },
    { id: 'reconcile', label: 'Сверка с офисом' },
    { id: 'money_salon', label: 'Касса салона' },
    { id: 'hr', label: 'Сотрудники' },
    { id: 'wages', label: 'Сдельная оплата' },
    { id: 'techcards', label: 'Тех. карты' },
    { id: 'mycabinet', label: 'Личный кабинет' },
    { id: 'ai', label: 'AI Ассистент' },
];
const TEMPLATES: [string, string][] = [
    ['manager', 'Менеджер продаж'],
    ['storekeeper', 'Кладовщик'],
    ['foreman', 'Начальник цеха'],
    ['accountant', 'Бухгалтер'],
    ['hr', 'HR-менеджер'],
    ['buyer', 'Менеджер закупок'],
    ['salon_armada', 'Салон Армада'],
    ['salon_nazarbayeva', 'Салон Назарбаева'],
    ['dealer_astana', 'Дилер Астана'],
    ['dealer_aktau', 'Дилер Актау'],
    ['dealer_shymkent', 'Дилер Шымкент'],
    ['dealer_atyrau', 'Дилер Атырау'],
    ['dealer_aktobe', 'Дилер Актобе'],
];
const CHANNELS: [string, string][] = [
    ['salon_armada', 'Салон Армада'],
    ['salon_nazarbayeva', 'Салон Назарбаева'],
    ['dealer_astana', 'Дилер Астана'],
    ['dealer_aktau', 'Дилер Актау'],
    ['dealer_shymkent', 'Дилер Шымкент'],
    ['dealer_atyrau', 'Дилер Атырау'],
    ['dealer_aktobe', 'Дилер Актобе'],
];
const PIN_LATER = 'Коды ЗП для личного кабинета выдаются заново вместе с кабинетом сотрудника (ERP-058, вопрос № 42); из старой системы они не переносятся.';
const pinLater = () => window.alert(PIN_LATER);

// ── Names (legacy usrNorm/usrSameName): the same words, or the same surname and the first letter of the first name ──
const norm = (s: string) =>
    String(s || '')
        .toLowerCase()
        .replace(/ё/g, 'е')
        .replace(/[.,]/g, ' ')
        .split(/\s+/)
        .filter(Boolean);
function sameName(a: string, b: string): boolean {
    const x = norm(a);
    const y = norm(b);
    if (!x.length || !y.length) return false;
    if (x.join(' ') === y.join(' ')) return true;
    return x[0] === y[0] && (x[1] || '')[0] === (y[1] || '')[0] && !!x[1];
}
const pageLabels = (pages: string[]) => {
    const l = pages
        .slice(0, 4)
        .map((p) => ALL_PAGES.find((x) => x.id === p)?.label || p)
        .join(', ');
    return l + (pages.length > 4 ? ` +${pages.length - 4}` : '');
};
const accountOf = (name: string) => props.accounts.find((a) => sameName(a.name, name)) ?? null;

// ── Staff and their access (legacy renderUsersStaffBlock) ──
const f = reactive({ q: '', only: 'all' });
const staff = computed(() => props.employees.filter((e) => (e.status || 'active') !== 'fired').map((e) => ({ e, user: accountOf(e.name) })));
const staffSum = computed(() => `Всего ${staff.value.length} · без входа в систему: ${staff.value.filter((x) => !x.user).length}`);
const staffRows = computed(() => {
    let list = staff.value;
    if (f.only === 'nopin' || f.only === 'old') return [];
    if (f.only === 'nouser') list = list.filter((x) => !x.user);
    if (f.only === 'user') list = list.filter((x) => x.user);
    const q = f.q.toLowerCase().trim();
    if (q) list = list.filter((x) => (x.e.name + ' ' + (x.e.pos || '')).toLowerCase().includes(q));
    return list;
});

// ── The form (legacy userModal) ──
const m = reactive({
    open: false,
    id: null as number | null,
    lockVersion: 0,
    login: '',
    password: '',
    name: '',
    label: '',
    channel: '',
    template: '',
    pages: [] as string[],
    error: '',
    busy: false,
});
function openUserModal(a: Account | null): void {
    Object.assign(m, {
        open: true,
        id: a?.id ?? null,
        lockVersion: a?.lock_version ?? 0,
        login: a?.login ?? '',
        password: '',
        name: a?.name ?? '',
        label: a?.label ?? '',
        channel: a?.channel ?? '',
        template: '',
        pages: [...(a?.pages ?? [])],
        error: '',
    });
}
// Legacy usrTranslit/usrCreateFor: a login from the surname and the first letter of the first name, a free one; a random
// password; the name and the position; «Личный кабинет».
const TR: Record<string, string> = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's',
    т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ы: 'y', э: 'e', ю: 'yu', я: 'ya', ъ: '', ь: '', і: 'i', ә: 'a', ғ: 'g', қ: 'k',
    ң: 'n', ө: 'o', ұ: 'u', ү: 'u', һ: 'h',
};
const translit = (s: string) =>
    String(s || '')
        .toLowerCase()
        .split('')
        .map((c) => (TR[c] !== undefined ? TR[c] : c))
        .join('')
        .replace(/[^a-z0-9]/g, '');
const randomPassword = () => {
    const abc = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint32Array(12));
    return Array.from(bytes, (n) => abc[n % abc.length]).join('');
};
function createFor(e: Employee): void {
    openUserModal(null);
    const t = norm(e.name);
    let login = translit(t[0] || '') + (t[1] ? translit(t[1])[0] : '');
    const taken = new Set(props.accounts.map((a) => a.login));
    const base = login;
    let n = 2;
    while (taken.has(login)) login = base + n++;
    Object.assign(m, { login, password: randomPassword(), name: e.name, label: e.pos || '', pages: ['mycabinet'] });
}
function applyTemplate(): void {
    const tpl = props.templates[m.template];
    if (!tpl) return;
    m.label = tpl.label || '';
    m.channel = tpl.channel || '';
    m.pages = (tpl.pages || []).filter((p) => ALL_PAGES.some((x) => x.id === p));
}
function togglePage(id: string, on: boolean): void {
    m.pages = on ? [...new Set([...m.pages, id])] : m.pages.filter((p) => p !== id);
}
function save(): void {
    if (!m.login.trim()) {
        m.error = 'Введите логин';
        return;
    }
    const data = { login: m.login, password: m.password, name: m.name, label: m.label, channel: m.channel, pages: m.pages, lock_version: m.lockVersion };
    const options = {
        preserveScroll: true,
        onStart: () => (m.busy = true),
        onFinish: () => (m.busy = false),
        onSuccess: () => (m.open = false),
        onError: (e: Record<string, string>) => (m.error = Object.values(e)[0] || 'Ошибка'),
    };
    if (m.id === null) router.post('/hr/users', data, options);
    else router.put(`/hr/users/${m.id}`, data, options);
}
const busyToggle = ref(false);
function toggleActive(a: Account): void {
    router.patch(
        `/hr/users/${a.id}/active`,
        { active: !a.active, lock_version: a.lock_version },
        {
            preserveScroll: true,
            onStart: () => (busyToggle.value = true),
            onFinish: () => (busyToggle.value = false),
            onError: (e: Record<string, string>) => window.alert(Object.values(e)[0] || 'Ошибка'),
        },
    );
}
</script>

<template>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px">
        <div style="font-size: 13px; color: var(--text3)">Вход в систему (логин и пароль) и коды для личного кабинета (ЗП, задания)</div>
        <button class="btn btn-primary btn-sm" @click="openUserModal(null)">+ Новый пользователь</button>
    </div>
    <div id="users-db-error" style="font-size: 12px; color: var(--red); margin-bottom: 8px"></div>
    <div class="card" style="padding: 0; overflow-x: auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Логин</th>
                    <th>Имя</th>
                    <th>Роль</th>
                    <th>Разделы</th>
                    <th>Пароль</th>
                    <th>Код ЗП</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="users-tbody">
                <tr v-for="a in props.accounts" :key="a.id">
                    <td style="font-family: monospace; font-weight: 600">{{ a.login }}</td>
                    <td>{{ a.name }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ a.label || '—' }}</td>
                    <td style="font-size: 11px; color: var(--text3)">{{ pageLabels(a.pages) }}</td>
                    <td style="font-family: monospace; font-size: 12px"><span title="Пароль хранится только в зашифрованном виде">••••••</span></td>
                    <td style="font-family: monospace; font-size: 12px"><span style="color: var(--text3)">—</span></td>
                    <td>
                        <span class="badge" :class="a.active ? 'badge-green' : 'badge-red'">{{ a.active ? 'Активен' : 'Заблокирован' }}</span>
                        <div style="font-size: 10px; color: var(--text3); margin-top: 2px">{{ a.source }}</div>
                    </td>
                    <td style="white-space: nowrap">
                        <button class="btn btn-ghost btn-sm" @click="openUserModal(a)">✏️</button>
                        <button class="btn btn-ghost btn-sm" style="color: var(--red)" :disabled="busyToggle" @click="toggleActive(a)">{{ a.active ? '🔒' : '🔓' }}</button>
                    </td>
                </tr>
                <tr v-if="!props.accounts.length">
                    <td colspan="8" style="text-align: center; padding: 20px; color: var(--text3)">Нет пользователей</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin: 22px 0 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
        <b style="font-size: 14px">👷 Сотрудники: коды ЗП и доступ в систему</b>
        <span id="users-staff-sum" style="font-size: 12px; color: var(--text3)">{{ staffSum }}</span>
        <span style="flex: 1"></span>
        <button class="btn btn-ghost btn-sm" title="Выдать случайные 6-значные коды тем, у кого нет кода или он 4-значный" @click="pinLater">🔑 Выдать 6-значные коды</button>
        <input id="users-staff-q" v-model="f.q" type="search" placeholder="🔍 Поиск…" style="font-size: 12px; width: 170px" />
        <select id="users-staff-filter" v-model="f.only" style="font-size: 12px; width: auto">
            <option value="all">Все</option>
            <option value="nopin">Без кода ЗП</option>
            <option value="old">Со старым 4-значным кодом</option>
            <option value="nouser">Без входа в систему</option>
            <option value="user">С входом в систему</option>
        </select>
    </div>
    <div class="card" style="padding: 0; overflow-x: auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th>Должность</th>
                    <th>Код ЗП</th>
                    <th>Вход в систему</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="users-staff-tbody">
                <tr v-for="{ e, user } in staffRows" :key="e.id">
                    <td>{{ e.name }}</td>
                    <td style="font-size: 12px; color: var(--text3)">{{ e.pos || '—' }}</td>
                    <td style="font-family: monospace; font-weight: 600">
                        <span style="color: var(--text3)">—</span>
                        <button class="btn btn-ghost btn-sm" style="padding: 0 6px" title="Задать / сменить код" @click="pinLater">🔑</button>
                    </td>
                    <td>
                        <template v-if="user"
                            ><span style="font-family: monospace">{{ user.login }}</span> <span style="font-size: 11px; color: var(--text3)">· {{ user.label || '' }}</span></template
                        >
                        <span v-else style="color: var(--text3)">нет входа</span>
                    </td>
                    <td>
                        <button v-if="user" class="btn btn-ghost btn-sm" @click="openUserModal(user)">✏️ Доступ</button>
                        <button v-else class="btn btn-ghost btn-sm" @click="createFor(e)">＋ Создать вход</button>
                    </td>
                </tr>
                <tr v-if="!staffRows.length">
                    <td colspan="5" style="text-align: center; padding: 16px; color: var(--text3)">Нет сотрудников</td>
                </tr>
            </tbody>
        </table>
    </div>

    <ErpModal id="userModal" :open="m.open" max-width="560px" @close="m.open = false">
        <div class="modal-header">
            <span id="user-modal-title" class="modal-title">{{ m.id === null ? 'Новый пользователь' : 'Редактировать пользователя' }}</span>
            <button class="modal-close" @click="m.open = false">✕</button>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
            <div class="form-row">
                <label class="label">Логин</label>
                <input id="u-login" v-model="m.login" type="text" placeholder="например: manager2" :disabled="m.id !== null" />
            </div>
            <div class="form-row">
                <label class="label">Пароль</label>
                <div style="display: flex; gap: 6px">
                    <input id="u-password" v-model="m.password" type="text" :placeholder="m.id !== null ? 'пусто — не менять' : 'минимум 8 символов'" style="flex: 1" />
                    <button class="btn btn-ghost btn-sm" @click="m.password = randomPassword()">🎲</button>
                </div>
            </div>
            <div class="form-row">
                <label class="label">Имя (отображается в системе)</label>
                <input id="u-name" v-model="m.name" type="text" placeholder="Иванов И.И." />
            </div>
            <div class="form-row">
                <label class="label">Подпись роли</label>
                <input id="u-label" v-model="m.label" type="text" placeholder="Менеджер продаж" />
            </div>
            <div class="form-row">
                <label class="label">Шаблон роли</label>
                <select id="u-role-template" v-model="m.template" @change="applyTemplate">
                    <option value="">— выбрать шаблон —</option>
                    <option v-for="[k, l] in TEMPLATES" :key="k" :value="k">{{ l }}</option>
                </select>
            </div>
            <div class="form-row">
                <label class="label">Канал продаж (для дилеров/салонов)</label>
                <select id="u-channel" v-model="m.channel">
                    <option value="">— офис (нет ограничений) —</option>
                    <option v-for="[k, l] in CHANNELS" :key="k" :value="k">{{ l }}</option>
                </select>
            </div>
        </div>

        <div style="margin-top: 12px">
            <label class="label" style="margin-bottom: 8px; display: block">Доступные разделы</label>
            <div id="u-pages-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px">
                <label v-for="p in ALL_PAGES" :key="p.id" style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer">
                    <input type="checkbox" :data-page="p.id" :checked="m.pages.includes(p.id)" @change="togglePage(p.id, ($event.target as HTMLInputElement).checked)" />
                    {{ p.label }}
                </label>
            </div>
        </div>

        <div style="display: flex; gap: 8px; justify-content: space-between; margin-top: 16px">
            <div style="display: flex; gap: 8px; margin-left: auto">
                <button class="btn btn-ghost" @click="m.open = false">Отмена</button>
                <button class="btn btn-primary" :disabled="m.busy" @click="save">💾 Сохранить</button>
            </div>
        </div>
        <div id="u-error" style="color: var(--red); font-size: 12px; margin-top: 8px">{{ m.error }}</div>
    </ErpModal>
</template>
