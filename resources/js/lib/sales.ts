// M09 «Продажи» — orders as the journal shows them: legacy constants and formats (L28800, L17723, L23027, L29981).
import { router } from '@inertiajs/vue3';

export interface OrderRow {
    id: number;
    num: string;
    channel: string;
    customer: string | null;
    end_client: string | null;
    seller: string | null;
    created: string | null;
    date: string | null;
    payment: number;
    status: string;
    total: number;
    progress: number;
    can_complete: boolean;
    lock_version: number;
}

export const STATUS_LABELS: Record<string, string> = { queue: 'В очереди', progress: 'В работе', done: 'Готово' };
export const STATUS_BADGE: Record<string, string> = { queue: 'badge-amber', progress: 'badge-blue', done: 'badge-green' };
export const PROGRESS_CLASS: Record<string, string> = { queue: 'amber', progress: 'blue', done: 'green' };
export const PL_MONTH_LABELS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

/** Legacy fmtCurrency. */
export const fmtCurrency = (n: number): string => '₸' + Math.round(Number.isNaN(n) ? 0 : n).toLocaleString('ru');

/** Paid part of the total by the legacy percentage (capped at 100 %). */
export const paidOf = (o: OrderRow): number => (o.total * Math.min(o.payment || 0, 100)) / 100;

/** DD.MM.YYYY → YYYY-MM-DD (legacy parseDMY / toISO). */
export const toISO = (d: string | null): string => {
    if (!d) return '';
    const p = d.split('.');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : d;
};

/** Legacy getOrderSortValue. */
export function sortValue(o: OrderRow, col: string): string | number {
    const paid = paidOf(o);
    switch (col) {
        case 'num':
            return o.num || '';
        case 'customer':
            return (o.customer || '').toLowerCase();
        case 'total':
            return o.total;
        case 'paid':
            return paid;
        case 'debt':
            return o.total - paid;
        case 'date':
            return toISO(o.date || o.created || '');
        default:
            return toISO(o.date || '');
    }
}

/** Legacy markOrderDone: a dealer order realized to 99 % is completed after a confirmation. */
export function markOrderDone(o: OrderRow): void {
    if (!window.confirm(`Отметить заказ ${o.num} как завершённый?`)) return;
    router.post(`/sales/orders/${o.id}/complete`, { lock_version: o.lock_version }, {
        preserveScroll: true,
        preserveState: true,
        only: ['orders', 'flash'],
        onError: (errors: Record<string, string>) => window.alert('Ошибка: ' + (Object.values(errors)[0] ?? '')),
    });
}

/** The order form comes with editing orders (ERP-030). */
export const ORDER_FORM_LATER = 'Карточка заказа откроется после переноса редактирования заказов: цена и состав заказа ждут решений D-02 и D-12.';

// ── Realization journal (legacy renderSalesJournal) ──
export interface RealizationRow {
    id: number;
    date: string;
    doc_num: string | null;
    order_num: string | null;
    customer: string | null;
    name: string;
    qty: number;
    revenue: number;
}

/** Legacy short channel names of the journal. */
export const SJ_CHANNELS: Record<string, string> = {
    office: 'Офис',
    salon_armada: 'Армада',
    salon_nazarbayeva: 'Назарбаева',
    dealer_astana: 'Астана',
    dealer_aktau: 'Актау',
    dealer_shymkent: 'Шымкент',
    dealer_atyrau: 'Атырау',
    dealer_aktobe: 'Актобе',
};

/** Order status in the journal. Legacy named the orders in work «production», which they never are, and showed them queued. */
export const sjStatus = (status: string | undefined): string =>
    status === undefined
        ? '—'
        : ({ done: '✅ Завершён', delivered: '🚚 Доставлен', ready: '📦 Готов', progress: '🔨 В работе', production: '🔨 В работе' }[status] ?? '⏳ В очереди');

/** Business date YYYY-MM-DD of a calendar day (legacy localISODate). */
export const localISODate = (y: number, m: number, d: number): string => {
    const dt = new Date(y, m, d);
    return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
};

/** Legacy swIsDriverUser: the role or the name of the account says «водитель». */
export const isDriverUser = (u: { name?: string; roleLabel?: string } | null | undefined): boolean => !!u && /водител/i.test((u.roleLabel || '') + ' ' + (u.name || ''));

/** Legacy swDriverMatches: the driver of a note and the name of the account, one within the other. */
export const driverMatches = (driver: string | null | undefined, name: string | null | undefined): boolean => {
    const a = String(driver || '')
        .toLowerCase()
        .trim();
    const b = String(name || '')
        .toLowerCase()
        .trim();
    return !!a && !!b && (a.includes(b) || b.includes(a));
};
