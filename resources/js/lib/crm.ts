// CRM (M10) — shared by the tabs «Лиды», «Воронка лидов» and «Задачи»: types, legacy formats and the actions of legacy
// quickChangeLeadStatus / crmDrop / leadWon / leadLost / convertLeadToClient / crmMoveToNextStage / toggleTask
// (L28949–32135). Every action is saved by the server.
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

export interface LeadRow {
    id: number;
    name: string;
    phone: string | null;
    status: string;
    interest: string | null;
    budget: number;
    source: string | null;
    comment: string | null;
    manager: string | null;
    segment: number | null;
    tags: string[];
    next_date: string | null;
    next_action: string | null;
    created_at: string | null;
    lock_version: number;
}

export interface LeadStatus {
    label: string;
    color: string;
}

export interface Segment {
    id: number;
    label: string;
    color: string;
}

export interface FunnelStage {
    key: string;
    label: string;
    statuses: string[];
}

/** Legacy crmFmtAmount. */
export const crmFmtAmount = (n: number): string => (!n ? '—' : '₸' + (n >= 1000000 ? (n / 1000000).toFixed(1) + 'М' : n >= 1000 ? (n / 1000).toFixed(0) + 'К' : String(n)));

/** Legacy `created`: the creation date as the browser shows it (dd.mm.yyyy). */
export const createdLabel = (l: LeadRow): string => (l.created_at ? new Date(l.created_at).toLocaleDateString('ru-RU') : '');

/** Props a CRM change reloads; `flash` carries the server's message (a partial reload leaves out what it does not name). */
export const RELOAD = ['leads', 'managers', 'flash'];
const visit = { preserveScroll: true, preserveState: true, only: RELOAD };
const fail = (errors: Record<string, string>) => window.alert(Object.values(errors)[0] ?? 'Ошибка сохранения');

/** «🎉 Сделка закрыта!» after a lead is won (crmConvertModal). */
export const convert = reactive({ open: false, label: '' });

export function changeStatus(lead: LeadRow, status: string, reason: string | null = null): void {
    router.put(`/crm/leads/${lead.id}/status`, { status, reason, lock_version: lead.lock_version }, { ...visit, onError: fail });
}

/** Legacy leadWon: confirm, save «Выиграли», offer to create the customer's order. */
export function leadWon(lead: LeadRow): void {
    if (!window.confirm(`✅ Лид «${lead.name}» выиграли?\nСтатус изменится на «Выиграли» и лид уйдёт из воронки.`)) return;
    router.put(`/crm/leads/${lead.id}/status`, { status: 'won', lock_version: lead.lock_version }, {
        ...visit,
        onError: fail,
        onSuccess: () => {
            convert.label = `«${lead.name}» выиграли! Создать заказ покупателя?`;
            convert.open = true;
        },
    });
}

/** Legacy leadLost: the reason is optional; «Отмена» keeps the lead as it is. */
export function leadLost(lead: LeadRow): void {
    const reason = window.prompt(`❌ Причина отказа «${lead.name}»?\n(необязательно, нажмите OK чтобы пропустить)`);
    if (reason === null) return;
    changeStatus(lead, 'lost', reason);
}

/** Legacy quickChangeLeadStatus: «Выиграли» and «Проиграли» go through their own dialogs. */
export function quickChangeLeadStatus(lead: LeadRow, status: string): void {
    if (status === lead.status) return;
    if (status === 'won') return leadWon(lead);
    if (status === 'lost') return leadLost(lead);
    changeStatus(lead, status);
}

/** Legacy convertLeadToClient: a customer of the lead's channel, and the lead is won. */
export function convertLeadToClient(lead: LeadRow): void {
    if (!window.confirm(`Перевести "${lead.name}" в клиенты?`)) return;
    router.post(`/crm/leads/${lead.id}/client`, { lock_version: lead.lock_version }, { ...visit, onError: fail });
}

/** Functions of other modules: orders (M09). */
export const ORDERS_LATER = 'Создание заказа появится после решений по сохраняемой цене и составу заказа (D-02, D-12).';

// ── «→ Следующий этап» (legacy crmMoveToNextStage, CRM_STAGE_ORDER) ──
/** Stage of a lead in the funnel order: «Контакт» and «Встреча» stand in «Квалификация», a new lead is «Лид». */
export const stageOfStatus = (status: string): string => (['contact', 'meeting'].includes(status) ? 'qualify' : status);

/** Next status after the lead's stage, up to «Выиграли»; null when there is none. */
export function nextStatus(lead: LeadRow, order: string[]): string | null {
    const idx = order.indexOf(stageOfStatus(lead.status));
    return idx < 0 || idx >= order.indexOf('won') ? null : order[idx + 1];
}

/** Moves the lead on; «Выиграли» offers the customer's order, as legacy does (without the confirmation of the card button). */
export function moveToNextStage(lead: LeadRow, order: string[]): void {
    const status = nextStatus(lead, order);
    if (status === null) return;
    router.put(`/crm/leads/${lead.id}/status`, { status, lock_version: lead.lock_version }, {
        ...visit,
        onError: fail,
        onSuccess: () => {
            if (status !== 'won') return;
            convert.label = `«${lead.name}» выиграли! Создать заказ покупателя?`;
            convert.open = true;
        },
    });
}

// ── Business time: «today» and «now» in the business time zone (legacy took the UTC date, so before 05:00 in Almaty
// «today» was yesterday) ──
export function businessNow(timezone: string): { date: string; time: string } {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(new Date())
            .map((p) => [p.type, p.value]),
    );
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

/** Whole days from `from` to `to` (both YYYY-MM-DD). */
export const daysBetween = (from: string, to: string): number => Math.round((Date.parse(to + 'T00:00:00Z') - Date.parse(from + 'T00:00:00Z')) / 86400000);

// ── Tasks (legacy tasks, PRIORITY_CONFIG, isTaskOverdue, fmtTaskDateTime) ──
export interface TaskRow {
    id: number;
    title: string;
    due_date: string | null;
    due_time: string | null;
    manager: string | null;
    priority: string;
    status: string;
    lead_id: number | null;
    lock_version: number;
}

export const PRIORITY_CONFIG: Record<string, { label: string; icon: string; cls: string; textCls: string }> = {
    high: { label: 'Высокий', icon: '🔴', cls: 'task-high', textCls: 'priority-high' },
    medium: { label: 'Средний', icon: '🟡', cls: 'task-medium', textCls: 'priority-medium' },
    low: { label: 'Низкий', icon: '⚪', cls: 'task-low', textCls: 'priority-low' },
};
/** The form stores «normal», which PRIORITY_CONFIG calls «medium». */
export const priorityOf = (t: TaskRow) => PRIORITY_CONFIG[t.priority] ?? PRIORITY_CONFIG.medium;

export function isTaskOverdue(t: TaskRow, now: { date: string; time: string }): boolean {
    if (t.status === 'done' || !t.due_date) return false;
    if (t.due_date < now.date) return true;
    return t.due_date === now.date && !!t.due_time && t.due_time < now.time;
}

export function fmtTaskDateTime(t: TaskRow): string {
    if (!t.due_date) return '—';
    const d = new Date(t.due_date + 'T00:00:00').toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    return d + (t.due_time ? ' ' + t.due_time : '');
}

export const TASK_RELOAD = ['tasks', 'managers', 'flash'];

/** Legacy toggleTask: the round checkbox. */
export function toggleTask(t: TaskRow): void {
    router.put(`/crm/tasks/${t.id}/toggle`, { lock_version: t.lock_version }, { preserveScroll: true, preserveState: true, only: TASK_RELOAD, onError: fail });
}

// ── Communication history of a lead (legacy COMM_TYPES) ──
export interface Communication {
    id: number;
    type: string;
    happened_on: string | null;
    text: string;
    manager: string | null;
}

export const COMM_TYPES: Record<string, { label: string; icon: string; dot: string }> = {
    call: { label: 'Звонок', icon: '📞', dot: 'comm-dot-call' },
    letter: { label: 'Письмо', icon: '✉️', dot: 'comm-dot-letter' },
    meeting: { label: 'Встреча', icon: '🤝', dot: 'comm-dot-meeting' },
    whatsapp: { label: 'WhatsApp', icon: '💬', dot: 'comm-dot-whatsapp' },
    email: { label: 'Email', icon: '📧', dot: 'comm-dot-email' },
    note: { label: 'Заметка', icon: '📝', dot: 'comm-dot-note' },
};
