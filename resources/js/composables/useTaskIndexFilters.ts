import { router } from '@inertiajs/vue3';
import { useTaskFilterState } from '@/shared/tasks/useTaskFilterState';
import type { TaskIndexFilters } from '@/shared/tasks/types';

type UseTaskIndexFiltersOptions = {
    filters: TaskIndexFilters;
};

export function useTaskIndexFilters(options: UseTaskIndexFiltersOptions) {
    const state = useTaskFilterState({
        filters: options.filters,
        includeClosed: false,
        completedStatuses: ['completed'],
    });

    function resetFilters() {
        state.resetFilters();
        router.get('/tasks', state.buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
        state.filtersOpen.value = false;
    }

    function applyFilters() {
        state.applyDraftToApplied();
        router.get('/tasks', state.buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
        state.filtersOpen.value = false;
    }

    return {
        ...state,
        applyFilters,
        resetFilters,
    };
}
