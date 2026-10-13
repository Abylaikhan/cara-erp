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
    flash: { alert: string | null };
}
