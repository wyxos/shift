import type { Ref } from 'vue';
import { getThreadErrorMessage } from './thread';
import type { UseTaskThreadStateOptions } from './thread-state-types';
import type { ThreadMessage } from './types';

type ReactionOptions = Pick<UseTaskThreadStateOptions<unknown>, 'setThreadReaction' | 'onReactionError'>;

export function useTaskThreadReactions(options: ReactionOptions, threadMessages: Ref<ThreadMessage[]>, getActiveTaskId: () => number | null) {
    const pendingReactions = new Set<string>();

    function isReactionPending(taskId: number, threadId?: number) {
        return pendingReactions.has(`${taskId}:${threadId}`);
    }

    async function toggleThreadReaction(message: ThreadMessage, emoji: string) {
        const taskId = getActiveTaskId();
        const threadId = message.id;
        const current = threadMessages.value.find((item) => item.id === threadId);
        if (!options.setThreadReaction || !taskId || !threadId || !current?.reactions || current.isDraft) return;
        const reactionKey = `${taskId}:${threadId}`;
        if (pendingReactions.has(reactionKey)) return;
        const reacted = !current.reactions.some((reaction) => reaction.emoji === emoji && reaction.reacted);
        pendingReactions.add(reactionKey);
        current.reacting = true;

        try {
            const reactions = await options.setThreadReaction(taskId, threadId, emoji, reacted);
            if (getActiveTaskId() !== taskId) return;
            threadMessages.value = threadMessages.value.map((item) => (item.id === threadId ? { ...item, reactions, reacting: false } : item));
        } catch (error) {
            if (getActiveTaskId() !== taskId) return;
            threadMessages.value = threadMessages.value.map((item) => (item.id === threadId ? { ...item, reacting: false } : item));
            options.onReactionError?.(getThreadErrorMessage(error, 'Unable to update reaction'));
        } finally {
            pendingReactions.delete(reactionKey);
        }
    }

    return { isReactionPending, toggleThreadReaction };
}
