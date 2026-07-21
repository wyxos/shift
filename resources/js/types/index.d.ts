import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';
import type { Config } from 'ziggy-js';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SidebarOrganisation {
    id: number;
    name: string;
    isOwner: boolean;
    can_manage_org_access?: boolean;
}

export interface SharedData extends PageProps {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: Omit<Config, 'location'> & { location: string };
    sidebarOpen: boolean;
    sidebarOrganisations?: SidebarOrganisation[];
    sidebarOrganisationsHasMore?: boolean;
    shift?: {
        ai_rewrite_enabled: boolean;
        ai_email_import_enabled: boolean;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: SharedData;
    }
}
