import { computed, ref, watch } from 'vue';
import {
    DEFAULT_SORT_BY,
    getDefaultStatuses,
    getPriorityOptions,
    getSortByOptions,
    getStatusOptions,
    normalizeStringList,
} from './presentation';
import type { TaskIndexFilters } from './types';

type UseTaskFilterStateOptions = {
    includeClosed?: boolean;
    completedStatuses?: string[];
    filters?: TaskIndexFilters;
};

export function useTaskFilterState(options: UseTaskFilterStateOptions = {}) {
    const statusOptions = getStatusOptions({ includeClosed: options.includeClosed ?? false });
    const priorityOptions = getPriorityOptions();
    const sortByOptions = getSortByOptions();
    const defaultSortBy = DEFAULT_SORT_BY;
    const allowedSortBy = new Set(sortByOptions.map((option) => option.value));
    const defaultStatuses = getDefaultStatuses(statusOptions, options.completedStatuses ?? ['completed']);
    const allPriorities = priorityOptions.map((option) => option.value);
    const providedFilters = options.filters ?? {};

    const providedStatuses = normalizeStringList(providedFilters.status);
    const providedPriorities = normalizeStringList(providedFilters.priority);
    const providedSearchTerm = typeof providedFilters.search === 'string' ? providedFilters.search : '';
    const providedEnvironmentTerm = typeof providedFilters.environment === 'string' ? providedFilters.environment : '';
    const providedSortBy =
        typeof providedFilters.sort_by === 'string' && allowedSortBy.has(providedFilters.sort_by)
            ? providedFilters.sort_by
            : defaultSortBy;

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
        syncAppliedToDraft();
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

    function applyDraftToApplied() {
        appliedStatuses.value = [...draftStatuses.value];
        appliedPriorities.value = [...draftPriorities.value];
        appliedSearchTerm.value = draftSearchTerm.value;
        appliedEnvironmentTerm.value = draftEnvironmentTerm.value;
        appliedSortBy.value = draftSortBy.value;
    }

    function resetDraftToDefaults() {
        draftStatuses.value = [...defaultStatuses];
        draftPriorities.value = [...allPriorities];
        draftSearchTerm.value = '';
        draftEnvironmentTerm.value = '';
        draftSortBy.value = defaultSortBy;
    }

    function resetAppliedToDefaults() {
        appliedStatuses.value = [...defaultStatuses];
        appliedPriorities.value = [...allPriorities];
        appliedSearchTerm.value = '';
        appliedEnvironmentTerm.value = '';
        appliedSortBy.value = defaultSortBy;
    }

    function resetFilters() {
        resetDraftToDefaults();
        applyDraftToApplied();
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

    function selectAllStatuses() {
        draftStatuses.value = statusOptions.map((option) => option.value);
    }

    function selectAllPriorities() {
        draftPriorities.value = [...allPriorities];
    }

    return {
        activeFilterCount,
        allPriorities,
        appliedEnvironmentTerm,
        appliedPriorities,
        appliedSearchTerm,
        appliedSortBy,
        appliedStatuses,
        applyDraftToApplied,
        buildListQuery,
        defaultSortBy,
        defaultStatuses,
        draftEnvironmentTerm,
        draftPriorities,
        draftSearchTerm,
        draftSortBy,
        draftStatuses,
        filtersOpen,
        priorityOptions,
        resetAppliedToDefaults,
        resetDraftToDefaults,
        resetFilters,
        selectAllPriorities,
        selectAllStatuses,
        sortByOptions,
        statusOptions,
        syncAppliedToDraft,
    };
}
