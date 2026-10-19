import TaskListOverviewPanel from '@shared/components/tasks/TaskListOverviewPanel.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const stubs = {
    TaskListFiltersSheet: { template: '<div />' },
    ResponsiveRecordList: { template: '<div><slot name="desktop" /><slot name="compact" /></div>' },
    TaskListCompact: { template: '<div />' },
    Table: { template: '<div><slot /></div>' },
    TableHeader: { template: '<div><slot /></div>' },
    TableBody: { template: '<div><slot /></div>' },
    TableRow: { template: '<div><slot /></div>' },
    TableHead: { template: '<div><slot /></div>' },
    TableEmpty: { template: '<div><slot /></div>' },
};

function panel(overrides: Record<string, unknown> = {}) {
    const searchTasks = vi.fn();
    const resetFilters = vi.fn();
    const wrapper = mount(TaskListOverviewPanel, {
        props: {
            tasks: [],
            totalTasks: 0,
            currentPage: 1,
            lastPage: 1,
            from: 0,
            to: 0,
            highlightedTaskId: null,
            filtersOpen: false,
            activeFilterCount: 2,
            searchTerm: 'checkout',
            searchTasks,
            draftStatuses: [],
            draftPriorities: [],
            draftSearchTerm: 'checkout',
            draftEnvironmentTerm: '',
            draftSortBy: 'updated_at',
            statusOptions: [],
            priorityOptions: [],
            sortByOptions: [],
            getTaskEnvironmentLabel: vi.fn(),
            setFiltersOpen: vi.fn(),
            setDraftStatuses: vi.fn(),
            setDraftPriorities: vi.fn(),
            setDraftSearchTerm: vi.fn(),
            setDraftEnvironmentTerm: vi.fn(),
            setDraftSortBy: vi.fn(),
            resetFilters,
            applyFilters: vi.fn(),
            selectAllStatuses: vi.fn(),
            selectAllPriorities: vi.fn(),
            openEdit: vi.fn(),
            deleteTask: vi.fn(),
            goToPage: vi.fn(),
            ...overrides,
        },
        global: { stubs },
    });

    return { wrapper, searchTasks, resetFilters };
}

describe('TaskListOverviewPanel title search', () => {
    it('hides reset and the default description when filters are unchanged', () => {
        const { wrapper } = panel({ activeFilterCount: 0, searchTerm: '' });

        expect(wrapper.find('[data-testid="task-list-reset-filters"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Default view hides completed and closed tasks.');
        expect(wrapper.find('h1 + p').exists()).toBe(false);
    });

    it('submits only on Enter or Search and offers empty-state recovery', async () => {
        const { wrapper, searchTasks, resetFilters } = panel();
        const input = wrapper.get('[data-testid="task-list-search"]');

        await input.setValue('new title');
        expect(searchTasks).not.toHaveBeenCalled();
        await wrapper.get('form[role="search"]').trigger('submit');
        expect(searchTasks).toHaveBeenLastCalledWith('new title');

        await wrapper.get('[data-testid="task-list-clear-search"]').trigger('click');
        expect(searchTasks).toHaveBeenLastCalledWith('');
        await wrapper.get('[data-testid="task-list-reset-filters"]').trigger('click');
        expect(resetFilters).toHaveBeenCalledOnce();
    });

    it('hides recovery while loading or showing an error and keeps legacy consumers compatible', () => {
        expect(panel({ loading: true }).wrapper.find('[data-testid="task-list-reset-filters"]').exists()).toBe(false);
        expect(panel({ error: 'Could not load tasks' }).wrapper.find('[data-testid="task-list-reset-filters"]').exists()).toBe(false);
        expect(panel({ searchTasks: undefined }).wrapper.find('[data-testid="task-list-search"]').exists()).toBe(false);
    });
});
