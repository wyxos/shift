import axios from 'axios';
import type { ComputedRef, Ref } from 'vue';
import { useTaskThreadState } from '@/shared/tasks/useTaskThreadState';
import type { TaskDetail } from '@/shared/tasks/types';
import { toast } from 'vue-sonner';

type UseTaskIndexThreadStateOptions = {
    aiImproveEnabled: ComputedRef<boolean>;
    editOpen: Ref<boolean>;
    editTask: Ref<TaskDetail | null>;
};

export function useTaskIndexThreadState(options: UseTaskIndexThreadStateOptions) {
    return {
        aiImproveEnabled: options.aiImproveEnabled,
        ...useTaskThreadState({
            editOpen: options.editOpen,
            editTask: options.editTask,
            getTaskId: (task) => task.id,
            fetchThreads: async (taskId) => {
                const response = await axios.get(route('task-threads.index', { task: taskId }));
                return Array.isArray(response.data?.external) ? response.data.external : [];
            },
            createThread: async (taskId, payload) => {
                const response = await axios.post(route('task-threads.store', { task: taskId }), {
                    content: payload.html,
                    type: 'external',
                    temp_identifier: payload.tempIdentifier,
                });

                return response.data?.thread ?? response.data;
            },
            updateThread: async (taskId, threadId, payload) => {
                const response = await axios.put(route('task-threads.update', { task: taskId, thread: threadId }), {
                    content: payload.html,
                    temp_identifier: payload.tempIdentifier,
                });

                return response.data?.thread ?? response.data;
            },
            deleteThread: async (taskId, threadId) => {
                await axios.delete(route('task-threads.destroy', { task: taskId, thread: threadId }));
            },
            onCopyMessageSuccess: () => toast.success('Message copied'),
            onCopyMessageError: () => toast.error('Unable to copy message'),
            onCopySelectionSuccess: () => toast.success('Selection copied'),
            onCopySelectionError: () => toast.error('Unable to copy selection'),
        }),
    };
}
