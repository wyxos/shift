import Index from '@/pages/Tasks/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, makeTasksPage, router } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('uses distinct status badge colors for each status', () => {
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

        expect(wrapper.get('[data-testid="task-status-badge-1"]').classes()).toContain('bg-amber-100');
        expect(wrapper.get('[data-testid="task-status-badge-2"]').classes()).toContain('bg-sky-100');
        expect(wrapper.get('[data-testid="task-status-badge-3"]').classes()).toContain('bg-indigo-100');
        expect(wrapper.get('[data-testid="task-status-badge-4"]').classes()).toContain('bg-emerald-100');

        wrapper.unmount();
    });

    it('uses distinct priority badge colors for each priority', () => {
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

        expect(wrapper.get('[data-testid="task-priority-badge-1"]').classes()).toContain('bg-cyan-100');
        expect(wrapper.get('[data-testid="task-priority-badge-2"]').classes()).toContain('bg-fuchsia-100');
        expect(wrapper.get('[data-testid="task-priority-badge-3"]').classes()).toContain('bg-rose-100');

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
        expect(wrapper.get('[data-testid="task-environment-badge-2"]').text()).toContain('Unknown');

        wrapper.unmount();
    });

    it('distinguishes app error rows and keeps row badges under the title', async () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
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

        expect(wrapper.get('[data-testid="task-type-badge-1"]').text()).toContain('Task');
        expect(wrapper.get('[data-testid="task-type-badge-2"]').text()).toBe('error');
        expect(wrapper.get('[data-testid="task-project-badge-2"]').text()).toBe('Pack QA');

        const titleCell = wrapper.get('[data-testid="task-title-cell-2"]');
        const titleButton = wrapper.get('[data-testid="task-title-2"]');
        const badges = wrapper.get('[data-testid="task-title-badges-2"]');

        expect(titleCell.classes()).toContain('flex-col');
        expect(titleButton.element.compareDocumentPosition(badges.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

        await wrapper.get('[data-testid="filters-trigger"]').trigger('click');
        await wrapper.get('[data-testid="filter-type-app_errors"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/tasks',
            expect.objectContaining({
                type: 'app_errors',
                page: 1,
            }),
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );

        wrapper.unmount();
    });
});
