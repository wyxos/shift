/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

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

        expect(wrapper.find('input[placeholder="Search by title"]').exists()).toBe(true);
        expect(wrapper.findAll('button[data-testid^="task-filter-status-"]').length).toBeGreaterThanOrEqual(4);
        expect(wrapper.findAll('button[data-testid^="task-filter-priority-"]').length).toBeGreaterThanOrEqual(3);
        expect(wrapper.find('input[placeholder="Environment"]').exists()).toBe(true);

        wrapper.unmount();
    });

});
