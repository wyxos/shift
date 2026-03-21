/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('includes grouped collaborator payloads in owner autosaves', async () => {
        vi.useFakeTimers();
        axiosGetMock.mockReset();
        axiosPutMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    project_id: 42,
                    title: 'Owner task',
                    environment: 'staging',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-02-10T17:40:00',
                    description: '',
                    is_owner: true,
                    can_manage_collaborators: true,
                    submitter: { email: 'owner@example.com' },
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } })
            .mockResolvedValueOnce({
                data: {
                    internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                    external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                    external_available: true,
                    external_error: null,
                },
            });

        axiosPutMock.mockResolvedValueOnce({
            data: {
                ok: true,
                task: {
                    id: 1,
                    title: 'Owner task',
                    environment: 'staging',
                    priority: 'medium',
                    status: 'pending',
                    description: '',
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            },
        });

        axiosPatchMock.mockResolvedValueOnce({
            data: {
                ok: true,
                task: {
                    id: 1,
                    title: 'Owner task',
                    environment: 'staging',
                    priority: 'medium',
                    status: 'pending',
                    description: '',
                    attachments: [],
                    internal_collaborators: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
                    external_collaborators: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Owner task', status: 'pending', priority: 'high' }]),
                projects: [{ id: 42, name: 'Portal', environments: [{ key: 'staging', label: 'Staging', url: 'https://portal.test' }] }],
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const nextCollaborators = {
            internal: [{ id: 91, name: 'Jane Doe', email: 'jane@example.com' }],
            external: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
        };

        (wrapper.vm as any).editForm.collaborators = nextCollaborators;
        await (wrapper.vm as any).$nextTick();
        expect((wrapper.vm as any).editForm.collaborators).toEqual(nextCollaborators);

        await wrapper.get('[data-testid="task-priority-medium"]').trigger('click');
        await flushPromises();

        vi.advanceTimersByTime(800);
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith(
            '/tasks.v2.update',
            expect.objectContaining({
                priority: 'medium',
                title: 'Owner task',
                status: 'pending',
            }),
        );

        expect(axiosPatchMock).toHaveBeenCalledWith(
            '/tasks.v2.collaborators.update',
            expect.objectContaining({
                environment: 'staging',
                internal_collaborator_ids: [91],
                external_collaborators: [{ id: 'client-7', name: 'Client User', email: 'client@example.com' }],
            }),
        );

        wrapper.unmount();
        vi.useRealTimers();
    });

});
