import Index from '@/pages/Tasks/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, makeTasksPage, router } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('renders header + task rows', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    {
                        id: 1,
                        title: 'Auth issue',
                        status: 'pending',
                        priority: 'high',
                        can_delete: true,
                        project: { id: 10, name: 'Portal' },
                    },
                    {
                        id: 2,
                        title: 'UI polish',
                        status: 'in-progress',
                        priority: 'medium',
                        can_delete: true,
                        project: { id: 11, name: 'Console' },
                    },
                ]),
                projects: [
                    { id: 10, name: 'Portal', environments: [] },
                    { id: 11, name: 'Console', environments: [] },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.find('.app-layout').exists()).toBe(true);
        expect(wrapper.text()).toContain('Tasks');
        expect(wrapper.find('[data-testid="filters-trigger"]').exists()).toBe(true);

        const rows = wrapper.findAll('[data-testid="task-row"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.text()).toContain('Auth issue');
        expect(wrapper.text()).toContain('UI polish');
        expect(wrapper.find('[data-testid="task-project-badge-1"]').text()).toContain('Portal');
        expect(wrapper.find('[data-testid="task-project-badge-2"]').text()).toContain('Console');

        for (const row of rows) {
            expect(row.find('button[title="Open details"]').exists()).toBe(true);
            expect(row.find('button[title="Delete"]').exists()).toBe(true);
        }

        wrapper.unmount();
    });

    it('hides destructive task row actions without the delete capability', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'Developer-visible task', status: 'pending', priority: 'high', can_delete: false },
                    { id: 2, title: 'Maintainer task', status: 'in-progress', priority: 'medium', can_delete: true },
                ]),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.find('[data-testid="task-open-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="task-delete-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="task-delete-2"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('refreshes rows when preserved Inertia state receives a new task page', async () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Page one task', status: 'pending', priority: 'high' }], {
                    current_page: 1,
                    last_page: 2,
                    total: 2,
                    from: 1,
                    to: 1,
                }),
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.text()).toContain('Page one task');

        await wrapper.setProps({
            tasks: makeTasksPage([{ id: 2, title: 'Page two task', status: 'in-progress', priority: 'medium' }], {
                current_page: 2,
                last_page: 2,
                total: 2,
                from: 2,
                to: 2,
            }),
        });

        expect(wrapper.text()).not.toContain('Page one task');
        expect(wrapper.text()).toContain('Page two task');
        expect(wrapper.text()).toContain('Page 2 of 2');
        expect(wrapper.text()).toContain('Showing 2 to 2 of 2 tasks');

        wrapper.unmount();
    });

    it('has filter controls', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                projects: [{ id: 10, name: 'Portal', environments: [] }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        expect(wrapper.find('[data-testid="filter-search"]').exists()).toBe(true);
        expect(wrapper.findAll('input[data-testid^="status-"]').length).toBeGreaterThanOrEqual(4);
        expect(wrapper.findAll('input[data-testid^="priority-"]').length).toBeGreaterThanOrEqual(3);
        expect(wrapper.find('[data-testid="filter-project"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-environment"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('applies project filters to the task list query', async () => {
        axiosGetMock.mockReset();
        (router.get as any).mockClear();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                projects: [
                    { id: 10, name: 'Portal', environments: [] },
                    { id: 11, name: 'Console', environments: [] },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="filter-project"]').setValue('11');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/tasks',
            expect.objectContaining({
                page: 1,
                project_id: '11',
            }),
            expect.objectContaining({ preserveState: true, replace: true }),
        );

        wrapper.unmount();
    });
});
