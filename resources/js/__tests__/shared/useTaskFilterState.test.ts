import { useTaskFilterState } from '@shared/tasks/useTaskFilterState';
import { describe, expect, it } from 'vitest';

describe('task filter defaults', () => {
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
