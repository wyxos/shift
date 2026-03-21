/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Tasks/Index.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { axiosDeleteMock, axiosGetMock, axiosPatchMock, axiosPostMock, axiosPutMock, makeTasksPage, sonnerMocks } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('only enables copy selection when the selection belongs to that comment', async () => {
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
        (wrapper.vm as any).contextMenuMessageId = 10;
        (wrapper.vm as any).contextMenuSelectionText = 'Hello';
        expect((wrapper.vm as any).shouldShowCopySelection(message)).toBe(true);

        await (wrapper.vm as any).copySelectedMessage();
        expect(writeTextMock).toHaveBeenCalledWith('Hello');
        expect(sonnerMocks.toastSuccessMock).toHaveBeenCalledWith('Selection copied');

        (wrapper.vm as any).contextMenuSelectionText = '';
        expect((wrapper.vm as any).shouldShowCopySelection(message)).toBe(false);
        wrapper.unmount();
    });

});
