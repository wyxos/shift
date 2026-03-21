/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('replies to a comment by quoting and linking back to the original message', async () => {
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();

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
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>Original message</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        axiosPostMock.mockResolvedValueOnce({
            data: {
                thread: {
                    id: 12,
                    sender_name: 'You',
                    is_current_user: true,
                    content: '<p>Sent reply</p>',
                    created_at: '2026-02-09T12:03:00Z',
                    attachments: [],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const message = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        (wrapper.vm as any).startReplyToMessage(message);
        await flushPromises();

        const composerHtml = (wrapper.vm as any).threadComposerHtml as string;
        expect(composerHtml).toContain('class="shift-reply"');
        expect(composerHtml).toContain('data-reply-to="10"');

        const commentsEditor = wrapper.get('[data-testid="comments-editor"]');
        await commentsEditor.get('[data-testid="stub-send"]').trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/task-threads.store',
            expect.objectContaining({
                content: expect.stringContaining('data-reply-to="10"'),
            }),
        );

        wrapper.unmount();
    });

    it('appends multiple replies into the same draft instead of replacing previous content', async () => {
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
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 10,
                            sender_name: 'Alice',
                            is_current_user: false,
                            content: '<p>First message</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                        {
                            id: 13,
                            sender_name: 'Bob',
                            is_current_user: false,
                            content: '<p>Second message</p>',
                            created_at: '2026-02-09T12:02:00Z',
                            attachments: [],
                        },
                    ],
                },
            });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }]),
                filters: { status: ['pending', 'in-progress', 'awaiting-feedback'], priority: ['low', 'medium', 'high'], search: '' },
            },
        });

        await wrapper.find('button[title="Edit"]').trigger('click');
        await flushPromises();

        const firstMessage = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        const secondMessage = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 13);

        (wrapper.vm as any).startReplyToMessage(firstMessage);
        await flushPromises();
        (wrapper.vm as any).threadComposerHtml = `${(wrapper.vm as any).threadComposerHtml}<p>stuff</p>`;

        (wrapper.vm as any).startReplyToMessage(secondMessage);
        await flushPromises();

        const composerHtml = (wrapper.vm as any).threadComposerHtml as string;
        const replyMatches = composerHtml.match(/data-reply-to="/g) ?? [];

        expect(replyMatches.length).toBe(2);
        expect(composerHtml).toContain('data-reply-to="10"');
        expect(composerHtml).toContain('data-reply-to="13"');
        expect(composerHtml.indexOf('data-reply-to="10"')).toBeLessThan(composerHtml.indexOf('data-reply-to="13"'));
        expect(composerHtml).toContain('<p>stuff</p>');

        wrapper.unmount();
    });

});
