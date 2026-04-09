/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('shows task created timestamp in the edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
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

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Created');
        expect(wrapper.text()).toContain('17:40');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('shows task creator and environment in the edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    environment: 'staging',
                    created_at: '2026-02-10T17:40:00',
                    updated_at: '2026-02-10T17:55:00',
                    description: '',
                    is_owner: false,
                    submitter: { name: 'Taylor Brown', email: 'someone@example.com' },
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

        const editStatusGroup = wrapper.get('[aria-label="Task status"]');
        const mobilePaneGroup = wrapper.get('[aria-label="Edit task section"]');

        expect(wrapper.get('[data-testid="edit-task-environment"]').text()).toContain('Staging');
        expect(wrapper.find('[data-testid="edit-task-environment-select"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="edit-task-created-by"]').text()).toContain('Taylor Brown');
        expect(wrapper.get('[data-testid="edit-task-updated-at"]').text()).toContain('Updated');
        expect(editStatusGroup.classes()).toContain('grid-cols-2');
        expect(editStatusGroup.classes()).toContain('xl:grid-cols-4');
        expect(mobilePaneGroup.classes()).toContain('grid-cols-2');
        expect(wrapper.get('[data-testid="edit-mobile-pane-details"]').text()).toContain('Details');
        expect(wrapper.get('[data-testid="edit-mobile-pane-comments"]').text()).toContain('Comments');
        expect(wrapper.get('[data-testid="task-status-pending"]').classes()).toContain('bg-amber-100');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('renders html-backed task descriptions without exposing raw tags in the edit surface', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Auth issue',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '<p>Saved from rich editor</p>',
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

        const description = wrapper.get('[data-testid="task-edit-description"]');
        const preview = description.get('[data-testid="stub-editor-preview"]');

        expect(preview.text()).toContain('Saved from rich editor');
        expect(preview.text()).not.toContain('<p>');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('allows any user to change task status from the V2 edit sheet', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-02-10T18:00:00'));
        axiosGetMock.mockReset();
        axiosPutMock.mockReset();

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

        axiosPutMock.mockResolvedValueOnce({ data: { ok: true } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="task-status-in-progress"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="task-status-in-progress"]').classes()).toContain('bg-sky-100');

        vi.advanceTimersByTime(800);
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith('/tasks.v2.update', expect.objectContaining({ status: 'in-progress' }));
        expect(router.reload).toHaveBeenCalledWith({
            only: ['tasks', 'filters', 'projects'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: expect.any(Function),
        });
        expect(sonnerMocks.toastLoadingMock).toHaveBeenCalledWith('Saving task changes...');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Task changes saved', expect.objectContaining({ id: 'autosave-toast' }));

        wrapper.unmount();
        vi.useRealTimers();
    });

});
