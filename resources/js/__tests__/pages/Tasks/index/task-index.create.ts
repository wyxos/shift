/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('keeps create disabled until a project and title are provided', async () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [
                    { id: 42, name: 'Portal', environments: [{ key: 'staging', label: 'Staging', url: 'https://portal.test' }] },
                    { id: 43, name: 'Docs', environments: [] },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');

        const submit = wrapper.get('[data-testid="submit-create-task"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="create-task-title"]').setValue('   ');
        await wrapper.get('[data-testid="create-task-project"]').setValue('42');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="create-task-title"]').setValue('Created from UI');
        expect(submit.attributes('disabled')).toBeUndefined();

        wrapper.unmount();
    });

    it('creates a task from the V2 sheet and reloads the list', async () => {
        axiosGetMock.mockReset();
        axiosGetMock.mockResolvedValue({
            data: {
                internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                external_available: true,
                external_error: null,
            },
        });
        axiosPostMock.mockResolvedValueOnce({
            data: {
                data: {
                    id: 7,
                    title: 'Created from UI',
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [{ id: 42, name: 'Portal', environments: [{ key: 'staging', label: 'Staging', url: 'https://portal.test' }] }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');
        await wrapper.get('[data-testid="create-task-title"]').setValue('Created from UI');
        await wrapper.get('[data-testid="create-description-editor"] [data-testid="stub-editor-input"]').setValue('<p>Details</p>');
        await wrapper.get('[data-testid="set-task-environment"]').trigger('click');
        await wrapper.get('[data-testid="set-task-collaborators"]').trigger('click');
        await wrapper.get('[data-testid="create-task-form"]').trigger('submit');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/tasks.v2.store',
            expect.objectContaining({
                title: 'Created from UI',
                description: '<p>Details</p>',
                priority: 'medium',
                project_id: 42,
                environment: 'staging',
                internal_collaborator_ids: [91],
                external_collaborators: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
            }),
        );
        expect((router.reload as any).mock.calls).toHaveLength(1);
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Task created', {
            description: 'Your task has been added to the queue.',
        });

        wrapper.unmount();
    });

});
