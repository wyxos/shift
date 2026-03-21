/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('scrolls and highlights the original comment when clicking a reply quote reference', async () => {
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
                            content: '<p>Original message</p>',
                            created_at: '2026-02-09T12:00:00Z',
                            attachments: [],
                        },
                        {
                            id: 11,
                            sender_name: 'Bob',
                            is_current_user: false,
                            content:
                                '<blockquote class="shift-reply" data-reply-to="10"><p>Replying to Alice</p><p>Original message</p></blockquote><p>Follow up</p>',
                            created_at: '2026-02-09T12:03:00Z',
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

        const originBubble = wrapper.get('[data-testid="comment-bubble-10"]').element as HTMLElement;
        const originalScrollIntoView = (HTMLElement.prototype as any).scrollIntoView;
        const scrollIntoViewMock = vi.fn();
        Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
            value: scrollIntoViewMock,
            configurable: true,
            writable: true,
        });

        const quoteElement = wrapper.get('[data-testid="comment-bubble-11"] blockquote[data-reply-to]').element as HTMLElement;
        expect(quoteElement.getAttribute('data-reply-to')).toBe('10');
        expect(originBubble.textContent).toContain('Original message');

        if (originalScrollIntoView) {
            Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
                value: originalScrollIntoView,
                configurable: true,
                writable: true,
            });
        } else {
            delete (HTMLElement.prototype as any).scrollIntoView;
        }

        wrapper.unmount();
    });
});
