import { getRequirementStatusOptions } from '@/shared/tasks/presentation';
import type { TaskIndexFilters, TaskIndexSurface } from '@/shared/tasks/types';
import { useTaskFilterState } from '@/shared/tasks/useTaskFilterState';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type UseTaskIndexFiltersOptions = {
    filters: TaskIndexFilters;
    surface?: TaskIndexSurface;
};

export function useTaskIndexFilters(options: UseTaskIndexFiltersOptions) {
    const page = usePage<SharedData>();
    const state = useTaskFilterState({
        filters: options.filters,
        includeTypeFilter: false,
        includeClosed: false,
        completedStatuses: options.surface === 'requirements' ? [] : ['completed'],
        statusOptions: options.surface === 'requirements' ? getRequirementStatusOptions() : undefined,
    });
    const appliedComponent = ref(
        options.surface === 'app-errors' && ['shift_sdk', 'application'].includes(options.filters.component ?? '') ? options.filters.component! : '',
    );
    const activeFilterCount = computed(() => state.activeFilterCount.value + (appliedComponent.value ? 1 : 0));
    const canResetFilters = computed(() => state.canResetFilters.value || Boolean(appliedComponent.value));
    const providedOrganisationId =
        typeof options.filters.organisation_id === 'number' || typeof options.filters.organisation_id === 'string'
            ? String(options.filters.organisation_id)
            : '';

    function scopedOrganisationRoutePath() {
        if (!providedOrganisationId) return false;

        const current = new URL(page.url, 'https://shift.test');
        const surfacePath = options.surface ?? 'tasks';

        return current.pathname === `/organisation/${providedOrganisationId}/${surfacePath}`;
    }

    function isScopedOrganisationRoute() {
        return Boolean(scopedOrganisationRoutePath());
    }

    function indexPath() {
        const organisationScoped = isScopedOrganisationRoute();
        const surfacePath = options.surface === 'app-errors' && !organisationScoped ? 'error-reports' : (options.surface ?? 'tasks');

        return organisationScoped ? `/organisation/${providedOrganisationId}/${surfacePath}` : `/${surfacePath}`;
    }

    function buildListQuery(page: number) {
        const query = {
            ...state.buildListQuery(page),
            ...(appliedComponent.value ? { component: appliedComponent.value } : {}),
        };

        if (isScopedOrganisationRoute()) {
            return Object.fromEntries(Object.entries(query).filter(([key]) => key !== 'organisation_id'));
        }

        return query;
    }

    function resetFilters() {
        state.resetFilters();
        appliedComponent.value = '';
        router.get(indexPath(), buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
        state.filtersOpen.value = false;
    }

    function applyFilters() {
        state.applyDraftToApplied();
        router.get(indexPath(), buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
        state.filtersOpen.value = false;
    }

    function setComponent(value: string) {
        appliedComponent.value = ['shift_sdk', 'application'].includes(value) ? value : '';
        router.get(indexPath(), buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
    }

    function searchTasks(term: string) {
        state.appliedSearchTerm.value = term.trim();
        if (!state.filtersOpen.value) state.draftSearchTerm.value = state.appliedSearchTerm.value;
        router.get(indexPath(), buildListQuery(1), { preserveState: true, preserveScroll: true, replace: true });
    }

    return {
        ...state,
        activeFilterCount,
        canResetFilters,
        appliedComponent,
        setComponent,
        applyFilters,
        buildListQuery,
        indexPath,
        resetFilters,
        searchTasks,
    };
}
