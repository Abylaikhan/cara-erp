export interface SidebarUser {
    name: string;
    initials: string;
    color: string;
    roleLabel: string;
}

export interface NavItem {
    id: string;
    label: string;
    href: string;
    icon: string;
}

export interface NavSection {
    label: string;
    items: NavItem[];
}

export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    auth: { user: SidebarUser | null };
    nav: NavSection[];
    page: { id: string; title: string; module: string } | null;
    flash: {
        alert: string | null;
        toast: string | null;
        result: { text: string; color: 'green' | 'red' } | null;
        dialog: { type: 'alert' | 'confirm'; text: string } | null;
        esfPreview: EsfPreview | null;
    };
}

/** Dealer of the «Покупатели» page: a sales channel of the group «dealer». */
export interface Dealer {
    code: string;
    name: string;
    region: string | null;
    lock_version: number;
}

/** An order of a dealer channel as the dealers tab counts it (legacy recalcAllCustomerTurnover). */
export interface DealerOrder {
    channel: string;
    date: string | null;
    total: number;
    payment: number;
}

/** Preview of an ESF XML import (EsfImportService::preview). */
export interface EsfPreview {
    token: string;
    total: number;
    ok: number;
    rows: {
        index: number;
        ok: boolean;
        fileName: string;
        error?: string;
        date?: string;
        num?: string;
        supplier_name?: string;
        supplier_bin?: string;
        lines?: number;
        total_amount?: number;
        total_nds?: number;
        total_with_nds?: number;
        duplicate?: string | null;
    }[];
}
