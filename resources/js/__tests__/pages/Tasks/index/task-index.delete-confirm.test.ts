import TaskIndexListCard from '@/components/tasks/index/TaskIndexListCard.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';

vi.mock('@/components/tasks/TaskCreateSheet.vue', () => ({
    default: {
        render() {
            return h('div', { class: 'task-create-sheet-stub' });
        },
    },
}));

vi.mock('@shared/components/tasks/TaskListOverviewPanel.vue', () => ({
    default: {
        props: ['deleteTask'],
        render() {
            return h(
                'button',
                {
                    type: 'button',
                    'data-testid': 'task-delete-1',
                    onClick: () => this.deleteTask(1),
                },
                'Delete task',
            );
        },
    },
}));

describe('TaskIndexListCard delete confirmation', () => {
    it('deletes the pending task when the confirmation action is pressed', async () => {
        const deleteTask = vi.fn();

        mount(TaskIndexListCard, {
            props: {
                filters: {
                    filtersOpen: false,
                    activeFilterCount: 0,
                    draftStatuses: ['pending'],
                    draftPriorities: ['medium'],
                    draftSearchTerm: '',
                    draftEnvironmentTerm: '',
                    draftProjectId: '',
                    draftSortBy: 'updated_at',
                    projectOptions: [],
                    statusOptions: [],
                    priorityOptions: [],
                    sortByOptions: [],
                    setFiltersOpen: vi.fn(),
                    setDraftStatuses: vi.fn(),
                    setDraftPriorities: vi.fn(),
                    setDraftSearchTerm: vi.fn(),
                    setDraftEnvironmentTerm: vi.fn(),
                    setDraftProjectId: vi.fn(),
                    setDraftSortBy: vi.fn(),
                    resetFilters: vi.fn(),
                    applyFilters: vi.fn(),
                    selectAllStatuses: vi.fn(),
                    selectAllPriorities: vi.fn(),
                },
                projects: [],
                state: {
                    taskRows: [{ id: 1, title: 'Delete me', status: 'pending', priority: 'medium', can_delete: true }],
                    tasksPage: {
                        total: 1,
                        current_page: 1,
                        last_page: 1,
                        from: 1,
                        to: 1,
                    },
                    loading: false,
                    error: null,
                    deleteLoading: null,
                    requirementBatchFinalizeLoading: null,
                    highlightedTaskId: null,
                    openEdit: vi.fn(),
                    deleteTask,
                    finalizeRequirementBatch: vi.fn(),
                    goToPage: vi.fn(),
                    handleTaskCreated: vi.fn(),
                },
                surface: 'tasks',
            },
            attachTo: document.body,
        });

        document.querySelector<HTMLButtonElement>('[data-testid="task-delete-1"]')?.click();
        await nextTick();

        const confirm = Array.from(document.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button')).find(
            (button) => button.textContent?.trim() === 'Delete',
        );
        expect(confirm).toBeDefined();

        confirm?.click();
        await nextTick();

        expect(deleteTask).toHaveBeenCalledWith(1);
    });
});
