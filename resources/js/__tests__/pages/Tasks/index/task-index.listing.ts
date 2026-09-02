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
                        environment: 'staging',
                        created_at: '2024-01-01T09:15:00',
                        updated_at: '2024-01-02T11:45:00',
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
        const compactRows = wrapper.findAll('[data-testid^="task-compact-row-"]');
        expect(rows).toHaveLength(2);
        expect(compactRows).toHaveLength(2);
        expect(wrapper.text()).toContain('Auth issue');
        expect(wrapper.text()).toContain('UI polish');
        expect(wrapper.find('[data-testid="task-project-badge-1"]').text()).toContain('Portal');
        expect(wrapper.find('[data-testid="task-project-badge-2"]').text()).toContain('Console');
        expect(wrapper.get('[data-testid="task-status-badge-1"]').text()).toContain('Pending');
        expect(wrapper.get('[data-testid="task-priority-badge-1"]').text()).toContain('High');
        expect(wrapper.get('[data-testid="task-environment-badge-1"]').text()).toContain('Staging');
        expect(wrapper.get('[data-testid="task-created-at-1"]').text()).toContain('1 Jan 2024');
        expect(wrapper.get('[data-testid="task-created-at-1"]').text()).toContain('09:15');
        expect(wrapper.get('[data-testid="task-updated-at-1"]').text()).toContain('2 Jan 2024');
        expect(wrapper.get('[data-testid="task-updated-at-1"]').text()).toContain('11:45');

        for (const row of rows) {
            expect(row.find('button[data-testid^="task-open-"]').exists()).toBe(true);
            expect(row.find('button[data-testid^="task-delete-"]').exists()).toBe(true);
        }

        expect(compactRows[0].text()).toContain('Pending');
        expect(compactRows[0].text()).toContain('High');
        expect(compactRows[0].text()).toContain('Created');
        expect(compactRows[0].text()).toContain('1 Jan 2024');
        expect(compactRows[0].text()).toContain('Updated');
        expect(compactRows[0].text()).toContain('2 Jan 2024');
        expect(compactRows[0].get('button[aria-label="Open task details for Auth issue"]').classes()).toContain('[overflow-wrap:anywhere]');
        expect(compactRows[0].find('[data-slot="responsive-record-item-actions"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="task-compact-open-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="task-compact-delete-1"]').exists()).toBe(true);

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
                projects: [
                    {
                        id: 10,
                        name: 'Portal',
                        environments: [{ key: 'production', label: 'Production', url: 'https://portal.example.com' }],
                    },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                    project_id: 10,
                },
            },
        });

        expect(wrapper.find('[data-testid="filter-search"]').exists()).toBe(true);
        expect(wrapper.findAll('button[data-testid^="status-"]').length).toBeGreaterThanOrEqual(4);
        expect(wrapper.findAll('button[data-testid^="priority-"]').length).toBeGreaterThanOrEqual(3);
        expect(wrapper.find('[data-testid="filter-project"]').exists()).toBe(true);
        expect(wrapper.find('input[data-testid="filter-environment"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="filter-environment-production"]').exists()).toBe(true);

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

    it('keeps app error filters on the dedicated route when paginating', async () => {
        axiosGetMock.mockReset();
        (router.get as any).mockClear();

        const wrapper = mount(Index, {
            props: {
                surface: 'app-errors',
                tasks: makeTasksPage([{ id: 1, title: 'Checkout failed', status: 'pending', priority: 'high', type: 'app_error' }], {
                    current_page: 1,
                    last_page: 2,
                    total: 11,
                    from: 1,
                    to: 10,
                }),
                projects: [{ id: 10, name: 'Portal', environments: [] }],
                filters: {
                    status: ['pending'],
                    priority: ['high'],
                    search: 'Checkout',
                    environment: 'staging',
                    project_id: 10,
                },
            },
        });

        const nextButton = wrapper.findAll('button').find((button) => button.text() === 'Next');
        expect(nextButton).toBeDefined();
        await nextButton!.trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/error-reports',
            expect.objectContaining({
                status: ['pending'],
                priority: ['high'],
                search: 'Checkout',
                environment: 'staging',
                project_id: '10',
                page: 2,
            }),
            expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }),
        );
        expect((router.get as any).mock.calls.at(-1)?.[1]).not.toHaveProperty('type');

        wrapper.unmount();
    });
});
