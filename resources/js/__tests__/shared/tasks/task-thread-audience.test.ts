import { useTaskThreadAudienceState } from '@/shared/tasks/useTaskThreadAudienceState';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';

function mountAudienceState(fetchMentionCandidates?: ReturnType<typeof vi.fn>) {
    return mount(
        defineComponent({
            setup() {
                const editTask = ref({ id: 1 });
                const threadComposerHtml = ref('');
                const threadComposerRef = ref<any>(null);
                const threadEditingId = ref<number | null>(null);
                const threadMessages = ref([
                    {
                        clientId: 'all',
                        id: 1,
                        author: 'Alice',
                        time: '10:00',
                        content: '<p>All context</p>',
                        audience: 'all' as const,
                    },
                    {
                        clientId: 'team',
                        id: 2,
                        author: 'Bob',
                        time: '10:01',
                        content: '<p>Team secret context</p>',
                        audience: 'team' as const,
                    },
                ]);

                return {
                    editTask,
                    threadComposerHtml,
                    threadEditingId,
                    ...useTaskThreadAudienceState({
                        editTask,
                        getTaskId: (task) => task.id,
                        fetchMentionCandidates,
                        threadComposerHtml,
                        threadComposerRef,
                        threadEditingId,
                        threadMessages,
                    }),
                };
            },
            template: '<div />',
        }),
    );
}

afterEach(() => {
    vi.useRealTimers();
});

describe('task thread audience state', () => {
    it('keeps Team content out of All AI context while Team can use the authorized timeline', () => {
        const wrapper = mountAudienceState();

        expect((wrapper.vm as any).threadAiContext).toContain('All context');
        expect((wrapper.vm as any).threadAiContext).not.toContain('Team secret context');

        expect((wrapper.vm as any).setThreadAudience('team')).toBe(true);
        expect((wrapper.vm as any).threadAiContext).toContain('All context');
        expect((wrapper.vm as any).threadAiContext).toContain('Team secret context');

        (wrapper.vm as any).handleSlashCommand('all');
        expect((wrapper.vm as any).threadAudience).toBe('all');
    });

    it('does not allow a Team reply to be relabelled All', () => {
        const wrapper = mountAudienceState();
        (wrapper.vm as any).setThreadAudience('team');
        (wrapper.vm as any).threadComposerHtml = '<blockquote class="shift-reply" data-reply-to="2"><p>Team secret context</p></blockquote>';

        expect((wrapper.vm as any).setThreadAudience('all')).toBe(false);
        expect((wrapper.vm as any).threadAudience).toBe('team');
        expect((wrapper.vm as any).threadAudienceError).toContain('Remove the Team reply');
    });

    it('does not allow external mentions in Team and clears stale candidates during an audience change', async () => {
        vi.useFakeTimers();
        const fetchCandidates = vi.fn().mockResolvedValue({
            existing: [
                {
                    kind: 'external',
                    id: 'client-7',
                    name: 'Client User',
                    isCollaborator: true,
                },
            ],
            addable: [],
        });
        const wrapper = mountAudienceState(fetchCandidates);

        (wrapper.vm as any).handleMentionQuery('cli');
        await vi.advanceTimersByTimeAsync(180);
        await flushPromises();
        expect((wrapper.vm as any).threadMentionCandidates).toHaveLength(1);

        (wrapper.vm as any).threadComposerHtml =
            '<p><span data-shift-mention="true" data-mention-kind="external" data-mention-id="client-7">@Client User</span></p>';
        expect((wrapper.vm as any).setThreadAudience('team')).toBe(false);
        expect((wrapper.vm as any).threadMentionCandidates).toHaveLength(1);

        (wrapper.vm as any).threadComposerHtml = '';
        expect((wrapper.vm as any).setThreadAudience('team')).toBe(true);
        expect((wrapper.vm as any).threadMentionCandidates).toEqual([]);
        expect((wrapper.vm as any).threadMentionLoading).toBe(true);
    });

    it('offers only existing collaborators while editing a message', async () => {
        vi.useFakeTimers();
        const fetchCandidates = vi.fn().mockResolvedValue({
            existing: [{ kind: 'internal', id: 2, name: 'Existing', isCollaborator: true }],
            addable: [{ kind: 'internal', id: 3, name: 'Addable', isCollaborator: false }],
        });
        const wrapper = mountAudienceState(fetchCandidates);

        (wrapper.vm as any).threadEditingId = 7;
        (wrapper.vm as any).handleMentionQuery('');
        await vi.advanceTimersByTimeAsync(180);
        await flushPromises();

        expect((wrapper.vm as any).threadMentionCandidates).toEqual([expect.objectContaining({ id: 2, isCollaborator: true })]);
    });
});
