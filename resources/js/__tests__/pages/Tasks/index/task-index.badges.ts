/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

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

});
