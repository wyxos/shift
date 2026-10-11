import { useTaskIndexFilters } from '@/composables/useTaskIndexFilters';
import { router, usePage } from '@inertiajs/vue3';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
    usePage: vi.fn(() => ({ url: '/tasks' })),
}));

describe('useTaskIndexFilters title search', () => {
    it('searches with applied filters while leaving unsaved sheet choices untouched', () => {
        const filters = useTaskIndexFilters({ filters: { search: 'original', priority: ['high'], environment: 'production' } });
        filters.filtersOpen.value = true;
        filters.draftStatuses.value = ['completed'];
        filters.draftPriorities.value = ['low'];

        filters.searchTasks('  checkout  ');

        expect(filters.appliedSearchTerm.value).toBe('checkout');
        expect(filters.draftStatuses.value).toEqual(['completed']);
        expect(filters.draftPriorities.value).toEqual(['low']);
        expect(router.get).toHaveBeenLastCalledWith(
            '/tasks',
            expect.objectContaining({ search: 'checkout', priority: ['high'], environment: 'production', page: 1 }),
            expect.objectContaining({ preserveState: true, replace: true }),
        );

        filters.searchTasks('');
        expect(router.get).toHaveBeenLastCalledWith('/tasks', expect.objectContaining({ search: undefined, priority: ['high'] }), expect.anything());
        expect(usePage).toHaveBeenCalled();
    });

    it('resets to the default task statuses, excluding completed and closed', () => {
        const filters = useTaskIndexFilters({ filters: { status: ['completed'], search: 'checkout' } });

        filters.resetFilters();

        expect(filters.appliedSearchTerm.value).toBe('');
        expect(filters.appliedStatuses.value).not.toContain('completed');
        expect(filters.appliedStatuses.value).not.toContain('closed');
        expect(router.get).toHaveBeenLastCalledWith(
            '/tasks',
            expect.objectContaining({ status: filters.appliedStatuses.value, search: undefined, page: 1 }),
            expect.anything(),
        );
    });
});
