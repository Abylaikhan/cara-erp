// CRM leads (M10) — shared by the «Лиды» and «Воронка лидов» tabs: types, legacy formats and the status actions of legacy
// quickChangeLeadStatus / crmDrop / leadWon / leadLost / convertLeadToClient (L28949–32135). Every action is saved by the server.
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

/** Functions of other modules: orders (M09) and CRM tasks (next part). */
export const ORDERS_LATER = 'Заказы покупателей появятся после переноса раздела «Продажи».';
export const TASKS_LATER = 'Задачи CRM появятся в следующей части переноса раздела.';
