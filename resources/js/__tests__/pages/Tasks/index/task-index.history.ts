/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('syncs task id in URL when opening and closing the edit sheet', async () => {
        axiosGetMock.mockReset();
        const pushStateSpy = vi.spyOn(window.history, 'pushState');

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(window.location.search).toContain('task=1');
        expect(pushStateSpy.mock.calls.some(([, , next]) => next === '/tasks?task=1')).toBe(true);

        (wrapper.vm as any).closeEditNow();
        await flushPromises();

        expect(window.location.search).toBe('');
        expect(pushStateSpy.mock.calls.some(([, , next]) => next === '/tasks')).toBe(true);
        wrapper.unmount();
        pushStateSpy.mockRestore();
    });

    it('auto-opens the edit sheet from task URL query', async () => {
        axiosGetMock.mockReset();
        window.history.replaceState({}, '', '/tasks?task=1');

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.v2.show');
        expect(axiosGetMock).toHaveBeenCalledWith('/task-threads.index');

        wrapper.unmount();
    });

    it('handles browser popstate navigation for task deep links', async () => {
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: false,
                    submitter: { email: 'someone@example.com' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        window.history.replaceState({}, '', '/tasks?task=1');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.v2.show');

        window.history.replaceState({}, '', '/tasks');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await flushPromises();

        expect(window.location.search).toBe('');
        expect((wrapper.vm as any).editOpen).toBe(false);

        wrapper.unmount();
    });

});
