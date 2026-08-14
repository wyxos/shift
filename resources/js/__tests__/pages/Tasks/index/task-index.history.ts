import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosGetMock, makeTasksPage } from './test-helpers';

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

        await wrapper.find('button[title="Open details"]').trigger('click');
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

    it('opens task details from the task title', async () => {
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

        await wrapper.get('[data-testid="task-title-1"]').trigger('click');
        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.show');
        expect(window.location.search).toContain('task=1');
        expect(pushStateSpy.mock.calls.some(([, , next]) => next === '/tasks?task=1')).toBe(true);

        wrapper.unmount();
        pushStateSpy.mockRestore();
    });

    it('auto-opens an app error sheet and occurrences from the dedicated deep link', async () => {
        axiosGetMock.mockReset();
        window.history.replaceState({}, '', '/error-reports?task=1');

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
                    error_signature: 'error-signature',
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } })
            .mockResolvedValueOnce({
                data: {
                    data: [],
                    pagination: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
                },
            });

        const wrapper = mount(Index, {
            props: {
                surface: 'app-errors',
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await flushPromises();

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.show');
        expect(axiosGetMock).toHaveBeenCalledWith('/task-threads.index');
        expect(axiosGetMock).toHaveBeenCalledWith('/task-error-occurrences.index', { params: { page: 1 } });

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

        expect(axiosGetMock).toHaveBeenCalledWith('/tasks.show');

        window.history.replaceState({}, '', '/tasks');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await flushPromises();

        expect(window.location.search).toBe('');
        expect((wrapper.vm as any).editOpen).toBe(false);

        wrapper.unmount();
    });
});
