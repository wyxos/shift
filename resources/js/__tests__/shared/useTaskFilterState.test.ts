import { getRequirementStatusOptions } from '@shared/tasks/presentation';
import { useTaskFilterState } from '@shared/tasks/useTaskFilterState';
import { describe, expect, it } from 'vitest';

describe('task filter defaults', () => {
    it('offers reset for draft changes and keeps it available until applied filters are reset', () => {
        const filters = useTaskFilterState({ filters: { organisation_id: 7 } });

        expect(filters.canResetFilters.value).toBe(false);
        filters.draftSearchTerm.value = '   ';
        expect(filters.canResetFilters.value).toBe(false);
        filters.draftSearchTerm.value = 'export';
        expect(filters.canResetFilters.value).toBe(true);
        expect(filters.activeFilterCount.value).toBe(0);
        filters.applyDraftToApplied();
        filters.resetDraftToDefaults();
        expect(filters.canResetFilters.value).toBe(true);
        filters.resetFilters();
        expect(filters.canResetFilters.value).toBe(false);
        expect(filters.buildListQuery(1).organisation_id).toBe('7');
    });

    it('uses requirement status defaults for draft reset visibility', () => {
        const filters = useTaskFilterState({ statusOptions: getRequirementStatusOptions(), completedStatuses: [] });

        expect(filters.canResetFilters.value).toBe(false);
        filters.draftStatuses.value = ['submitted'];
        expect(filters.canResetFilters.value).toBe(true);
        filters.selectAllStatuses();
        expect(filters.canResetFilters.value).toBe(false);
    });

    it('counts changes from defaults regardless of selection order', () => {
        const filters = useTaskFilterState({ includeClosed: true, completedStatuses: ['completed', 'closed'] });

        expect(filters.activeFilterCount.value).toBe(0);
        filters.appliedStatuses.value.reverse();
        filters.appliedPriorities.value.reverse();
        expect(filters.activeFilterCount.value).toBe(0);

        filters.selectAllStatuses();
        filters.applyDraftToApplied();
        expect(filters.activeFilterCount.value).toBe(1);

        filters.resetFilters();
        expect(filters.activeFilterCount.value).toBe(0);
        filters.appliedStatuses.value = ['completed'];
        filters.appliedSearchTerm.value = 'export';
        expect(filters.activeFilterCount.value).toBe(2);
        filters.resetFilters();
        expect(filters.activeFilterCount.value).toBe(0);
    });
});
