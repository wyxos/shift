export type ProjectRow = {
    id: number;
    name: string;
    client_id?: number | null;
    organisation_id?: number | null;
    client_name?: string | null;
    organisation_name?: string | null;
    isOwner?: boolean;
    can_delete_project?: boolean;
    can_manage_project_access?: boolean;
    can_manage_technical_settings?: boolean;
    token?: string | null;
    external_widget_enabled?: boolean;
    external_widget_guest_submissions_enabled?: boolean;
};

export type ProjectPaginator = {
    data: ProjectRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Option = {
    id: number;
    name: string;
};

export type ProjectFilters = {
    search?: string | null;
    sort_by?: string | null;
    organisation_id?: number | string | null;
};

export type ProjectAccessUser = ManagedAccessUser;

export type SortBy = 'newest' | 'oldest' | 'name';

export const defaultSortBy: SortBy = 'newest';

export const sortOptions = [
    { value: 'newest', label: 'Newest' },
    { value: 'oldest', label: 'Oldest' },
    { value: 'name', label: 'Name' },
] satisfies { value: SortBy; label: string }[];

export function normalizeSortBy(value: string | null | undefined): SortBy {
    if (value === 'oldest' || value === 'name') {
        return value;
    }

    return defaultSortBy;
}

export function normalizeNullableId(value: number | string | null | undefined) {
    if (value === null || value === undefined || value === '' || value === 'null') {
        return null;
    }

    return Number(value);
}

export function projectClientLabel(project: ProjectRow) {
    return project.client_name?.trim() || null;
}

export function projectOrganisationLabel(project: ProjectRow) {
    if (project.organisation_name) {
        return project.organisation_name;
    }

    return 'Standalone';
}

import type { ManagedAccessUser } from '../access-users';
