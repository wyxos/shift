import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, makeTasksPage, router, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('renders the requirements review surface with requirement language', () => {
        axiosGetMock.mockReset();

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                tasks: makeTasksPage([{ id: 1, title: 'Export renewal data', status: 'pending', priority: 'medium', phase: 'requirement' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        expect(wrapper.text()).toContain('Requirements');
        expect(wrapper.text()).toContain('Review submitted requirement items before they become active tasks.');
        expect(wrapper.text()).toContain('Showing 1 to 1 of 1 requirements');
        expect(wrapper.find('[data-testid="task-create-trigger"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('groups requirement rows by submitted pack', () => {
        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                tasks: makeTasksPage([
                    {
                        id: 1,
                        title: 'Monthly renewal report',
                        status: 'pending',
                        priority: 'medium',
                        phase: 'requirement',
                        can_delete: true,
                        can_finalize_requirement: true,
                        batch: {
                            id: 7,
                            title: 'June client requirements',
                            total_items: 2,
                            requirement_items: 2,
                            finalized_items: 0,
                            can_finalize_requirement: true,
                        },
                    },
                    {
                        id: 2,
                        title: 'CSV export',
                        status: 'pending',
                        priority: 'medium',
                        phase: 'requirement',
                        can_delete: true,
                        can_finalize_requirement: true,
                        batch: {
                            id: 7,
                            title: 'June client requirements',
                            total_items: 2,
                            requirement_items: 2,
                            finalized_items: 0,
                            can_finalize_requirement: true,
                        },
                    },
                    {
                        id: 3,
                        title: 'Notification wording',
                        status: 'pending',
                        priority: 'low',
                        phase: 'requirement',
                        can_delete: true,
                        can_finalize_requirement: true,
                        batch: {
                            id: 8,
                            title: 'Notification changes',
                            total_items: 1,
                            requirement_items: 1,
                            finalized_items: 0,
                            can_finalize_requirement: true,
                        },
                    },
                ]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        expect(wrapper.text()).toContain('June client requirements');
        expect(wrapper.text()).toContain('Notification changes');
        expect(wrapper.text()).toContain('2 items');
        expect(wrapper.text()).toContain('2 pending');
        expect(wrapper.find('[data-testid="requirement-pack-finalize-7"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('hides requirement item and pack finalize controls when the capability is missing', async () => {
        axiosGetMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    project_id: 42,
                    title: 'Monthly renewal report',
                    priority: 'medium',
                    status: 'pending',
                    phase: 'requirement',
                    finalized: false,
                    created_at: '2026-02-10T17:40:00',
                    description: '<p>Client supplied requirement.</p>',
                    is_owner: true,
                    can_comment: true,
                    can_edit_requirement: false,
                    can_finalize_requirement: false,
                    can_manage_collaborators: false,
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            })
            .mockResolvedValueOnce({ data: { external: [] } });

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                tasks: makeTasksPage([
                    {
                        id: 1,
                        title: 'Monthly renewal report',
                        status: 'pending',
                        priority: 'medium',
                        phase: 'requirement',
                        can_delete: false,
                        can_finalize_requirement: false,
                        batch: {
                            id: 7,
                            title: 'June client requirements',
                            total_items: 1,
                            requirement_items: 1,
                            finalized_items: 0,
                            can_finalize_requirement: false,
                        },
                    },
                ]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        expect(wrapper.find('[data-testid="requirement-pack-finalize-7"]').exists()).toBe(false);

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="finalize-requirement"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="comments-editor"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('confirms pack finalization in an alert dialog before promoting every open item', async () => {
        const confirmSpy = vi.fn(() => true);
        vi.stubGlobal('confirm', confirmSpy);
        axiosPatchMock.mockResolvedValueOnce({
            data: {
                ok: true,
                finalized_count: 2,
                tasks: [
                    { id: 1, title: 'Monthly renewal report', phase: 'task', finalized: true },
                    { id: 2, title: 'CSV export', phase: 'task', finalized: true },
                ],
            },
        });

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                tasks: makeTasksPage([
                    {
                        id: 1,
                        title: 'Monthly renewal report',
                        status: 'pending',
                        priority: 'medium',
                        phase: 'requirement',
                        can_delete: true,
                        can_finalize_requirement: true,
                        batch: {
                            id: 7,
                            title: 'June client requirements',
                            total_items: 2,
                            requirement_items: 2,
                            finalized_items: 0,
                            can_finalize_requirement: true,
                        },
                    },
                    {
                        id: 2,
                        title: 'CSV export',
                        status: 'pending',
                        priority: 'medium',
                        phase: 'requirement',
                        can_delete: true,
                        can_finalize_requirement: true,
                        batch: {
                            id: 7,
                            title: 'June client requirements',
                            total_items: 2,
                            requirement_items: 2,
                            finalized_items: 0,
                            can_finalize_requirement: true,
                        },
                    },
                ]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.get('[data-testid="requirement-pack-finalize-7"]').trigger('click');
        await flushPromises();

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(axiosPatchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Finalize requirement pack');
        expect(wrapper.text()).toContain('Finalize all 2 open requirements in June client requirements as active tasks.');

        await wrapper.get('[data-testid="confirm-requirement-pack-finalize"]').trigger('click');
        await flushPromises();

        expect(axiosPatchMock).toHaveBeenCalledWith('/requirements.v2.batches.finalize', {});
        expect(router.reload).toHaveBeenCalledWith({ preserveScroll: true, preserveState: true });
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Requirement pack finalized', {
            description: '2 items now appear in the active task list.',
        });

        vi.unstubAllGlobals();
        wrapper.unmount();
    });

    it('finalizes a requirement from the edit sheet without replacing its thread context', async () => {
        vi.useFakeTimers();
        axiosGetMock.mockReset();
        axiosPatchMock.mockReset();

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    project_id: 42,
                    title: 'Export renewal data',
                    priority: 'medium',
                    status: 'pending',
                    phase: 'requirement',
                    finalized: false,
                    created_at: '2026-02-10T17:40:00',
                    description: '<p>Original requested export.</p>',
                    submitted_title: 'Export renewal data',
                    submitted_description: '<p>Original requested export.</p>',
                    is_owner: true,
                    can_edit_requirement: true,
                    can_finalize_requirement: true,
                    can_manage_collaborators: true,
                    submitter: { email: 'client@example.com' },
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Client User',
                            is_current_user: false,
                            content: '<p>Need this for monthly reporting.</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        axiosPatchMock.mockResolvedValueOnce({
            data: {
                ok: true,
                task: {
                    id: 1,
                    project_id: 42,
                    title: 'Export renewal data',
                    priority: 'medium',
                    status: 'pending',
                    phase: 'task',
                    finalized: true,
                    description: '<p>Original requested export.</p>',
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                tasks: makeTasksPage([{ id: 1, title: 'Export renewal data', status: 'pending', priority: 'medium', phase: 'requirement' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Clarifications');
        expect(wrapper.text()).toContain('Original requested export.');

        await wrapper.get('[data-testid="finalize-requirement"]').trigger('click');
        await flushPromises();

        expect(axiosPatchMock).toHaveBeenCalledWith('/requirements.v2.finalize', {
            title: 'Export renewal data',
            description: '<p>Original requested export.</p>',
        });
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Requirement finalized', {
            description: 'The item now appears in the active task list.',
        });

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('confirms requirement comment deletion in an alert dialog before deleting the thread message', async () => {
        const confirmSpy = vi.fn(() => true);
        vi.stubGlobal('confirm', confirmSpy);
        axiosGetMock.mockReset();
        axiosDeleteMock.mockResolvedValueOnce({ data: {} });

        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    project_id: 42,
                    title: 'Export renewal data',
                    priority: 'medium',
                    status: 'pending',
                    phase: 'requirement',
                    finalized: false,
                    created_at: '2026-02-10T17:40:00',
                    description: '<p>Original requested export.</p>',
                    is_owner: true,
                    can_manage_collaborators: true,
                    submitter: { email: 'client@example.com' },
                    attachments: [],
                    internal_collaborators: [],
                    external_collaborators: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '<p>Draft clarification.</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                surface: 'requirements',
                tasks: makeTasksPage([{ id: 1, title: 'Export renewal data', status: 'pending', priority: 'medium', phase: 'requirement' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-testid="comment-bubble-11"]').trigger('contextmenu');
        await flushPromises();

        const deleteItem = document.querySelector('[data-testid="delete-thread-message"]') as HTMLElement | null;
        expect(deleteItem).not.toBeNull();

        deleteItem?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(axiosDeleteMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Delete comment');
        expect(wrapper.text()).toContain('Delete this comment from the thread? This cannot be undone.');

        await wrapper.get('[data-testid="confirm-thread-message-delete"]').trigger('click');
        await flushPromises();

        expect(axiosDeleteMock).toHaveBeenCalledWith('/task-threads.destroy');

        vi.unstubAllGlobals();
        wrapper.unmount();
    });
});
