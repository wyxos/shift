import Index from '@/pages/Tasks/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosDeleteMock, axiosGetMock, makeTasksPage } from './test-helpers';

describe('Tasks/Index.vue', () => {
    it('keeps the requirement comment delete dialog open with a busy confirm action while deletion is pending', async () => {
        let resolveDelete: ((value: { data: Record<string, never> }) => void) | undefined;
        const deletePromise = new Promise<{ data: Record<string, never> }>((resolve) => {
            resolveDelete = resolve;
        });

        axiosGetMock.mockReset();
        axiosDeleteMock.mockReturnValueOnce(deletePromise);

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

        document
            .querySelector<HTMLElement>('[data-testid="delete-thread-message"]')
            ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await flushPromises();

        await wrapper.get('[data-testid="confirm-thread-message-delete"]').trigger('click');
        await flushPromises();

        const confirm = wrapper.get<HTMLButtonElement>('[data-testid="confirm-thread-message-delete"]');
        expect(confirm.element.disabled).toBe(true);
        expect(confirm.attributes('aria-busy')).toBe('true');
        expect(confirm.text()).toContain('Deleting...');
        expect(wrapper.text()).toContain('Delete comment');

        resolveDelete?.({ data: {} });
        await flushPromises();

        expect(wrapper.find('[data-testid="confirm-thread-message-delete"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
