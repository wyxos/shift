import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { shiftEditorConfirmMentionAdditionMock } from './ShiftEditorStub';
import { axiosGetMock, axiosPostMock, makeTasksPage } from './test-helpers';

describe('Tasks/Index.vue All and Team comments', () => {
    beforeEach(() => {
        shiftEditorConfirmMentionAdditionMock.mockClear();
    });

    it('renders one chronological timeline and sends with the persistent selected audience', async () => {
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Audience task',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-07-30T10:00:00Z',
                    description: '',
                    can_comment: true,
                    can_manage_collaborators: true,
                    submitter: { name: 'Owner' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    internal: [
                        {
                            id: 12,
                            sender_name: 'Bob',
                            is_current_user: false,
                            audience: 'team',
                            content: '<p>Team second</p>',
                            created_at: '2026-07-30T10:02:00Z',
                            attachments: [],
                            mentions: [],
                        },
                    ],
                    external: [
                        {
                            id: 11,
                            sender_name: 'Alice',
                            is_current_user: false,
                            audience: 'all',
                            content: '<p>All first</p>',
                            created_at: '2026-07-30T10:01:00Z',
                            attachments: [],
                            mentions: [],
                        },
                    ],
                    threads: [
                        {
                            id: 11,
                            sender_name: 'Alice',
                            is_current_user: false,
                            audience: 'all',
                            content: '<p>All first</p>',
                            created_at: '2026-07-30T10:01:00Z',
                            attachments: [],
                            mentions: [],
                        },
                        {
                            id: 12,
                            sender_name: 'Bob',
                            is_current_user: false,
                            audience: 'team',
                            content: '<p>Team second</p>',
                            created_at: '2026-07-30T10:02:00Z',
                            attachments: [],
                            mentions: [],
                        },
                    ],
                },
            });
        axiosPostMock.mockResolvedValueOnce({
            data: {
                thread: {
                    id: 13,
                    sender_name: 'You',
                    is_current_user: true,
                    audience: 'team',
                    content: '<p>Team reply</p>',
                    created_at: '2026-07-30T10:03:00Z',
                    attachments: [],
                    mentions: [],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Audience task', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending'], priority: ['high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();

        const bubbles = wrapper.findAll('[data-testid^="comment-bubble-"]');
        expect(bubbles.map((bubble) => bubble.text())).toEqual([expect.stringContaining('All first'), expect.stringContaining('Team second')]);
        expect(bubbles[0].text()).not.toContain('Team');
        expect(bubbles[1].text()).toContain('Team');
        expect(wrapper.get('[data-testid="thread-audience-all"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="thread-audience-team"]').exists()).toBe(true);

        await wrapper.get('[data-testid="thread-audience-team"]').trigger('click');
        await wrapper.get('[data-testid="stub-editor-input"]').setValue('<p>Team reply</p>');
        await wrapper.get('[data-testid="stub-send"]').trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith('/task-threads.store', {
            content: '<p>Team reply</p>',
            type: 'internal',
            temp_identifier: expect.any(String),
            mentions: [],
            add_collaborators: [],
        });
        expect((wrapper.vm as any).threadAudience).toBe('all');

        wrapper.unmount();
    });

    it('automatically keeps replies to Team messages in Team', async () => {
        axiosGetMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Audience task',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-07-30T10:00:00Z',
                    description: '',
                    can_comment: true,
                    submitter: { name: 'Owner' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    threads: [
                        {
                            id: 12,
                            sender_name: 'Bob',
                            is_current_user: false,
                            audience: 'team',
                            content: '<p>Team context</p>',
                            created_at: '2026-07-30T10:02:00Z',
                            attachments: [],
                            mentions: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Audience task', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending'], priority: ['high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();
        const message = (wrapper.vm as any).threadMessages[0];

        await (wrapper.vm as any).copyEntireMessage(message);
        expect((wrapper.vm as any).threadAudience).toBe('team');
        expect((wrapper.vm as any).setThreadAudience('all')).toBe(true);

        (wrapper.vm as any).startReplyToMessage(message);
        await flushPromises();

        expect((wrapper.vm as any).threadAudience).toBe('team');
        expect((wrapper.vm as any).threadComposerHtml).toContain('data-reply-to="12"');

        wrapper.unmount();
    });

    it('inserts an explicitly confirmed collaborator mention through the live composer ref', async () => {
        axiosGetMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Audience task',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-07-30T10:00:00Z',
                    description: '',
                    can_comment: true,
                    can_manage_collaborators: true,
                    submitter: { name: 'Owner' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { threads: [] } });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Audience task', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending'], priority: ['high'], search: '' },
            },
        });

        await wrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="stub-mention-add-request"]').trigger('click');

        expect(wrapper.text()).toContain('Add New Collaborator to task?');
        await wrapper.get('[data-testid="confirm-mention-collaborator-add"]').trigger('click');

        expect(shiftEditorConfirmMentionAdditionMock).toHaveBeenCalledWith(
            expect.objectContaining({ kind: 'internal', id: 31, isCollaborator: false }),
        );

        wrapper.unmount();
    });

    it('renders loading, error, disabled, and permission states for the active comments surface', async () => {
        let rejectThreads: (reason?: unknown) => void = () => {};
        const pendingThreads = new Promise((_, reject) => {
            rejectThreads = reject;
        });
        axiosGetMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Audience task',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-07-30T10:00:00Z',
                    description: '',
                    can_comment: true,
                    submitter: { name: 'Owner' },
                    attachments: [],
                },
            })
            .mockReturnValueOnce(pendingThreads);

        const loadingWrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Audience task', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending'], priority: ['high'], search: '' },
            },
        });

        await loadingWrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();
        expect(loadingWrapper.text()).toContain('Loading comments...');

        rejectThreads(new Error('Thread service unavailable'));
        await flushPromises();
        expect(loadingWrapper.text()).toContain('Thread service unavailable');
        loadingWrapper.unmount();

        window.history.replaceState({}, '', '/tasks');
        axiosGetMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 2,
                    title: 'Read-only task',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-07-30T10:00:00Z',
                    description: '',
                    can_comment: false,
                    submitter: { name: 'Owner' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { threads: [] } });

        const permissionWrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 2, title: 'Read-only task', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending'], priority: ['high'], search: '' },
            },
        });

        await permissionWrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();
        expect(permissionWrapper.text()).toContain('Commenting is unavailable for this task.');
        expect(permissionWrapper.find('[data-testid="stub-send"]').exists()).toBe(false);
        permissionWrapper.unmount();

        window.history.replaceState({}, '', '/tasks');
        let resolveSend: (value: unknown) => void = () => {};
        const pendingSend = new Promise((resolve) => {
            resolveSend = resolve;
        });
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 3,
                    title: 'Sending task',
                    priority: 'high',
                    status: 'pending',
                    created_at: '2026-07-30T10:00:00Z',
                    description: '',
                    can_comment: true,
                    submitter: { name: 'Owner' },
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({ data: { threads: [] } });
        axiosPostMock.mockReturnValueOnce(pendingSend);

        const disabledWrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 3, title: 'Sending task', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending'], priority: ['high'], search: '' },
            },
        });

        await disabledWrapper.find('button[title="Open details"]').trigger('click');
        await flushPromises();
        await disabledWrapper.get('[data-testid="stub-editor-input"]').setValue('<p>Sending</p>');
        await disabledWrapper.get('[data-testid="stub-send"]').trigger('click');
        await disabledWrapper.vm.$nextTick();

        expect(disabledWrapper.get('[data-testid="stub-send"]').attributes('disabled')).toBeDefined();
        expect(disabledWrapper.get('[data-testid="thread-audience-all"]').attributes('disabled')).toBeDefined();
        expect(disabledWrapper.get('[data-testid="thread-audience-team"]').attributes('disabled')).toBeDefined();

        resolveSend({
            data: {
                thread: {
                    id: 14,
                    sender_name: 'You',
                    is_current_user: true,
                    audience: 'all',
                    content: '<p>Sending</p>',
                    created_at: '2026-07-30T10:04:00Z',
                    attachments: [],
                    mentions: [],
                },
            },
        });
        await flushPromises();
        disabledWrapper.unmount();
    });
});
