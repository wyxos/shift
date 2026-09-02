import TaskListFiltersSheet from '@shared/components/tasks/TaskListFiltersSheet.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

function props(overrides: Record<string, unknown> = {}) {
    return {
        open: true,
        activeFilterCount: 0,
        draftStatuses: ['pending'],
        draftPriorities: ['high'],
        draftSearchTerm: '',
        draftEnvironmentTerm: '',
        draftSortBy: 'updated_at',
        statusOptions: [{ value: 'pending', label: 'Pending' }],
        priorityOptions: [{ value: 'high', label: 'High' }],
        sortByOptions: [{ value: 'updated_at', label: 'Updated' }],
        setOpen: vi.fn(),
        setDraftStatuses: vi.fn(),
        setDraftPriorities: vi.fn(),
        setDraftSearchTerm: vi.fn(),
        setDraftEnvironmentTerm: vi.fn(),
        setDraftSortBy: vi.fn(),
        resetFilters: vi.fn(),
        applyFilters: vi.fn(),
        selectAllStatuses: vi.fn(),
        selectAllPriorities: vi.fn(),
        ...overrides,
    };
}

const sheetStubs = {
    Sheet: { template: '<div><slot /></div>' },
    SheetTrigger: { template: '<div><slot /></div>' },
    SheetContent: { template: '<div><slot /></div>' },
    SheetHeader: { template: '<div><slot /></div>' },
    SheetTitle: { template: '<div><slot /></div>' },
    SheetDescription: { template: '<div><slot /></div>' },
    SheetFooter: { template: '<div><slot /></div>' },
};

describe('TaskListFiltersSheet', () => {
    it('does not render redundant helper copy beneath the filter title', () => {
        const wrapper = mount(TaskListFiltersSheet, {
            props: props(),
            global: { stubs: sheetStubs },
        });

        expect(wrapper.text()).not.toContain('Refine your task list in real time.');
    });

    it('renders registered environment buttons and maps All back to the empty filter', async () => {
        const setDraftEnvironmentTerm = vi.fn();
        const wrapper = mount(TaskListFiltersSheet, {
            props: props({
                environmentOptions: [
                    { value: 'production', label: 'Production' },
                    { value: 'staging', label: 'Staging' },
                ],
                setDraftEnvironmentTerm,
            }),
            global: { stubs: sheetStubs },
        });

        await wrapper.get('[data-testid="filter-environment-staging"]').trigger('click');
        expect(setDraftEnvironmentTerm).toHaveBeenLastCalledWith('staging');

        await wrapper.get('[data-testid="filter-environment-all"]').trigger('click');
        expect(setDraftEnvironmentTerm).toHaveBeenLastCalledWith('');
        expect(wrapper.find('input[data-testid="filter-environment"]').exists()).toBe(false);
    });

    it('keeps the free-text field as the legacy fallback when environment metadata is absent', () => {
        const wrapper = mount(TaskListFiltersSheet, {
            props: props(),
            global: { stubs: sheetStubs },
        });

        expect(wrapper.find('input[data-testid="filter-environment"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-environment-all"]').exists()).toBe(false);
    });

    it('renders status and priority filters as multi-select buttons', async () => {
        const setDraftStatuses = vi.fn();
        const setDraftPriorities = vi.fn();
        const wrapper = mount(TaskListFiltersSheet, {
            props: props({
                statusOptions: [
                    { value: 'pending', label: 'Pending' },
                    { value: 'completed', label: 'Completed' },
                ],
                priorityOptions: [
                    { value: 'high', label: 'High' },
                    { value: 'low', label: 'Low' },
                ],
                setDraftStatuses,
                setDraftPriorities,
            }),
            global: { stubs: sheetStubs },
        });

        expect(wrapper.find('input[data-testid^="status-"]').exists()).toBe(false);
        expect(wrapper.find('input[data-testid^="priority-"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="status-pending"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('[data-testid="priority-high"]').attributes('aria-pressed')).toBe('true');

        await wrapper.get('[data-testid="status-completed"]').trigger('click');
        await wrapper.get('[data-testid="priority-low"]').trigger('click');

        expect(setDraftStatuses).toHaveBeenLastCalledWith(['pending', 'completed']);
        expect(setDraftPriorities).toHaveBeenLastCalledWith(['high', 'low']);
    });
});
