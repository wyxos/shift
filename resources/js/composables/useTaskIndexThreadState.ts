import type { TaskDetail } from '@/shared/tasks/types';
import { useTaskThreadState } from '@/shared/tasks/useTaskThreadState';
import axios from 'axios';
import type { ComputedRef, Ref } from 'vue';
import { toast } from 'vue-sonner';

type UseTaskIndexThreadStateOptions = {
    aiImproveEnabled: ComputedRef<boolean>;
    editOpen: Ref<boolean>;
    editTask: Ref<TaskDetail | null>;
};

export function useTaskIndexThreadState(options: UseTaskIndexThreadStateOptions) {
    const normalizeMentionCandidate = (candidate: any) => ({
        kind: candidate.kind,
        id: candidate.id,
        name: String(candidate.name ?? ''),
        email: candidate.email ?? null,
        isCollaborator: Boolean(candidate.is_collaborator ?? candidate.isCollaborator),
    });

    return {
        aiImproveEnabled: options.aiImproveEnabled,
        ...useTaskThreadState({
            editOpen: options.editOpen,
            editTask: options.editTask,
            getTaskId: (task) => task.id,
            fetchThreads: async (taskId) => {
                const response = await axios.get(route('task-threads.index', { task: taskId }));
                if (Array.isArray(response.data?.threads)) {
                    return response.data.threads;
                }

                return [...(response.data?.internal ?? []), ...(response.data?.external ?? [])].sort((left: any, right: any) => {
                    const timeDifference = new Date(left.created_at).getTime() - new Date(right.created_at).getTime();
                    return timeDifference || Number(left.id) - Number(right.id);
                });
            },
            createThread: async (taskId, payload) => {
                const response = await axios.post(route('task-threads.store', { task: taskId }), {
                    content: payload.html,
                    type: payload.audience === 'team' ? 'internal' : 'external',
                    temp_identifier: payload.tempIdentifier,
                    mentions: payload.mentions,
                    add_collaborators: payload.addCollaborators,
                });

                return response.data?.thread ?? response.data;
            },
            updateThread: async (taskId, threadId, payload) => {
                const response = await axios.put(route('task-threads.update', { task: taskId, thread: threadId }), {
                    content: payload.html,
                    type: payload.audience === 'team' ? 'internal' : 'external',
                    temp_identifier: payload.tempIdentifier,
                    mentions: payload.mentions,
                });

                return response.data?.thread ?? response.data;
            },
            deleteThread: async (taskId, threadId) => {
                await axios.delete(route('task-threads.destroy', { task: taskId, thread: threadId }));
            },
            fetchMentionCandidates: async (taskId, audience, search) => {
                const response = await axios.get(route('task-thread-mentions.candidates', { task: taskId }), {
                    params: { audience, search },
                });

                return {
                    existing: Array.isArray(response.data?.existing) ? response.data.existing.map(normalizeMentionCandidate) : [],
                    addable: Array.isArray(response.data?.addable) ? response.data.addable.map(normalizeMentionCandidate) : [],
                    addable_error: response.data?.addable_error ?? null,
                };
            },
            onCopyMessageSuccess: () => toast.success('Message copied'),
            onCopyMessageError: () => toast.error('Unable to copy message'),
            onCopySelectionSuccess: () => toast.success('Selection copied'),
            onCopySelectionError: () => toast.error('Unable to copy selection'),
        }),
    };
}
