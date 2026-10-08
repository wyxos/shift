import Index from '@/pages/Tasks/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, makeTasksPage, router } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('shows a quiet shared surface and distinct icons for task statuses', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'A', status: 'pending', priority: 'low' },
                    { id: 2, title: 'B', status: 'in-progress', priority: 'medium' },
                    { id: 3, title: 'C', status: 'awaiting-feedback', priority: 'high' },
                    { id: 4, title: 'D', status: 'completed', priority: 'low' },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback', 'completed'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        const badges = [1, 2, 3, 4].map((id) => wrapper.get(`[data-testid="task-status-badge-${id}"]`));
        expect(badges.every((badge) => badge.classes().includes('state'))).toBe(true);
        expect(new Set(badges.map((badge) => badge.get('svg').html())).size).toBe(4);
        expect(badges.map((badge) => badge.text())).toEqual(['Pending', 'In Progress', 'Awaiting Feedback', 'Completed']);

        wrapper.unmount();
    });

    it('shows a quiet shared surface and distinct icons for priorities', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'A', status: 'pending', priority: 'low' },
                    { id: 2, title: 'B', status: 'in-progress', priority: 'medium' },
                    { id: 3, title: 'C', status: 'awaiting-feedback', priority: 'high' },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        const badges = [1, 2, 3].map((id) => wrapper.get(`[data-testid="task-priority-badge-${id}"]`));
        expect(badges.every((badge) => badge.classes().includes('state'))).toBe(true);
        expect(new Set(badges.map((badge) => badge.get('svg').html())).size).toBe(3);
        expect(badges.map((badge) => badge.text())).toEqual(['Low', 'Medium', 'High']);

        wrapper.unmount();
    });

    it('shows environment badges in list rows', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'A', status: 'pending', priority: 'low', environment: 'staging' },
                    { id: 2, title: 'B', status: 'in-progress', priority: 'medium', environment: null },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.get('[data-testid="task-environment-badge-1"]').text()).toContain('Staging');
        expect(wrapper.get('[data-testid="task-environment-badge-2"]').text()).toContain('N/A');

        wrapper.unmount();
    });

    it('removes redundant type badges and keeps row metadata under the title', async () => {
        axiosGetMock.mockReset();
        (router.get as any).mockClear();

        const wrapper = mount(Index, {
            props: {
                surface: 'app-errors',
                tasks: makeTasksPage([
                    { id: 1, title: 'Investigate checkout', status: 'pending', priority: 'medium', type: 'task', type_label: 'Task' },
                    {
                        id: 2,
                        title: 'Checkout failed',
                        project: { id: 5, name: 'Requirement Pack QA' },
                        status: 'pending',
                        priority: 'high',
                        type: 'app_error',
                        type_label: 'App error',
                    },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                    type: 'all',
                },
            },
        });

        expect(wrapper.find('[data-testid="task-type-badge-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="task-type-badge-2"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="task-project-badge-2"]').text()).toBe('Pack QA');

        const titleCell = wrapper.get('[data-testid="task-title-cell-2"]');
        const titleButton = wrapper.get('[data-testid="task-title-2"]');
        const badges = wrapper.get('[data-testid="task-title-badges-2"]');

        expect(titleCell.classes()).toContain('flex-col');
        expect(titleButton.element.compareDocumentPosition(badges.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(badges.find('[data-testid="task-status-badge-2"]').exists()).toBe(true);
        expect(badges.find('[data-testid="task-priority-badge-2"]').exists()).toBe(true);
        expect(badges.find('[data-testid="task-environment-badge-2"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('App errors');

        await wrapper.get('[data-testid="filters-trigger"]').trigger('click');
        expect(wrapper.find('[data-testid="filter-type-app_errors"]').exists()).toBe(false);
        await wrapper.get('[data-testid="filter-search"]').setValue('Checkout');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/error-reports',
            expect.objectContaining({
                search: 'Checkout',
                page: 1,
            }),
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
        expect((router.get as any).mock.calls.at(-1)?.[1]).not.toHaveProperty('type');

        wrapper.unmount();
    });
});
