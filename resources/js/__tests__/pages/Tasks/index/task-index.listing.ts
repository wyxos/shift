import Index from '@/pages/Tasks/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, makeTasksPage } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('renders header + task rows', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([
                    { id: 1, title: 'Auth issue', status: 'pending', priority: 'high' },
                    { id: 2, title: 'UI polish', status: 'in-progress', priority: 'medium' },
                ]),
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

        for (const row of rows) {
            expect(row.find('button[title="Edit"]').exists()).toBe(true);
            expect(row.find('button[title="Delete"]').exists()).toBe(true);
        }

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
        expect(wrapper.find('[data-testid="filter-environment"]').exists()).toBe(true);

        wrapper.unmount();
    });
});
