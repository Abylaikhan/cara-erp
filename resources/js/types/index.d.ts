export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    auth: { user: AuthUser | null };
}
