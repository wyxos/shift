import { computed, onBeforeUnmount, ref, type Ref } from 'vue';
import type { MentionCandidate } from '../components/shift-editor/types';
import { buildThreadAiContext } from './ai';
import type { ThreadMessage } from './types';

export type TaskThreadMentionCandidateResponse = {
    existing?: MentionCandidate[];
    addable?: MentionCandidate[];
    addable_error?: string | null;
};

export type TaskThreadMentionCandidateFetcher = (
    taskId: number,
    audience: 'all' | 'team',
    search: string,
) => Promise<TaskThreadMentionCandidateResponse>;

type AudienceStateOptions<TTaskDetail> = {
    editTask: Ref<TTaskDetail | null>;
    getTaskId: (task: TTaskDetail) => number;
    fetchMentionCandidates?: TaskThreadMentionCandidateFetcher;
    threadComposerHtml: Ref<string>;
    threadComposerRef: Ref<any>;
    threadEditingId: Ref<number | null>;
    threadMessages: Ref<ThreadMessage[]>;
};

function errorMessage(error: any): string {
    return error?.response?.data?.error || error?.response?.data?.message || error?.message || 'Unable to find eligible people';
}

export function useTaskThreadAudienceState<TTaskDetail>(options: AudienceStateOptions<TTaskDetail>) {
    const threadAudience = ref<'all' | 'team'>('all');
    const threadAudienceError = ref<string | null>(null);
    const threadMentionCandidates = ref<MentionCandidate[]>([]);
    const threadMentionLoading = ref(false);
    const threadMentionError = ref<string | null>(null);
    const threadAiContext = computed(() =>
        buildThreadAiContext(
            threadAudience.value === 'all'
                ? options.threadMessages.value.filter((message) => message.audience === 'all')
                : options.threadMessages.value,
        ),
    );
    let mentionQueryTimer: number | null = null;
    let mentionRequestSequence = 0;

    onBeforeUnmount(() => {
        if (mentionQueryTimer !== null) window.clearTimeout(mentionQueryTimer);
    });

    function composerReferencesTeamMessage(): boolean {
        const html = options.threadComposerRef.value?.editor?.getHTML?.() ?? options.threadComposerHtml.value;
        const referencedIds = new Set<number>();

        if (typeof DOMParser !== 'undefined') {
            const document = new DOMParser().parseFromString(html, 'text/html');
            document.querySelectorAll('[data-reply-to]').forEach((element) => {
                const id = Number.parseInt(element.getAttribute('data-reply-to') ?? '', 10);
                if (Number.isFinite(id)) referencedIds.add(id);
            });
            document.querySelectorAll('a[href^="#comment-"]').forEach((element) => {
                const match = element.getAttribute('href')?.match(/^#comment-(\d+)/);
                if (match) referencedIds.add(Number.parseInt(match[1], 10));
            });
        } else {
            for (const match of html.matchAll(/(?:data-reply-to=["']|#comment-)(\d+)/g)) {
                referencedIds.add(Number.parseInt(match[1], 10));
            }
        }

        return options.threadMessages.value.some((message) => message.id && referencedIds.has(message.id) && message.audience === 'team');
    }

    function composerMentionsExternalCollaborator(): boolean {
        const html = options.threadComposerRef.value?.editor?.getHTML?.() ?? options.threadComposerHtml.value;
        return /data-mention-kind=["']external["']/i.test(html);
    }

    function setThreadAudience(audience: 'all' | 'team'): boolean {
        if (audience === threadAudience.value) return true;
        if (options.threadEditingId.value) {
            threadAudienceError.value = 'Finish editing this message before changing its audience.';
            return false;
        }

        if (audience === 'all' && composerReferencesTeamMessage()) {
            threadAudienceError.value = 'Remove the Team reply before changing this message to All.';
            return false;
        }

        if (audience === 'team' && composerMentionsExternalCollaborator()) {
            threadAudienceError.value = 'Remove non-Team mentions before changing this message to Team.';
            return false;
        }

        threadAudience.value = audience;
        threadAudienceError.value = null;
        handleMentionQuery('');
        return true;
    }

    function handleSlashCommand(command: string) {
        if (command === 'all' || command === 'team') setThreadAudience(command);
    }

    function handleMentionQuery(search: string) {
        if (mentionQueryTimer !== null) window.clearTimeout(mentionQueryTimer);

        if (!options.editTask.value || !options.fetchMentionCandidates) {
            threadMentionCandidates.value = [];
            threadMentionLoading.value = false;
            threadMentionError.value = null;
            return;
        }

        const taskId = options.getTaskId(options.editTask.value);
        const audience = threadAudience.value;
        const editingId = options.threadEditingId.value;
        const sequence = ++mentionRequestSequence;
        threadMentionCandidates.value = [];
        threadMentionLoading.value = true;
        threadMentionError.value = null;

        mentionQueryTimer = window.setTimeout(async () => {
            try {
                const response = await options.fetchMentionCandidates?.(taskId, audience, search);
                if (sequence !== mentionRequestSequence || audience !== threadAudience.value || editingId !== options.threadEditingId.value) return;

                threadMentionCandidates.value = [...(response?.existing ?? []), ...(editingId ? [] : (response?.addable ?? []))];
                threadMentionError.value = response?.addable_error ?? null;
            } catch (error: any) {
                if (sequence !== mentionRequestSequence) return;
                threadMentionCandidates.value = [];
                threadMentionError.value = errorMessage(error);
            } finally {
                if (sequence === mentionRequestSequence) threadMentionLoading.value = false;
            }
        }, 180);
    }

    function resetThreadAudienceState() {
        threadAudience.value = 'all';
        threadAudienceError.value = null;
        threadMentionCandidates.value = [];
        threadMentionLoading.value = false;
        threadMentionError.value = null;
        mentionRequestSequence += 1;
        if (mentionQueryTimer !== null) {
            window.clearTimeout(mentionQueryTimer);
            mentionQueryTimer = null;
        }
    }

    return {
        handleMentionQuery,
        handleSlashCommand,
        resetThreadAudienceState,
        setThreadAudience,
        threadAiContext,
        threadAudience,
        threadAudienceError,
        threadMentionCandidates,
        threadMentionError,
        threadMentionLoading,
    };
}
