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
    };
}

/** Dealer of the «Покупатели» page: a sales channel of the group «dealer». */
export interface Dealer {
    code: string;
    name: string;
    region: string | null;
    lock_version: number;
}
