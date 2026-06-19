import { computed, ref, watch } from 'vue';
import type { TaskFilterOption } from './presentation';
import {
    DEFAULT_SORT_BY,
    DEFAULT_TASK_TYPE_FILTER,
    getDefaultStatuses,
    getPriorityOptions,
    getSortByOptions,
    getStatusOptions,
    getTaskTypeOptions,
    normalizeStringList,
} from './presentation';
import type { TaskIndexFilters } from './types';

type UseTaskFilterStateOptions = {
    includeTypeFilter?: boolean;
    includeClosed?: boolean;
    completedStatuses?: string[];
    filters?: TaskIndexFilters;
    statusOptions?: TaskFilterOption[];
};

export function useTaskFilterState(options: UseTaskFilterStateOptions = {}) {
    const statusOptions = options.statusOptions ?? getStatusOptions({ includeClosed: options.includeClosed ?? false });
    const priorityOptions = getPriorityOptions();
    const sortByOptions = getSortByOptions();
    const typeOptions = getTaskTypeOptions();
    const defaultSortBy = DEFAULT_SORT_BY;
    const includeTypeFilter = options.includeTypeFilter ?? false;
    const allowedSortBy = new Set(sortByOptions.map((option) => option.value));
    const allowedTypes = new Set(typeOptions.map((option) => option.value));
    const defaultStatuses = getDefaultStatuses(statusOptions, options.completedStatuses ?? ['completed']);
    const allPriorities = priorityOptions.map((option) => option.value);
    const providedFilters = options.filters ?? {};

    const providedStatuses = normalizeStringList(providedFilters.status);
    const providedPriorities = normalizeStringList(providedFilters.priority);
    const providedSearchTerm = typeof providedFilters.search === 'string' ? providedFilters.search : '';
    const providedEnvironmentTerm = typeof providedFilters.environment === 'string' ? providedFilters.environment : '';
    const providedOrganisationId =
        typeof providedFilters.organisation_id === 'number' || typeof providedFilters.organisation_id === 'string'
            ? String(providedFilters.organisation_id)
            : '';
    const providedProjectId =
        typeof providedFilters.project_id === 'number' || typeof providedFilters.project_id === 'string' ? String(providedFilters.project_id) : '';
    const providedType =
        includeTypeFilter && typeof providedFilters.type === 'string' && allowedTypes.has(providedFilters.type)
            ? providedFilters.type
            : DEFAULT_TASK_TYPE_FILTER;
    const providedSortBy =
        typeof providedFilters.sort_by === 'string' && allowedSortBy.has(providedFilters.sort_by) ? providedFilters.sort_by : defaultSortBy;

    const filtersOpen = ref(false);
    const appliedStatuses = ref<string[]>(providedStatuses.length ? providedStatuses : [...defaultStatuses]);
    const appliedPriorities = ref<string[]>(providedPriorities.length ? providedPriorities : [...allPriorities]);
    const appliedSearchTerm = ref(providedSearchTerm);
    const appliedEnvironmentTerm = ref(providedEnvironmentTerm);
    const appliedProjectId = ref(providedProjectId);
    const appliedType = ref(providedType);
    const appliedSortBy = ref(providedSortBy);

    const draftStatuses = ref<string[]>([...appliedStatuses.value]);
    const draftPriorities = ref<string[]>([...appliedPriorities.value]);
    const draftSearchTerm = ref(appliedSearchTerm.value);
    const draftEnvironmentTerm = ref(appliedEnvironmentTerm.value);
    const draftProjectId = ref(appliedProjectId.value);
    const draftType = ref(appliedType.value);
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
        if (appliedProjectId.value) count += 1;
        if (includeTypeFilter && appliedType.value !== DEFAULT_TASK_TYPE_FILTER) count += 1;
        if (appliedSortBy.value !== defaultSortBy) count += 1;
        return count;
    });

    function syncAppliedToDraft() {
        draftStatuses.value = [...appliedStatuses.value];
        draftPriorities.value = [...appliedPriorities.value];
        draftSearchTerm.value = appliedSearchTerm.value;
        draftEnvironmentTerm.value = appliedEnvironmentTerm.value;
        draftProjectId.value = appliedProjectId.value;
        draftType.value = appliedType.value;
        draftSortBy.value = appliedSortBy.value;
    }

    function applyDraftToApplied() {
        appliedStatuses.value = [...draftStatuses.value];
        appliedPriorities.value = [...draftPriorities.value];
        appliedSearchTerm.value = draftSearchTerm.value;
        appliedEnvironmentTerm.value = draftEnvironmentTerm.value;
        appliedProjectId.value = draftProjectId.value;
        appliedType.value = allowedTypes.has(draftType.value) ? draftType.value : DEFAULT_TASK_TYPE_FILTER;
        appliedSortBy.value = draftSortBy.value;
    }

    function resetDraftToDefaults() {
        draftStatuses.value = [...defaultStatuses];
        draftPriorities.value = [...allPriorities];
        draftSearchTerm.value = '';
        draftEnvironmentTerm.value = '';
        draftProjectId.value = '';
        draftType.value = DEFAULT_TASK_TYPE_FILTER;
        draftSortBy.value = defaultSortBy;
    }

    function resetAppliedToDefaults() {
        appliedStatuses.value = [...defaultStatuses];
        appliedPriorities.value = [...allPriorities];
        appliedSearchTerm.value = '';
        appliedEnvironmentTerm.value = '';
        appliedProjectId.value = '';
        appliedType.value = DEFAULT_TASK_TYPE_FILTER;
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
            organisation_id: providedOrganisationId || undefined,
            project_id: appliedProjectId.value || undefined,
            type: includeTypeFilter && appliedType.value !== DEFAULT_TASK_TYPE_FILTER ? appliedType.value : undefined,
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
        appliedProjectId,
        appliedSearchTerm,
        appliedSortBy,
        appliedStatuses,
        appliedType,
        applyDraftToApplied,
        buildListQuery,
        defaultSortBy,
        defaultStatuses,
        draftEnvironmentTerm,
        draftPriorities,
        draftProjectId,
        draftSearchTerm,
        draftSortBy,
        draftStatuses,
        draftType,
        filtersOpen,
        includeTypeFilter,
        priorityOptions,
        resetAppliedToDefaults,
        resetDraftToDefaults,
        resetFilters,
        selectAllPriorities,
        selectAllStatuses,
        sortByOptions,
        statusOptions,
        syncAppliedToDraft,
        typeOptions,
    };
}
