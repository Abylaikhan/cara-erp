// M10 «Проекты» — shared pieces of legacy L31099–31160: statuses, money and date formats, image compression, the toast.
import { reactive } from 'vue';

export const PRJ_STATUSES: Record<string, { label: string; color: string }> = {
    request: { label: 'Запрос', color: '#a78bfa' },
    calc: { label: 'В расчёте', color: '#f5a623' },
    sent: { label: 'Отправлен заказчику', color: '#4f8ef7' },
    approved: { label: 'Согласован', color: '#06b6d4' },
    order: { label: 'Стал заказом', color: '#3dd68c' },
    closed: { label: 'Закрыт', color: '#8a8f98' },
};
export const isOpen = (status: string) => status !== 'order' && status !== 'closed';

export interface CalcItem {
    name: string;
    attrs: string;
    qty: string | number;
    cost?: string | number;
    price: string | number;
}
export interface ProjectCalc {
    version: number;
    items: CalcItem[];
    total: string;
    markup_pct: string | null;
    sent_at: string | null;
    created_by_name: string | null;
}
export interface ProjectFileRow {
    id: number;
    side: 'customer' | 'designer';
    internal: boolean;
    file_name: string;
    image: boolean;
    size: number;
    uploaded_by_login: string | null;
    uploaded_by_name: string | null;
    created_at: string | null;
}
export interface ProjectCommentRow {
    id: number;
    side: 'client' | 'staff';
    internal: boolean;
    text: string;
    author_name: string | null;
    created_at: string | null;
}
export interface ProjectRow {
    id: number;
    num: string | null;
    title: string;
    channel: string;
    status: string;
    customer_name: string | null;
    description: string | null;
    due_date: string | null;
    executors: { login: string; name: string }[];
    responsible_name: string | null;
    order_num: string | null;
    total_amount: string | null;
    created_at: string | null;
    updated_at: string | null;
    lock_version: number;
    calcs: ProjectCalc[];
    files: ProjectFileRow[];
    comments: ProjectCommentRow[];
}
export interface ProjectMeta {
    client: boolean;
    admin: boolean;
    login: string;
    channels: { code: string; name: string; group: string; own: boolean }[];
    staff: { login: string; name: string; label: string }[];
}

/** Partial reload after an action on a project; 'flash' carries the number of what was created. */
export const PROJECT_RELOAD = ['projects', 'projectsCount', 'flash'];

/** Legacy prjFmt. */
export const prjFmt = (n: number | string | null | undefined) => '₸' + Math.round(Number(n) || 0).toLocaleString('ru-RU');

/** Legacy prjDate: dd.mm.yy hh:mm (business time zone, as the users' browsers are there). */
export function prjDate(s: string | null, timeZone: string): string {
    if (!s) return '';
    const d = new Date(s);
    if (isNaN(d.getTime())) return String(s).slice(0, 10);
    return (
        d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit', timeZone }) +
        ' ' +
        d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone })
    );
}

/** Legacy prjStatusBadge style. */
export const badgeStyle = (color: string) => ({
    display: 'inline-block',
    padding: '2px 9px',
    borderRadius: '10px',
    fontSize: '11px',
    fontWeight: '600',
    whiteSpace: 'nowrap',
    background: color + '22',
    color,
});

/** Legacy prjTotal / prjCostTotal (display only — the server counts the saved total). */
export const prjTotal = (items: CalcItem[]) => items.reduce((s, i) => s + (+i.qty || 0) * (+i.price || 0), 0);
export const prjCostTotal = (items: CalcItem[]) => items.reduce((s, i) => s + (+i.qty || 0) * (+(i.cost ?? 0) || 0), 0);

/** Legacy prjCompressImage: photos over 1.5 MB are scaled to 2200 px and saved as JPEG when that is smaller. */
export function prjCompressImage(file: File): Promise<File> {
    return new Promise((res) => {
        if (!file.type.startsWith('image/') || file.type === 'image/gif' || file.size < 1.5 * 1048576) return res(file);
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            const max = 2200;
            const k = Math.min(1, max / Math.max(img.width, img.height));
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * k);
            c.height = Math.round(img.height * k);
            c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
            c.toBlob(
                (b) => {
                    if (b && b.size < file.size) res(new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
                    else res(file);
                },
                'image/jpeg',
                0.86,
            );
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            res(file);
        };
        img.src = url;
    });
}

/** Legacy prjToast: one message at the bottom of the screen, green or red; errors stay longer. */
export const toast = reactive({ text: '', error: false, visible: false, timer: 0 });
export function prjToast(msg: string, isErr = false): void {
    window.clearTimeout(toast.timer);
    Object.assign(toast, { text: msg, error: isErr, visible: true });
    toast.timer = window.setTimeout(() => (toast.visible = false), isErr ? 6000 : 2500);
}
/** The first message of a failed request. */
export const firstError = (errors: Record<string, string>) => Object.values(errors)[0] ?? 'Ошибка сохранения';
