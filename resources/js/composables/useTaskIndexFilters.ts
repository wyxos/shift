import type { TaskIndexFilters } from '@/shared/tasks/types';
import { useTaskFilterState } from '@/shared/tasks/useTaskFilterState';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';

type UseTaskIndexFiltersOptions = {
    filters: TaskIndexFilters;
};

export function useTaskIndexFilters(options: UseTaskIndexFiltersOptions) {
    const page = usePage<SharedData>();
    const state = useTaskFilterState({
        filters: options.filters,
        includeClosed: false,
        completedStatuses: ['completed'],
    });
    const providedOrganisationId =
        typeof options.filters.organisation_id === 'number' || typeof options.filters.organisation_id === 'string'
            ? String(options.filters.organisation_id)
            : '';

    function isScopedOrganisationRoute() {
        if (!providedOrganisationId) return false;

        const current = new URL(page.url, 'https://shift.test');

        return current.pathname === `/organisation/${providedOrganisationId}/tasks`;
    }

    function indexPath() {
        return isScopedOrganisationRoute() ? `/organisation/${providedOrganisationId}/tasks` : '/tasks';
    }

    function buildListQuery(page: number) {
        const query = state.buildListQuery(page);

        if (isScopedOrganisationRoute()) {
            return Object.fromEntries(Object.entries(query).filter(([key]) => key !== 'organisation_id'));
        }

        return query;
    }

    function resetFilters() {
        state.resetFilters();
        router.get(indexPath(), buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
        state.filtersOpen.value = false;
    }

    function applyFilters() {
        state.applyDraftToApplied();
        router.get(indexPath(), buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
        state.filtersOpen.value = false;
    }

    return {
        ...state,
        applyFilters,
        buildListQuery,
        indexPath,
        resetFilters,
    };
}
