import type { Ref } from 'vue';
import type { MentionIdentity } from '../components/shift-editor/types';
import type { ThreadReaction } from './types';
import type { TaskThreadMentionCandidateFetcher } from './useTaskThreadAudienceState';

export type ThreadPayload = {
    html: string;
    tempIdentifier: string;
    clientRequestId?: string;
    audience: 'all' | 'team';
    mentions: MentionIdentity[];
    addCollaborators: MentionIdentity[];
};

export type UseTaskThreadStateOptions<TTaskDetail> = {
    editOpen: Ref<boolean>;
    editTask: Ref<TTaskDetail | null>;
    getTaskId: (task: TTaskDetail) => number;
    fetchThreads: (taskId: number) => Promise<unknown[]>;
    createThread: (taskId: number, payload: ThreadPayload) => Promise<unknown>;
    updateThread: (taskId: number, threadId: number, payload: ThreadPayload) => Promise<unknown>;
    deleteThread: (taskId: number, threadId: number) => Promise<void>;
    setThreadReaction?: (taskId: number, threadId: number, emoji: string, reacted: boolean) => Promise<ThreadReaction[]>;
    onReactionError?: (message: string) => void;
    publishThread?: (taskId: number, threadId: number) => Promise<unknown>;
    fetchMentionCandidates?: TaskThreadMentionCandidateFetcher;
    optimisticAuthor?: () => string;
    onCopyMessageSuccess?: () => void;
    onCopyMessageError?: () => void;
    onCopySelectionSuccess?: () => void;
    onCopySelectionError?: () => void;
    onSendError?: (message: string) => void;
    onDeleteError?: (message: string) => void;
};
