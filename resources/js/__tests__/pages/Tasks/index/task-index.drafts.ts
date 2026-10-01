import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosGetMock, axiosPostMock, makeTasksPage } from './test-helpers';

describe('Tasks/Index.vue draft comments', () => {
    it('shows an author draft and publishes it from the conversation', async () => {
        axiosGetMock.mockReset();
        axiosGetMock
            .mockResolvedValueOnce({
                data: {
                    id: 1,
                    title: 'Draft task',
                    priority: 'medium',
                    status: 'pending',
                    can_comment: true,
                    description: '',
                    attachments: [],
                },
            })
            .mockResolvedValueOnce({
                data: {
                    threads: [
                        {
                            id: 21,
                            sender_name: 'You',
                            is_current_user: true,
                            audience: 'all',
                            is_draft: true,
                            can_publish: true,
                            content: '<p>Ready for review</p>',
                            created_at: '2026-09-30T10:00:00Z',
                            attachments: [],
                        },
                    ],
                },
            });
        axiosPostMock.mockResolvedValueOnce({
            data: {
                thread: {
                    id: 21,
                    sender_name: 'You',
                    is_current_user: true,
                    audience: 'all',
                    is_draft: false,
                    can_publish: false,
                    content: '<p>Ready for review</p>',
                    created_at: '2026-10-01T10:00:00Z',
                    attachments: [],
                },
            },
        });

        const wrapper = mount(Index, {
            props: {
                tasks: makeTasksPage([{ id: 1, title: 'Draft task', status: 'pending', priority: 'medium' }]),
                filters: { status: ['pending'], priority: ['medium'], search: '' },
            },
        });
        await wrapper.get('button[data-testid^="task-open-"]').trigger('click');
        await flushPromises();

        const draftAction = wrapper.get('[data-testid="publish-draft-21"]');
        expect(wrapper.find('[data-testid="thread-draft-badge"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="comment-bubble-21"]').element.contains(draftAction.element)).toBe(false);
        expect(draftAction.attributes('aria-label')).toBe('Publish draft');
        expect(draftAction.classes()).toContain('destructive');
        expect(draftAction.get('[data-testid="draft-action-label"]').text()).toBe('Draft');
        expect(draftAction.get('[data-testid="publish-action-label"]').text()).toBe('Publish');
        expect(wrapper.get('[data-testid="comment-bubble-21"]').text()).toContain('Ready for review');
        await draftAction.trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith('/task-threads.publish');
        expect(wrapper.find('[data-testid="thread-draft-badge"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="publish-draft-21"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="comment-bubble-21"]').text()).toContain('Ready for review');
        wrapper.unmount();
    });
});
