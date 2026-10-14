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
