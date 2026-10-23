<script setup lang="ts">
// M17 «Сотрудники», part 1 — legacy page-hr (L7204–7560), showPage('hr') (L16986), renderHRKPIs/renderOrgChart/showHRTab/
// setHRView/renderHRStaff/openEmployeeCard/openStaffChangesHistory (L21627–22600). The staff as legacy loads it, the
// staffing table, the cards and the table of the employees, the card of an employee with its conditions of work and their
// history. View only: saving a card, hiring, dismissing and the PIN come with the users and the payroll rules (D-13, D-06);
// «ФОТ» and «Нагрузка» of the KPIs, the attendance of the cards and the other tabs are counted by legacy from figures in its
// own code (demonstration lists, unconfirmed rates) and wait for those decisions (ADR-0036).
import { computed, reactive, ref } from 'vue';
import ErpModal from '@/components/ErpModal.vue';
import SalonCommissionsTab from '@/components/hr/SalonCommissionsTab.vue';
import UsersTab, { type Account } from '@/components/hr/UsersTab.vue';
import ErpLayout from '@/layouts/ErpLayout.vue';
import { initials, STAFF_DEPTS, STAFF_POSITIONS, TARIFFS, WORKSHOPS, type Employee } from '@/lib/hr';

const props = defineProps<{
    today: string;
    counts: { total: number; prod: number; aup: number };
    salonManager: boolean;
    canSeePin: boolean;
    canManageUsers: boolean;
    canSeeCommissions: boolean;
    canDismiss: boolean;
    employees: Employee[];
    accounts: Account[];
    roleTemplates: Record<string, { label: string; channel: string; pages: string[] }>;
}>();

const LATER = {
    save: 'Изменение карточки, приём и увольнение сотрудника появятся вместе с решением о пользователях и правилами расчёта зарплаты (ERP-053, ERP-054, решения D-13 и D-06).',
    pin: 'PIN-коды выдаются заново вместе с кабинетом сотрудника (ERP-058, решение D-13); из legacy они не переносятся.',
    structure: 'Изменение штатного расписания появится вместе с разделом «Сотрудники» (ERP-053, решение D-13).',
    tariffs: 'Изменение тарифов появится вместе со сдельной оплатой (ERP-055, решение D-06).',
};
const later = (text: string) => window.alert(text);
const TAB_LATER: Record<string, string> = {
    wage: 'Сдельная выработка появится вместе с расчётом сдельной оплаты (ERP-055, решение D-06).',
    payroll: 'Зарплатная ведомость появится вместе с правилами расчёта зарплаты (ERP-057, решение D-06).',
    timesheet: 'Табель появится вместе с правилами расчёта зарплаты (ERP-054, решение D-06).',
};

// ── Tabs (legacy showHRTab; the head of the salons sees «Сотрудники» and «Комиссии» only, and starts on «Сотрудники») ──
const TABS = [
    { id: 'orgchart', label: '🏢 Штатное расписание' },
    { id: 'employees', label: '👤 Сотрудники' },
    { id: 'wage', label: '🔨 Сдельная выработка' },
    { id: 'payroll', label: '📊 Зарплатная ведомость' },
    { id: 'timesheet', label: '📅 Табель' },
    { id: 'tariffs', label: '⚙️ Тарифы' },
    { id: 'salon_commissions', label: '💰 Комиссии салонов', domId: 'hr-tab-salon-commissions' },
    { id: 'users', label: '🔐 Пользователи', domId: 'hr-tab-users-btn' },
];
const visible = (id: string) => {
    if (id === 'users' && !props.canManageUsers) return false;
    if (id === 'salon_commissions' && !props.canSeeCommissions) return false;
    if (props.salonManager && ['orgchart', 'wage', 'payroll', 'timesheet', 'tariffs', 'users'].includes(id)) return false;
    return true;
};
const start = props.salonManager ? 'employees' : 'orgchart';
// Legacy «📊 Зарплатная ведомость» of the header opens the payroll tab but marks the third tab (index 2) as active.
const state = reactive({ tab: start, marked: TABS.findIndex((t) => t.id === start) });
function showHRTab(id: string, marked: number): void {
    state.tab = id;
    state.marked = marked;
}

// ── KPI (legacy renderHRKPIs) ──
const kpi = computed(() => props.counts);

// ── Штатное расписание (legacy renderOrgChart, occupiedSlots; «Тарифы» — renderTariffs): occupied posts by the exact title of the position ──
const occupied = (title: string) => props.employees.filter((e) => e.pos === title);
const orgchart = computed(() =>
    STAFF_DEPTS.map((dept) => {
        const positions = STAFF_POSITIONS.filter((p) => p.deptId === dept.id).map((p) => ({ ...p, occ: occupied(p.title) }));
        const totalSlots = positions.reduce((s, p) => s + p.slots, 0);
        const totalOccupied = positions.reduce((s, p) => s + p.occ.length, 0);
        return { dept, positions, totalSlots, totalOccupied, totalVacant: totalSlots - totalOccupied, fotMin: positions.reduce((s, p) => s + p.salaryMin * p.slots, 0) };
    }).filter((d) => d.positions.length),
);
const ru = (n: number) => n.toLocaleString('ru-RU');

// ── Сотрудники (legacy renderHRStaff, setHRView) ──
const f = reactive({ search: '', dept: 'all' });
const view = ref<'grid' | 'table'>('grid');
const list = computed(() => {
    const q = f.search.toLowerCase();
    return props.employees.filter((e) => (!q || (e.name + e.pos).toLowerCase().includes(q)) && (f.dept === 'all' || e.type === f.dept));
});
const wsOf = (e: Employee) => WORKSHOPS.find((w) => w.id === e.ws);
const typeBadge = (t: string) => (t === 'prod' ? 'badge-green' : t === 'salon' ? 'badge-purple' : 'badge-blue');
const typeLabel = (t: string) => (t === 'prod' ? 'Произв.' : t === 'salon' ? 'Салон' : 'АУП');
// Legacy «Начислено»: the output of a piece worker from its demonstration list, else the salary; here the salary.
const earned = (e: Employee) => e.salary || 0;

// ── Карточка сотрудника (legacy openEmployeeCard): the form as legacy fills it; saving waits for D-13/D-06 ──
const card = reactive({
    open: false,
    emp: null as Employee | null,
    name: '',
    pos: '',
    type: 'prod',
    ws: '',
    salary: '' as string | number,
    phone: '',
    iin: '',
    hired: '',
    salon: '',
    comment: '',
    base: '' as string | number,
    meal: '' as string | number,
    opv: 10 as string | number,
    osms: 3 as string | number,
    so: 3.5 as string | number,
    changeDate: '',
    status: 'active',
    firedDate: '',
});
function openEmployeeCard(emp: Employee | null): void {
    const comment = emp?.comment || '';
    Object.assign(card, {
        open: true,
        emp,
        name: emp?.name || '',
        pos: emp?.pos || '',
        type: emp?.type || 'prod',
        ws: emp?.ws || '',
        salary: emp?.salary || '',
        phone: emp?.phone || '',
        iin: emp?.iin || '',
        hired: emp?.hired || '',
        comment,
        salon:
            comment.includes('Армада') && comment.includes('Назарбаева') ? 'Армада, Назарбаева' : comment.includes('Армада') ? 'Армада' : comment.includes('Назарбаева') ? 'Назарбаева' : '',
        base: emp?.base_salary || '',
        meal: emp?.deduction_meal || '',
        opv: emp?.deduction_opv_pct || 10,
        osms: emp?.deduction_osms_pct || 3,
        so: emp?.deduction_so_pct || 3.5,
        changeDate: props.today,
        status: emp?.status || 'active',
        firedDate: emp?.fired_date || '',
    });
}
// Legacy openEmployeeCardByName (from «Зарплатная ведомость» → «👤 Карточка сотрудника»): /hr?emp=<name> opens the card.
{
    const name = new URLSearchParams(window.location.search).get('emp');
    const emp = name ? props.employees.find((e) => e.name === name) : undefined;
    if (emp) openEmployeeCard(emp);
}
// Legacy openStaffChangesHistory: the conditions of work, newest first, in a message.
function openStaffChangesHistory(): void {
    if (!card.emp && !card.name) {
        window.alert('Сохраните сотрудника сначала');
        return;
    }
    const fmt = (n: number | null) => (n ? '₸' + Math.round(n).toLocaleString('ru-RU') : '—');
    const label: Record<string, string> = { hire: 'Приём', salary_change: 'Изменение условий', fire: 'Увольнение' };
    window.alert(
        'История изменений:\n\n' +
            ((card.emp?.changes ?? [])
                .map(
                    (r) =>
                        `${r.date} — ${label[r.type] || r.type}\n` +
                        `Оклад: ${fmt(r.base_salary)} | Обед: ${fmt(r.deduction_meal)}\n` +
                        `ОПВ: ${r.opv}% | ОСМС: ${r.osms}% | СО: ${r.so}%\n` +
                        (r.comment ? `Примечание: ${r.comment}` : ''),
                )
                .join('\n─────────────\n') || 'История пуста'),
    );
}
</script>

<template>
    <ErpLayout>
        <div id="page-hr" class="page active">
            <div class="section-header">
                <div>
                    <div class="section-title">Сотрудники</div>
                    <div class="section-sub">Штат · зарплата · сдельный учёт · табель</div>
                </div>
                <div style="display: flex; gap: 8px">
                    <button class="btn btn-ghost btn-sm" @click="showHRTab('payroll', 2)">📊 Зарплатная ведомость</button>
                    <button class="btn btn-primary btn-sm" @click="openEmployeeCard(null)">+ Сотрудник</button>
                </div>
            </div>

            <!-- KPI -->
            <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px">
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Всего сотрудников</div>
                    <div id="hr-kpi-total" class="kpi-value" style="font-size: 22px">{{ kpi.total }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Производственных</div>
                    <div id="hr-kpi-prod" class="kpi-value" style="font-size: 22px; color: var(--green)">{{ kpi.prod }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">АУП</div>
                    <div id="hr-kpi-aup" class="kpi-value" style="font-size: 22px; color: var(--accent)">{{ kpi.aup }}</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">ФОТ месяц (нетто)</div>
                    <div id="hr-kpi-fot" class="kpi-value" style="font-size: 22px">—</div>
                </div>
                <div class="card" style="padding: 14px 16px">
                    <div style="font-size: 11px; color: var(--text3); margin-bottom: 4px">Нагрузка (налоги)</div>
                    <div id="hr-kpi-tax" class="kpi-value" style="font-size: 22px; color: var(--red)">—</div>
                </div>
            </div>

            <!-- Вкладки -->
            <div class="tabs" style="margin-bottom: 16px">
                <template v-for="(t, i) in TABS" :key="t.id">
                    <div v-if="visible(t.id)" :id="t.domId" class="tab" :class="{ active: state.marked === i }" @click="showHRTab(t.id, i)">{{ t.label }}</div>
                </template>
            </div>

            <!-- ШТАТНОЕ РАСПИСАНИЕ -->
            <div id="hr-tab-orgchart" :style="{ display: state.tab === 'orgchart' ? '' : 'none' }">
                <div style="display: flex; gap: 10px; margin-bottom: 14px; align-items: center">
                    <span style="font-size: 13px; color: var(--text2)">Структура компании: подразделения, должности, ставки</span>
                    <div style="flex: 1"></div>
                    <button class="btn btn-ghost btn-sm" @click="later(LATER.structure)">+ Должность</button>
                    <button class="btn btn-ghost btn-sm" @click="later(LATER.structure)">+ Подразделение</button>
                </div>
                <div id="hr-orgchart-body">
                    <div v-for="d in orgchart" :key="d.dept.id" class="card" style="margin-bottom: 16px; padding: 0; overflow: hidden">
                        <div style="padding: 12px 18px; background: var(--bg3); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px">
                            <span style="font-size: 22px">{{ d.dept.icon }}</span>
                            <div style="flex: 1">
                                <div style="font-weight: 700; font-size: 14px">{{ d.dept.name }}</div>
                                <div style="font-size: 11px; color: var(--text3)">
                                    {{ d.positions.length }} должностей · {{ d.totalSlots }} ставок ·
                                    <span style="color: var(--green)">{{ d.totalOccupied }} занято</span>
                                    <template v-if="d.totalVacant > 0">
                                        · <span style="color: var(--amber)">{{ d.totalVacant }} вакансий</span></template
                                    >
                                </div>
                            </div>
                            <div v-if="d.fotMin > 0" style="text-align: right; font-size: 12px">
                                <div style="color: var(--text3)">ФОТ (мин)</div>
                                <div style="font-weight: 600">₸{{ ru(d.fotMin) }}</div>
                            </div>
                            <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later(LATER.structure)">✏️</button>
                        </div>
                        <table class="data-table" style="margin: 0">
                            <thead>
                                <tr>
                                    <th>Должность</th>
                                    <th>Разряд</th>
                                    <th>Вид оплаты</th>
                                    <th style="text-align: center">Ставок</th>
                                    <th style="text-align: center">Занято</th>
                                    <th style="text-align: center">Вакансий</th>
                                    <th style="text-align: right">Оклад (мин–макс)</th>
                                    <th>Сотрудники</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in d.positions" :key="p.id">
                                    <td style="font-weight: 600">{{ p.title }}</td>
                                    <td style="font-size: 12px; color: var(--text3)">{{ p.grade }}</td>
                                    <td style="font-size: 12px">{{ p.wageType === 'piecework' ? '🔨 Сдельная' : '₸ Оклад' }}</td>
                                    <td style="text-align: center; font-family: 'IBM Plex Mono', monospace">{{ p.slots }}</td>
                                    <td style="text-align: center; color: var(--green); font-weight: 600">{{ p.occ.length }}</td>
                                    <td
                                        :style="{
                                            textAlign: 'center',
                                            color: p.slots - p.occ.length > 0 ? 'var(--amber)' : 'var(--text3)',
                                            fontWeight: p.slots - p.occ.length > 0 ? '600' : '400',
                                        }"
                                    >
                                        {{ p.slots - p.occ.length > 0 ? '⚡ ' + (p.slots - p.occ.length) : '—' }}
                                    </td>
                                    <td style="text-align: right; font-size: 12px; font-family: 'IBM Plex Mono', monospace">
                                        {{
                                            p.wageType === 'piecework'
                                                ? 'сдельно'
                                                : p.salaryMin === p.salaryMax
                                                  ? `₸${ru(p.salaryMin)}`
                                                  : `₸${ru(p.salaryMin)} – ₸${ru(p.salaryMax)}`
                                        }}
                                    </td>
                                    <td style="font-size: 12px">
                                        <template v-if="p.occ.length"
                                            ><span
                                                v-for="e in p.occ"
                                                :key="e.id"
                                                style="
                                                    display: inline-block;
                                                    background: rgba(79, 142, 247, 0.15);
                                                    border-radius: 20px;
                                                    padding: 2px 8px;
                                                    margin: 1px;
                                                    font-size: 11px;
                                                    cursor: pointer;
                                                "
                                                @click="openEmployeeCard(e)"
                                                >{{ e.name.split(' ').slice(0, 2).join(' ') }}</span
                                            ></template
                                        >
                                        <span v-else style="color: var(--text3); font-size: 11px">— вакансия —</span>
                                    </td>
                                    <td>
                                        <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="later(LATER.structure)">✏️</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- СОТРУДНИКИ ПО ФИО -->
            <div id="hr-tab-employees" :style="{ display: state.tab === 'employees' ? '' : 'none' }">
                <div style="display: flex; gap: 10px; margin-bottom: 12px; align-items: center; flex-wrap: wrap">
                    <input id="hr-staff-search" v-model="f.search" type="text" placeholder="Поиск по имени, должности..." style="flex: 1; max-width: 280px" />
                    <select id="hr-staff-dept" v-model="f.dept" style="font-size: 13px; width: auto">
                        <option value="all">Все подразделения</option>
                        <option value="prod">Производство</option>
                        <option value="aup">АУП</option>
                    </select>
                    <div style="flex: 1"></div>
                    <div style="display: flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden">
                        <button
                            id="hr-view-grid"
                            :style="{
                                padding: '5px 12px',
                                fontSize: '12px',
                                background: view === 'grid' ? 'var(--accent)' : 'transparent',
                                color: view === 'grid' ? '#fff' : 'var(--text2)',
                                border: 'none',
                                cursor: 'pointer',
                            }"
                            @click="view = 'grid'"
                        >
                            ⊞ Карточки
                        </button>
                        <button
                            id="hr-view-table"
                            :style="{
                                padding: '5px 12px',
                                fontSize: '12px',
                                background: view === 'table' ? 'var(--accent)' : 'transparent',
                                color: view === 'table' ? '#fff' : 'var(--text2)',
                                border: 'none',
                                cursor: 'pointer',
                            }"
                            @click="view = 'table'"
                        >
                            ☰ Таблица
                        </button>
                    </div>
                    <button class="btn btn-primary btn-sm" @click="openEmployeeCard(null)">+ Сотрудник</button>
                </div>
                <div id="hr-staff-grid" :style="{ display: view === 'grid' ? 'grid' : 'none', gridTemplateColumns: 'repeat(auto-fill,minmax(280px,1fr))', gap: '12px' }">
                    <div v-for="emp in list" :key="emp.id" v-memo="[emp]" class="card" style="padding: 0; overflow: hidden; cursor: pointer" @click="openEmployeeCard(emp)">
                        <div style="padding: 12px 14px; display: flex; gap: 12px; align-items: center; border-bottom: 1px solid var(--border); background: rgba(79, 142, 247, 0.15)">
                            <div
                                style="
                                    width: 40px;
                                    height: 40px;
                                    border-radius: 50%;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    font-weight: 700;
                                    font-size: 14px;
                                    background: var(--bg2);
                                    color: var(--accent);
                                "
                            >
                                {{ initials(emp.name) }}
                            </div>
                            <div style="flex: 1; min-width: 0">
                                <div style="font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ emp.name }}</div>
                                <div style="font-size: 11px; color: var(--text3)">
                                    {{ emp.pos }}{{ emp.type === 'salon' ? ' · Салон' : wsOf(emp) ? ' · ' + wsOf(emp)!.icon + ' ' + wsOf(emp)!.name : '' }}
                                </div>
                            </div>
                            <span class="badge" :class="typeBadge(emp.type)" style="font-size: 10px">{{ typeLabel(emp.type) }}</span>
                        </div>
                        <div style="padding: 10px 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 12px">
                            <div>
                                <div style="color: var(--text3); margin-bottom: 1px">Начислено (мес.)</div>
                                <div style="font-weight: 600; color: var(--green)">₸{{ ru(earned(emp)) }}</div>
                            </div>
                            <div>
                                <div style="color: var(--text3); margin-bottom: 1px">Явок в июле</div>
                                <div style="font-weight: 600">—</div>
                            </div>
                            <div v-if="emp.type === 'aup' || emp.type === 'salon'" style="grid-column: 1/-1">
                                <div style="color: var(--text3); margin-bottom: 1px">Оклад</div>
                                <div style="font-weight: 600">₸{{ ru(emp.salary) }}</div>
                            </div>
                        </div>
                        <div style="padding: 6px 14px; border-top: 1px solid var(--border); display: flex; gap: 6px">
                            <span v-if="emp.phone" style="font-size: 11px; color: var(--text3)">📞 {{ emp.phone }}</span>
                            <span style="font-size: 11px; color: var(--text3); margin-left: auto">с {{ emp.hired || '—' }}</span>
                        </div>
                    </div>
                </div>
                <div id="hr-staff-table" :style="{ display: view === 'table' ? '' : 'none' }">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Должность</th>
                                <th>Подразделение / Вид деятельности</th>
                                <th>Тип</th>
                                <th style="text-align: right">Оклад</th>
                                <th style="text-align: right">Начислено</th>
                                <th id="hr-pin-th" style="text-align: center" :style="{ display: props.canSeePin ? '' : 'none' }">🔐 PIN</th>
                                <th>Телефон</th>
                                <th>С</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="hr-staff-tbody">
                            <tr v-for="emp in list" :key="emp.id" v-memo="[emp]" style="cursor: pointer" @click="openEmployeeCard(emp)">
                                <td>
                                    <div style="font-weight: 600">{{ emp.name }}</div>
                                </td>
                                <td style="font-size: 12px">{{ emp.pos }}</td>
                                <td style="font-size: 12px; color: var(--text2)">{{ emp.type === 'salon' ? 'Салон' : wsOf(emp) ? wsOf(emp)!.icon + ' ' + wsOf(emp)!.name : 'АУП' }}</td>
                                <td>
                                    <span class="badge" :class="typeBadge(emp.type)" style="font-size: 10px">{{ typeLabel(emp.type) }}</span>
                                </td>
                                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px">{{ emp.salary ? '₸' + ru(emp.salary) : 'сдельно' }}</td>
                                <td style="text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--green)">₸{{ ru(earned(emp)) }}</td>
                                <td v-if="props.canSeePin" style="text-align: center"><span style="color: var(--text3); font-size: 11px">—</span></td>
                                <td style="font-size: 12px; color: var(--text3)">{{ emp.phone || '—' }}</td>
                                <td style="font-size: 11px; color: var(--text3)">{{ emp.hired || '—' }}</td>
                                <td>
                                    <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click.stop="openEmployeeCard(emp)">✏️</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ТАРИФЫ (legacy renderTariffs: the list of the page's code; its edits were never saved) -->
            <div id="hr-tab-tariffs" :style="{ display: state.tab === 'tariffs' ? '' : 'none' }">
                <div style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center">
                    <span style="font-size: 13px; color: var(--text2)">Тарифная сетка — ставки за операцию (₸/шт):</span>
                    <button class="btn btn-ghost btn-sm" @click="later(LATER.tariffs)">+ Добавить операцию</button>
                </div>
                <table class="data-table" style="max-width: 600px">
                    <thead>
                        <tr>
                            <th>Операция</th>
                            <th>Разряд</th>
                            <th>Ставка ₸/шт</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tariff-tbody">
                        <tr v-for="t in TARIFFS" :key="t.op">
                            <td><span class="editable" @click="later(LATER.tariffs)">{{ t.op }}</span></td>
                            <td style="font-family: 'IBM Plex Mono', monospace"><span class="editable" @click="later(LATER.tariffs)">{{ t.grade }}</span></td>
                            <td style="font-family: 'IBM Plex Mono', monospace; color: var(--green)">₸<span class="editable" @click="later(LATER.tariffs)">{{ t.rate }}</span></td>
                            <td><button class="row-delete-btn" title="Удалить" @click="later(LATER.tariffs)">✕</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- КОМИССИИ САЛОНОВ -->
            <div id="hr-tab-salon_commissions" :style="{ display: state.tab === 'salon_commissions' ? '' : 'none' }">
                <SalonCommissionsTab :employees="props.employees" />
            </div>

            <!-- ПОЛЬЗОВАТЕЛИ (D-13, ADR-0046) -->
            <div id="hr-tab-users" :style="{ display: state.tab === 'users' ? '' : 'none' }">
                <UsersTab v-if="props.canManageUsers" :accounts="props.accounts" :employees="props.employees" :templates="props.roleTemplates" />
            </div>

            <!-- Остальные вкладки — с правилами зарплаты -->
            <div v-for="(text, id) in TAB_LATER" :id="'hr-tab-' + id" :key="id" :style="{ display: state.tab === id ? '' : 'none' }">
                <div class="card">
                    <div style="font-size: 13px; color: var(--text3); line-height: 1.6">{{ text }}</div>
                </div>
            </div>
        </div>

        <ErpModal id="employeeCardModal" :open="card.open" :modal-style="{ maxWidth: '560px', maxHeight: '90vh', overflowY: 'auto' }" @close="card.open = false">
            <div class="modal-header">
                <span id="emp-card-title" class="modal-title">{{ card.emp ? card.emp.name : 'Новый сотрудник' }}</span>
                <button class="modal-close" @click="card.open = false">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 0 4px">
                <div class="form-row" style="grid-column: 1/-1">
                    <label class="label">ФИО *</label>
                    <input id="emp-card-name" v-model="card.name" type="text" placeholder="Иванов Иван Иванович" />
                </div>
                <div class="form-row">
                    <label class="label">Должность</label>
                    <input id="emp-card-pos" v-model="card.pos" type="text" placeholder="Столяр 4р" />
                </div>
                <div class="form-row">
                    <label class="label">Тип</label>
                    <select id="emp-card-type" v-model="card.type">
                        <option value="prod">Производственный (сдельный)</option>
                        <option value="aup">АУП (оклад)</option>
                        <option value="salon">Салон (продавец)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label class="label">Вид деятельности / Цех</label>
                    <select id="emp-card-ws" v-model="card.ws">
                        <option value="">— не указан —</option>
                        <option v-for="w in WORKSHOPS" :key="w.id" :value="w.id">{{ w.icon }} {{ w.name }}</option>
                    </select>
                </div>
                <div id="emp-salary-row" class="form-row" :style="{ display: props.salonManager ? 'none' : '' }">
                    <label class="label"
                        >Оклад (₸)
                        <span style="font-size: 10px; color: var(--text3); font-weight: 400"> — для истории изменяйте в Комиссиях </span>
                    </label>
                    <input id="emp-card-salary" v-model="card.salary" type="number" min="0" placeholder="0" />
                </div>
                <div class="form-row">
                    <label class="label">Телефон</label>
                    <input id="emp-card-phone" v-model="card.phone" type="text" placeholder="+7 701 000 0000" />
                </div>
                <div class="form-row">
                    <label class="label">ИИН</label>
                    <input id="emp-card-iin" v-model="card.iin" type="text" placeholder="000000000000" />
                </div>
                <div class="form-row">
                    <label class="label">Дата найма</label>
                    <input id="emp-card-hired" v-model="card.hired" type="date" />
                </div>
                <div id="emp-pin-row" class="form-row" :style="{ display: props.canSeePin ? '' : 'none' }">
                    <label class="label">🔐 PIN-код для Личного кабинета</label>
                    <div style="display: flex; gap: 8px; align-items: center">
                        <input
                            id="emp-card-pin"
                            type="text"
                            maxlength="6"
                            pattern="[0-9]{6}"
                            placeholder="6 цифр"
                            style="width: 130px; font-size: 18px; letter-spacing: 6px; font-family: monospace"
                        />
                        <button class="btn btn-ghost btn-sm" type="button" @click="later(LATER.pin)">🎲 Случайный</button>
                    </div>
                    <div style="font-size: 11px; color: var(--text3); margin-top: 4px">Рабочий вводит PIN на планшете в цеху для просмотра своей выработки</div>
                </div>
                <div class="form-row" style="grid-column: 1/-1">
                    <label class="label">Комментарий / Салон</label>
                    <div style="display: flex; gap: 8px">
                        <select id="emp-card-salon" v-model="card.salon" style="font-size: 13px; min-width: 160px">
                            <option value="">— не салон —</option>
                            <option value="Армада">🏬 Армада</option>
                            <option value="Назарбаева">🏪 Назарбаева</option>
                            <option value="Армада, Назарбаева">👑 Оба салона</option>
                        </select>
                        <input id="emp-card-comment" v-model="card.comment" type="text" placeholder="Доп. комментарий..." style="flex: 1" />
                    </div>
                </div>
            </div>
            <div id="emp-card-error" style="color: var(--red); font-size: 12px; margin-top: 8px; min-height: 16px"></div>

            <!-- Условия труда -->
            <div style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 4px">
                <div style="font-weight: 600; font-size: 13px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center">
                    <span>💼 Условия труда</span>
                    <button class="btn btn-ghost btn-sm" style="font-size: 11px" @click="openStaffChangesHistory">📋 История изменений</button>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px">
                    <div>
                        <label class="label">Оклад (₸)</label>
                        <input id="emp-base-salary" v-model="card.base" type="number" min="0" placeholder="0" class="input" />
                    </div>
                    <div>
                        <label class="label">Дата вступления в силу</label>
                        <input id="emp-change-date" v-model="card.changeDate" type="date" class="input" />
                    </div>
                    <div>
                        <label class="label">Удержание за обед (₸/мес)</label>
                        <input id="emp-meal" v-model="card.meal" type="number" min="0" placeholder="0" class="input" />
                    </div>
                    <div>
                        <label class="label">ОПВ %</label>
                        <input id="emp-opv-pct" v-model="card.opv" type="number" min="0" max="100" placeholder="10" class="input" />
                    </div>
                    <div>
                        <label class="label">ОСМС %</label>
                        <input id="emp-osms-pct" v-model="card.osms" type="number" min="0" max="100" placeholder="3" class="input" />
                    </div>
                    <div>
                        <label class="label">СО %</label>
                        <input id="emp-so-pct" v-model="card.so" type="number" min="0" max="100" placeholder="3.5" class="input" />
                    </div>
                </div>
                <div style="margin-top: 10px; display: flex; gap: 8px; align-items: center">
                    <select id="emp-status" v-model="card.status" style="font-size: 13px">
                        <option value="active">✅ Работает</option>
                        <option value="fired">❌ Уволен</option>
                    </select>
                    <input
                        id="emp-fired-date"
                        v-model="card.firedDate"
                        type="date"
                        style="font-size: 13px"
                        :style="{ display: card.status === 'fired' ? '' : 'none' }"
                        placeholder="Дата увольнения"
                    />
                </div>
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px">
                <button
                    id="emp-card-delete"
                    class="btn btn-ghost btn-sm"
                    style="margin-right: auto; color: var(--red)"
                    :style="{ display: card.emp && props.canDismiss ? '' : 'none' }"
                    @click="later(LATER.save)"
                >
                    🚪 Уволить
                </button>
                <button class="btn btn-ghost" @click="card.open = false">Отмена</button>
                <button class="btn btn-primary" @click="later(LATER.save)">Сохранить</button>
            </div>
        </ErpModal>
    </ErpLayout>
</template>
