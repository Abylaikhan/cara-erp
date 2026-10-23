// Warehouse helpers of legacy «Склад» (L1300–1330, L27578–27790, L28305–28400): stock status, item type by category, money
// formats and category icons. Kept as in legacy so the page shows the same numbers.

export interface MaterialRow {
    id: number;
    name: string;
    category: string;
    unit: string;
    sku: string | null;
    qty: number;
    price: number;
    min_qty: number;
    warehouse: string | null;
    lock_version: number;
}

export interface PhysicalWarehouse {
    code: string;
    name: string;
    icon: string;
    color: string;
    type: 'material' | 'semi' | 'product';
    categories: string[];
}

export interface Workshop {
    id: string;
    name: string;
    icon: string;
}

export interface MaterialAliasRow {
    id: number;
    material_name: string;
    alias_name: string;
    category: string | null;
}

export type StockStatus = 'ok' | 'low' | 'critical';

/** Legacy whStatus: no minimum — «Норма»; below 60 % of the minimum — «Критично»; below the minimum — «Мало». */
export function whStatus(m: Pick<MaterialRow, 'qty' | 'min_qty'>): StockStatus {
    if (!m.min_qty) return 'ok';
    if (m.qty < m.min_qty * 0.6) return 'critical';
    if (m.qty < m.min_qty) return 'low';
    return 'ok';
}

export const STATUS_BADGE: Record<StockStatus, { cls: string; label: string }> = {
    critical: { cls: 'badge-red', label: 'Критично' },
    low: { cls: 'badge-amber', label: 'Мало' },
    ok: { cls: 'badge-green', label: 'Норма' },
};

export const statusColor = (s: StockStatus, normal: string): string => (s === 'critical' ? 'var(--red)' : s === 'low' ? 'var(--amber)' : normal);

/** Legacy getWhItemType: the type of the warehouse that lists the category, «material» otherwise. */
export function itemType(category: string, warehouses: PhysicalWarehouse[]): PhysicalWarehouse['type'] {
    return warehouses.find((w) => w.categories.includes(category))?.type ?? 'material';
}

/** Legacy fmtCurrency. */
export const fmtCurrency = (n: number): string => '₸' + Math.round(isNaN(n) ? 0 : n).toLocaleString('ru');

/** Card value of «По складам»/«По категориям»: 1.2М, 350К or «—». */
export const fmtCardValue = (v: number): string => (v >= 1000000 ? (v / 1000000).toFixed(1) + 'М' : v > 0 ? Math.round(v / 1000) + 'К' : '—');

/** Legacy catIcons of renderWhStocks. */
export const CAT_ICONS: Record<string, string> = {
    Столы: '🪑',
    Стулья: '🪑',
    Диваны: '🛋',
    Кресла: '🛋',
    Кровати: '🛏',
    Комоды: '🗄',
    Шкафы: '🗄',
    Тумбы: '🗄',
    Витрины: '🪟',
    Зеркала: '🪞',
    Консоли: '🪞',
    'Ткань/Обивочные': '🧵',
    'Поролон/Набивка': '🧸',
    Пиломатериалы: '🪵',
    'ЛДСП/МДФ': '🪵',
    Шпон: '🪵',
    Стекло: '🪟',
    'Фурнитура/Крепёж': '🔩',
    'Мягкая фурнитура': '📎',
    'Крепёж Novus': '🔩',
    'ЛКМ/Химия': '🎨',
    'Инструмент/Абразивы': '🔧',
    'Металл/Профиль': '⚙️',
    Расходники: '📦',
    Прочее: '📋',
};

/** Warehouse documents (receipt, transfer, write-off, inventory) move stock; they wait for decisions D-04/D-05. */
export const DOCUMENTS_LATER = 'Перемещение, списание и применение инвентаризации появятся следующим шагом: приход уже проводится (№ 45, ADR-0049); отмена движения ждёт ответа на вопрос № 47.';
