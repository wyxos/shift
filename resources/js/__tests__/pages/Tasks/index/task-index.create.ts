import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, axiosPostMock, makeTasksPage, setShiftAiEnabled, setShiftAiFeatures, sonnerMocks } from './test-helpers';

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

        expect(wrapper.get('[data-testid="create-description-editor"]').find('[data-testid="stub-send"]').exists()).toBe(false);
        expect((wrapper.get('[data-testid="create-task-project"]').element as HTMLSelectElement).value).toBe('');
        expect((wrapper.get('[data-testid="create-task-project"]').element as HTMLSelectElement).selectedOptions[0]?.textContent).toBe(
            'Select a project',
        );

        const submit = wrapper.get('[data-testid="submit-create-task"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="create-task-title"]').setValue('   ');
        await wrapper.get('[data-testid="create-task-project"]').setValue('42');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="create-task-title"]').setValue('Created from UI');
        expect(submit.attributes('disabled')).toBeUndefined();

        wrapper.unmount();
    });

    it('creates a task from the task sheet and reloads the list', async () => {
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
            '/tasks.store',
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

    it('hides the email import dropzone when AI is disabled', async () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [{ id: 42, name: 'Portal', environments: [] }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');

        expect(wrapper.find('[data-testid="task-email-import-dropzone"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('enables email import independently from editor rewriting', async () => {
        setShiftAiFeatures({ emailImport: true, rewrite: false });
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [{ id: 42, name: 'Portal', environments: [] }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');

        expect(wrapper.find('[data-testid="task-email-import-dropzone"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="create-description-editor"]').find('[data-testid="stub-send"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('imports a dropped eml file into the create draft without creating the task when AI is enabled', async () => {
        setShiftAiEnabled(true);
        axiosGetMock.mockReset();
        axiosPostMock.mockResolvedValueOnce({
            data: {
                data: {
                    title: 'API fails when creating urgent fixes',
                    priority: 'high',
                    description_html: '<p>Customer reports the urgent fixes API fails during submission.</p>',
                    missing_details: ['Exact request payload'],
                    source: {
                        subject: 'Fw: EXT Urgent Fixes - API question',
                        from: 'Project Owner <owner@example.com>',
                        attachments: ['trace.txt'],
                    },
                    ai_used: true,
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [{ id: 42, name: 'Portal', environments: [] }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');

        const email = new File(['Subject: Fw: EXT Urgent Fixes - API question'], 'issue.eml', { type: 'message/rfc822' });
        await wrapper.get('[data-testid="task-email-import-dropzone"]').trigger('drop', {
            dataTransfer: {
                files: [email],
            },
        });
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledTimes(1);
        expect(axiosPostMock.mock.calls[0][0]).toBe('/tasks.email-import');
        expect(axiosPostMock.mock.calls[0][1]).toBeInstanceOf(FormData);
        expect((axiosPostMock.mock.calls[0][1] as FormData).get('project_id')).toBe('42');
        expect((axiosPostMock.mock.calls[0][1] as FormData).get('email')).toBe(email);
        expect(axiosPostMock.mock.calls[0][2]).toEqual(
            expect.objectContaining({
                headers: { 'Content-Type': 'multipart/form-data' },
            }),
        );
        expect((wrapper.get('[data-testid="create-task-title"]').element as HTMLInputElement).value).toBe('API fails when creating urgent fixes');
        expect(
            (wrapper.get('[data-testid="create-description-editor"] [data-testid="stub-editor-input"]').element as HTMLTextAreaElement).value,
        ).toBe('<p>Customer reports the urgent fixes API fails during submission.</p>');
        expect(wrapper.get('[data-testid="create-task-priority-high"]').classes().join(' ')).toContain('bg-rose-100');
        expect(wrapper.get('[data-testid="task-email-import-summary"]').text()).toContain('Fw: EXT Urgent Fixes - API question');
        expect(wrapper.get('[data-testid="task-email-import-missing"]').text()).toContain('Exact request payload');

        wrapper.unmount();
    });

    it('clears project-dependent environment and collaborators when the selected project changes', async () => {
        axiosGetMock.mockReset();
        axiosPostMock.mockResolvedValueOnce({
            data: {
                data: {
                    id: 8,
                    title: 'Moved project task',
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([]),
                projects: [
                    { id: 42, name: 'Portal', environments: [{ key: 'staging', label: 'Staging', url: 'https://portal.test' }] },
                    { id: 43, name: 'Docs', environments: [{ key: 'production', label: 'Production', url: 'https://docs.test' }] },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                    priority: ['low', 'medium', 'high'],
                    search: '',
                },
            },
        });

        await wrapper.get('[data-testid="open-create-task"]').trigger('click');
        await wrapper.get('[data-testid="create-task-title"]').setValue('Moved project task');
        await wrapper.get('[data-testid="create-task-project"]').setValue('42');
        await wrapper.get('[data-testid="set-task-environment"]').trigger('click');
        await wrapper.get('[data-testid="set-task-collaborators"]').trigger('click');

        await wrapper.get('[data-testid="create-task-project"]').setValue('43');
        await wrapper.get('[data-testid="create-task-form"]').trigger('submit');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/tasks.store',
            expect.objectContaining({
                project_id: 43,
                environment: null,
                internal_collaborator_ids: [],
                external_collaborators: [],
            }),
        );

        wrapper.unmount();
    });
});
