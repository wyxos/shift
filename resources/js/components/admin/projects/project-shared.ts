export type ProjectRow = {
    id: number;
    name: string;
    client_id?: number | null;
    organisation_id?: number | null;
    client_name?: string | null;
    organisation_name?: string | null;
    isOwner?: boolean;
    token?: string | null;
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
};

export type ProjectAccessUser = {
    id: number;
    user_name?: string | null;
    user_email?: string | null;
    registration_status?: string | null;
};

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

export function projectScopeLabel(project: ProjectRow) {
    if (project.client_name) {
        return project.client_name;
    }

    if (project.organisation_name) {
        return project.organisation_name;
    }

    return 'Standalone project';
}

export function projectScopeVariant(project: ProjectRow) {
    if (project.client_name || project.organisation_name) {
        return 'secondary';
    }

    return 'outline';
}

export function accessStatusLabel(projectUser: ProjectAccessUser) {
    return projectUser.registration_status === 'registered' ? 'Registered' : 'Pending invitation';
}
