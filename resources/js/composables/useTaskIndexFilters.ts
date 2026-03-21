import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { getDefaultStatuses, getSortByOptions, getStatusOptions, normalizeStringList, DEFAULT_SORT_BY, getPriorityOptions } from '@/shared/tasks/presentation';
import type { TaskIndexFilters } from '@/shared/tasks/types';

type UseTaskIndexFiltersOptions = {
    filters: TaskIndexFilters;
};

export function useTaskIndexFilters(options: UseTaskIndexFiltersOptions) {
    const statusOptions = getStatusOptions({ includeClosed: false });
    const priorityOptions = getPriorityOptions();
    const sortByOptions = getSortByOptions();
    const defaultSortBy = DEFAULT_SORT_BY;
    const allowedSortBy = new Set(sortByOptions.map((option) => option.value));
    const defaultStatuses = getDefaultStatuses(statusOptions, ['completed']);
    const allPriorities = priorityOptions.map((option) => option.value);

    const providedStatuses = normalizeStringList(options.filters.status);
    const providedPriorities = normalizeStringList(options.filters.priority);
    const providedSearchTerm = typeof options.filters.search === 'string' ? options.filters.search : '';
    const providedEnvironmentTerm = typeof options.filters.environment === 'string' ? options.filters.environment : '';
    const providedSortBy =
        typeof options.filters.sort_by === 'string' && allowedSortBy.has(options.filters.sort_by) ? options.filters.sort_by : defaultSortBy;

    const filtersOpen = ref(false);
    const appliedStatuses = ref<string[]>(providedStatuses.length ? providedStatuses : [...defaultStatuses]);
    const appliedPriorities = ref<string[]>(providedPriorities.length ? providedPriorities : [...allPriorities]);
    const appliedSearchTerm = ref(providedSearchTerm);
    const appliedEnvironmentTerm = ref(providedEnvironmentTerm);
    const appliedSortBy = ref(providedSortBy);

    const draftStatuses = ref<string[]>([...appliedStatuses.value]);
    const draftPriorities = ref<string[]>([...appliedPriorities.value]);
    const draftSearchTerm = ref(appliedSearchTerm.value);
    const draftEnvironmentTerm = ref(appliedEnvironmentTerm.value);
    const draftSortBy = ref(appliedSortBy.value);

    watch(filtersOpen, (open) => {
        if (!open) return;
        draftStatuses.value = [...appliedStatuses.value];
        draftPriorities.value = [...appliedPriorities.value];
        draftSearchTerm.value = appliedSearchTerm.value;
        draftEnvironmentTerm.value = appliedEnvironmentTerm.value;
        draftSortBy.value = appliedSortBy.value;
    });

    const activeFilterCount = computed(() => {
        let count = 0;
        if (appliedStatuses.value.length && appliedStatuses.value.length < statusOptions.length) count += 1;
        if (appliedPriorities.value.length && appliedPriorities.value.length < priorityOptions.length) count += 1;
        if (appliedSearchTerm.value.trim()) count += 1;
        if (appliedEnvironmentTerm.value.trim()) count += 1;
        if (appliedSortBy.value !== defaultSortBy) count += 1;
        return count;
    });

    function syncAppliedToDraft() {
        draftStatuses.value = [...appliedStatuses.value];
        draftPriorities.value = [...appliedPriorities.value];
        draftSearchTerm.value = appliedSearchTerm.value;
        draftEnvironmentTerm.value = appliedEnvironmentTerm.value;
        draftSortBy.value = appliedSortBy.value;
    }

    function resetFilters() {
        draftStatuses.value = [...defaultStatuses];
        draftPriorities.value = [...allPriorities];
        draftSearchTerm.value = '';
        draftEnvironmentTerm.value = '';
        draftSortBy.value = defaultSortBy;

        appliedStatuses.value = [...draftStatuses.value];
        appliedPriorities.value = [...draftPriorities.value];
        appliedSearchTerm.value = draftSearchTerm.value;
        appliedEnvironmentTerm.value = draftEnvironmentTerm.value;
        appliedSortBy.value = draftSortBy.value;

        router.get(
            '/tasks',
            {
                status: appliedStatuses.value,
                priority: appliedPriorities.value,
                search: appliedSearchTerm.value || undefined,
                environment: appliedEnvironmentTerm.value || undefined,
                sort_by: appliedSortBy.value,
                page: 1,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
        filtersOpen.value = false;
    }

    function applyFilters() {
        appliedStatuses.value = [...draftStatuses.value];
        appliedPriorities.value = [...draftPriorities.value];
        appliedSearchTerm.value = draftSearchTerm.value;
        appliedEnvironmentTerm.value = draftEnvironmentTerm.value;
        appliedSortBy.value = draftSortBy.value;

        router.get(
            '/tasks',
            {
                status: appliedStatuses.value,
                priority: appliedPriorities.value,
                search: appliedSearchTerm.value || undefined,
                environment: appliedEnvironmentTerm.value || undefined,
                sort_by: appliedSortBy.value,
                page: 1,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
        filtersOpen.value = false;
    }

    function selectAllStatuses() {
        draftStatuses.value = statusOptions.map((option) => option.value);
    }

    function selectAllPriorities() {
        draftPriorities.value = priorityOptions.map((option) => option.value);
    }

    function buildListQuery(page: number) {
        return {
            status: appliedStatuses.value,
            priority: appliedPriorities.value,
            search: appliedSearchTerm.value || undefined,
            environment: appliedEnvironmentTerm.value || undefined,
            sort_by: appliedSortBy.value,
            page,
        };
    }

    return {
        activeFilterCount,
        appliedEnvironmentTerm,
        appliedPriorities,
        appliedSearchTerm,
        appliedSortBy,
        appliedStatuses,
        applyFilters,
        allPriorities,
        buildListQuery,
        defaultSortBy,
        draftEnvironmentTerm,
        draftPriorities,
        draftSearchTerm,
        draftSortBy,
        draftStatuses,
        filtersOpen,
        priorityOptions,
        resetFilters,
        selectAllPriorities,
        selectAllStatuses,
        statusOptions,
        syncAppliedToDraft,
        sortByOptions,
    };
}
