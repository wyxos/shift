/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('cancels comment edit on Escape', async () => {
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
            .mockResolvedValueOnce({
                data: {
                    external: [
                        {
                            id: 11,
                            sender_name: 'You',
                            is_current_user: true,
                            content: '<p>Second</p>',
                            created_at: '2026-02-09T12:01:00Z',
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

        await wrapper.get('[data-testid="comment-bubble-11"]').trigger('dblclick');
        await wrapper.vm.$nextTick();

        const editor = wrapper.get('[data-testid="comments-editor"]');
        expect(editor.attributes('placeholder')).toBe('Edit your comment...');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(editor.attributes('placeholder')).toBe('Write a comment...');

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('copies the full text of a non-author comment', async () => {
        axiosGetMock.mockReset();
        const writeTextMock = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: writeTextMock },
            configurable: true,
        });

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
                            content: '<p>Hello <strong>team</strong></p>',
                            created_at: '2026-02-09T12:00:00Z',
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

        const message = (wrapper.vm as any).threadMessages.find((item: any) => item.id === 10);
        await (wrapper.vm as any).copyEntireMessage(message);

        expect(writeTextMock).toHaveBeenCalledWith('Hello team');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Message copied');

        wrapper.unmount();
    });

});
