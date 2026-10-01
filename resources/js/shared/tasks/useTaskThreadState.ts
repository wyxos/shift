import { nextTick, ref, type Ref } from 'vue';
import type { MentionIdentity } from '../components/shift-editor/types';
import { resolveTouchTap, shouldIgnoreEditGesture as shouldIgnoreEditGestureForEvent } from './interaction';
import { buildReplyQuoteHtml } from './rich-content';
import { createThreadRequestId, getThreadErrorMessage, mapPendingThreadAttachments, mapThreadToMessage, mergeDeliveredThreadMessage } from './thread';
import type { TaskAttachment, ThreadMessage } from './types';
import { useTaskThreadAudienceState, type TaskThreadMentionCandidateFetcher } from './useTaskThreadAudienceState';
import { useTaskThreadRichInteraction } from './useTaskThreadRichInteraction';
import { useTaskThreadViewport } from './useTaskThreadViewport';

type ThreadPayload = {
    html: string;
    tempIdentifier: string;
    clientRequestId?: string;
    audience: 'all' | 'team';
    mentions: MentionIdentity[];
    addCollaborators: MentionIdentity[];
};

type PendingThread = { taskId: number; payload: ThreadPayload };

type UseTaskThreadStateOptions<TTaskDetail> = {
    editOpen: Ref<boolean>;
    editTask: Ref<TTaskDetail | null>;
    getTaskId: (task: TTaskDetail) => number;
    fetchThreads: (taskId: number) => Promise<unknown[]>;
    createThread: (taskId: number, payload: ThreadPayload) => Promise<unknown>;
    updateThread: (taskId: number, threadId: number, payload: ThreadPayload) => Promise<unknown>;
    deleteThread: (taskId: number, threadId: number) => Promise<void>;
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

export function useTaskThreadState<TTaskDetail>(options: UseTaskThreadStateOptions<TTaskDetail>) {
    const threadTempIdentifier = ref(Date.now().toString());
    const threadLoading = ref(false);
    const threadSending = ref(false);
    const threadError = ref<string | null>(null);
    const threadMessages = ref<ThreadMessage[]>([]);
    const transientMessages = new Map<number, ThreadMessage[]>();
    const pendingThreads = new Map<string, PendingThread>();
    const clientIdsByRequest = new Map<string, string>();
    let activeTaskId: number | null = null;
    let fetchGeneration = 0;
    let localMessageSequence = 0;
    const threadComposerRef = ref<any>(null);
    const threadComposerHtml = ref('');
    const threadComposerUploading = ref(false);
    const threadEditingId = ref<number | null>(null);
    const threadEditSaving = ref(false);
    const threadEditError = ref<string | null>(null);
    const {
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
    } = useTaskThreadAudienceState({
        editTask: options.editTask,
        getTaskId: options.getTaskId,
        fetchMentionCandidates: options.fetchMentionCandidates,
        threadComposerHtml,
        threadComposerRef,
        threadEditingId,
        threadMessages,
    });
    const lastTouchTapAt = ref(0);
    const lastTouchTapId = ref<number | null>(null);
    const { commentsScrollRef, onCommentsMediaLoadCapture, scrollCommentsToBottomSoon } = useTaskThreadViewport(options.editOpen, threadMessages);
    const {
        contextMenuMessageId,
        contextMenuSelectionText,
        copyEntireMessage,
        copySelectedMessage,
        handleReplyReferenceClick,
        lightboxAlt,
        lightboxOpen,
        lightboxSrc,
        onCommentContextMenuOpen,
        onGlobalClickCapture,
        onGlobalDblClickCapture,
        onGlobalKeyDownCapture,
        onMessageCopy,
        onRichContentClick,
        resetRichInteractionState,
        shouldShowCopySelection,
    } = useTaskThreadRichInteraction({
        cancelThreadEdit: () => cancelThreadEdit(),
        commentsScrollRef,
        editOpen: options.editOpen,
        threadEditingId,
        onCopyMessageSuccess: options.onCopyMessageSuccess,
        onCopyMessageError: options.onCopyMessageError,
        onCopySelectionSuccess: options.onCopySelectionSuccess,
        onCopySelectionError: options.onCopySelectionError,
        onCopyTeamContent: () => threadAudience.value === 'team' || setThreadAudience('team'),
    });

    function resetThreadState() {
        fetchGeneration += 1;
        activeTaskId = null;
        threadTempIdentifier.value = Date.now().toString();
        threadLoading.value = false;
        threadSending.value = false;
        threadError.value = null;
        threadMessages.value = [];
        resetThreadAudienceState();
        threadComposerHtml.value = '';
        threadComposerUploading.value = false;
        threadEditingId.value = null;
        threadEditSaving.value = false;
        threadEditError.value = null;
        resetRichInteractionState();
        lastTouchTapAt.value = 0;
        lastTouchTapId.value = null;
    }

    async function fetchThreads(taskId: number, { quiet = false }: { quiet?: boolean } = {}) {
        const generation = ++fetchGeneration;
        const deliveredAtStart = new Set(threadMessages.value.filter((message) => message.id).map((message) => message.id));
        activeTaskId = taskId;
        if (!quiet) {
            threadLoading.value = true;
            threadError.value = null;
        }
        try {
            const list = await options.fetchThreads(taskId);
            if (generation !== fetchGeneration || activeTaskId !== taskId) return;
            const transient = transientMessages.get(taskId) ?? [];
            const reconciledIds = new Set<string>();
            const fetched = list.map((thread) => {
                const message = mapThreadToMessage<TaskAttachment>(thread);
                const matching = transient.find((item) => item.clientRequestId && item.clientRequestId === message.clientRequestId);
                if (matching) {
                    reconciledIds.add(matching.clientId);
                    pendingThreads.delete(matching.clientId);
                }
                const knownClientId = message.clientRequestId ? clientIdsByRequest.get(message.clientRequestId) : undefined;
                if (matching || knownClientId) message.clientId = matching?.clientId ?? knownClientId!;
                const current = threadMessages.value.find((item) => item.id === message.id);
                if (current?.publishing && message.isDraft) return { ...message, publishing: true };
                if (current?.publishError && message.isDraft) return { ...message, publishError: current.publishError };
                return message;
            });
            if (reconciledIds.size) {
                const remaining = transient.filter((message) => !reconciledIds.has(message.clientId));
                if (remaining.length) transientMessages.set(taskId, remaining);
                else transientMessages.delete(taskId);
            }
            const fetchedIds = new Set(fetched.map((message) => message.id));
            const deliveredDuringFetch = threadMessages.value.filter(
                (message) => message.id && !deliveredAtStart.has(message.id) && !fetchedIds.has(message.id),
            );
            threadMessages.value = [...fetched, ...deliveredDuringFetch, ...(transientMessages.get(taskId) ?? [])];
            threadError.value = null;
            refreshThreadSending();
            if (!quiet) scrollCommentsToBottomSoon();
        } catch (error: any) {
            if (!quiet && generation === fetchGeneration) threadError.value = getThreadErrorMessage(error, 'Failed to load comments');
        } finally {
            if (!quiet && generation === fetchGeneration) threadLoading.value = false;
        }
    }
    function refreshThreadSending() {
        threadSending.value = (transientMessages.get(activeTaskId ?? -1) ?? []).some((message) => message.pending);
    }

    function updateTransientMessage(taskId: number, clientId: string, update: (message: ThreadMessage) => ThreadMessage | null) {
        const messages = transientMessages.get(taskId) ?? [];
        const next = messages
            .map((message) => (message.clientId === clientId ? update(message) : message))
            .filter((message): message is ThreadMessage => message !== null);
        if (next.length) transientMessages.set(taskId, next);
        else transientMessages.delete(taskId);
        if (activeTaskId === taskId) {
            threadMessages.value = threadMessages.value
                .map((message) => (message.clientId === clientId ? update(message) : message))
                .filter((message): message is ThreadMessage => message !== null);
            refreshThreadSending();
        }
    }

    async function createPendingThread(clientId: string, taskId: number, payload: ThreadPayload) {
        try {
            const thread = await options.createThread(taskId, payload);
            const serverMessage = { ...mapThreadToMessage<TaskAttachment>(thread), clientId };
            updateTransientMessage(taskId, clientId, () => serverMessage);
            const remaining = (transientMessages.get(taskId) ?? []).filter((message) => message.clientId !== clientId);
            if (remaining.length) transientMessages.set(taskId, remaining);
            else transientMessages.delete(taskId);
            if (activeTaskId === taskId) {
                threadMessages.value = mergeDeliveredThreadMessage(threadMessages.value, serverMessage);
                scrollCommentsToBottomSoon();
            }
            pendingThreads.delete(clientId);
        } catch (error: any) {
            if (!pendingThreads.has(clientId)) return;
            const message = getThreadErrorMessage(error, 'Failed to send comment');
            updateTransientMessage(taskId, clientId, (item) => ({ ...item, pending: false, failed: true, time: 'Failed to send' }));
            if (options.onSendError) options.onSendError(message);
        }
    }
    function retryThreadSend(message: ThreadMessage) {
        const pending = pendingThreads.get(message.clientId);
        if (!pending || message.pending || !message.failed) return;
        updateTransientMessage(pending.taskId, message.clientId, (item) => ({ ...item, pending: true, failed: false, time: 'Sending...' }));
        void createPendingThread(message.clientId, pending.taskId, pending.payload);
    }

    async function publishThreadMessage(message: ThreadMessage) {
        if (!options.publishThread || !options.editTask.value || !message.id || !message.isDraft || !message.canPublish) return;
        if (threadMessages.value.some((item) => item.id === message.id && item.publishing)) return;
        const taskId = options.getTaskId(options.editTask.value);
        const threadId = message.id;
        threadMessages.value = threadMessages.value.map((item) => (item.id === threadId ? { ...item, publishing: true, publishError: null } : item));

        try {
            const thread = await options.publishThread(taskId, threadId);
            if (activeTaskId !== taskId) return;
            const published = { ...mapThreadToMessage<TaskAttachment>(thread), clientId: message.clientId };
            // Publishing changes the conversation timestamp, so the message moves to the latest position.
            threadMessages.value = [...threadMessages.value.filter((item) => item.id !== threadId), published];
            fetchGeneration += 1;
            threadLoading.value = false;
            scrollCommentsToBottomSoon();
        } catch (error: any) {
            if (activeTaskId !== taskId) return;
            threadMessages.value = threadMessages.value.map((item) =>
                item.id === threadId ? { ...item, publishing: false, publishError: getThreadErrorMessage(error, 'Failed to publish draft') } : item,
            );
        }
    }

    async function handleThreadSend(payload: {
        html: string;
        attachments?: any[];
        mentions?: MentionIdentity[];
        addCollaborators?: MentionIdentity[];
    }) {
        if (!options.editTask.value) return;
        if (threadComposerUploading.value) return;
        if (threadSending.value || threadEditSaving.value) return;

        const html = payload?.html?.trim();
        if (!html) return;

        const taskId = options.getTaskId(options.editTask.value);
        activeTaskId = taskId;

        if (threadEditingId.value) {
            threadEditSaving.value = true;
            threadEditError.value = null;

            try {
                const thread = await options.updateThread(taskId, threadEditingId.value, {
                    html,
                    tempIdentifier: threadTempIdentifier.value,
                    audience: threadAudience.value,
                    mentions: payload.mentions ?? [],
                    addCollaborators: [],
                });
                const serverMessage = mapThreadToMessage<TaskAttachment>(thread);
                threadMessages.value = threadMessages.value.map((message) =>
                    message.id === threadEditingId.value
                        ? {
                              ...message,
                              content: serverMessage.content,
                              attachments: serverMessage.attachments,
                              mentions: serverMessage.mentions,
                          }
                        : message,
                );
                threadEditingId.value = null;
                threadAudience.value = 'all';
                threadAudienceError.value = null;
                threadTempIdentifier.value = Date.now().toString();
                threadComposerHtml.value = '';
                threadComposerRef.value?.reset?.();
                scrollCommentsToBottomSoon();
            } catch (error: any) {
                threadEditError.value = getThreadErrorMessage(error, 'Failed to update comment');
            } finally {
                threadEditSaving.value = false;
            }

            return;
        }

        const localId = `local-${Date.now()}-${++localMessageSequence}`;
        const createdAt = new Date().toISOString();
        const request: ThreadPayload = {
            html,
            tempIdentifier: threadTempIdentifier.value,
            clientRequestId: createThreadRequestId(),
            audience: threadAudience.value,
            mentions: payload.mentions ?? [],
            addCollaborators: payload.addCollaborators ?? [],
        };
        const optimistic: ThreadMessage = {
            clientId: localId,
            clientRequestId: request.clientRequestId,
            author: options.optimisticAuthor?.() || 'You',
            createdAt,
            time: 'Sending...',
            content: html,
            isYou: true,
            pending: true,
            failed: false,
            audience: request.audience,
            attachments: mapPendingThreadAttachments(payload.attachments ?? []),
        };
        pendingThreads.set(localId, { taskId, payload: request });
        if (request.clientRequestId) clientIdsByRequest.set(request.clientRequestId, localId);
        transientMessages.set(taskId, [...(transientMessages.get(taskId) ?? []), optimistic]);
        threadMessages.value = [...threadMessages.value, optimistic];
        refreshThreadSending();
        threadComposerRef.value?.reset?.();
        threadComposerHtml.value = '';
        threadTempIdentifier.value = `${Date.now()}-${localMessageSequence}`;
        threadAudience.value = 'all';
        threadAudienceError.value = null;
        scrollCommentsToBottomSoon();
        await createPendingThread(localId, taskId, request);
    }

    function startThreadEdit(message: ThreadMessage) {
        if (!options.editTask.value) return;
        if (!message.id || !message.isYou || message.pending) return;
        threadEditingId.value = message.id;
        threadEditError.value = null;
        threadAudience.value = message.audience;
        threadAudienceError.value = null;
        threadTempIdentifier.value = Date.now().toString();
        threadComposerHtml.value = message.content;
        void nextTick().then(() => {
            threadComposerRef.value?.editor?.chain().focus().run();
            scrollCommentsToBottomSoon();
        });
    }

    function startReplyToMessage(message: ThreadMessage) {
        if (!options.editTask.value) return;
        if (!message.id || message.pending) return;
        if (threadEditingId.value) {
            cancelThreadEdit();
        }

        threadEditError.value = null;
        if (message.audience === 'team') {
            threadAudience.value = 'team';
            threadAudienceError.value = null;
        }
        threadTempIdentifier.value = Date.now().toString();
        const quoteHtml = buildReplyQuoteHtml(message);
        const editor = threadComposerRef.value?.editor;

        if (editor) {
            const currentHtml = editor.getHTML();
            const hasContent = editor.getText().trim().length > 0 || currentHtml.replace(/<p><\/p>/g, '').trim().length > 0;
            if (hasContent) {
                editor.chain().focus('end').insertContent(quoteHtml).run();
            } else {
                editor.commands.setContent(quoteHtml, false);
            }
            threadComposerHtml.value = editor.getHTML();
        } else {
            threadComposerHtml.value = threadComposerHtml.value.trim() ? `${threadComposerHtml.value}${quoteHtml}` : quoteHtml;
        }

        void nextTick().then(() => {
            threadComposerRef.value?.editor?.chain().focus('end').run();
            scrollCommentsToBottomSoon();
        });
    }

    function cancelThreadEdit() {
        threadEditingId.value = null;
        threadAudience.value = 'all';
        threadAudienceError.value = null;
        threadComposerHtml.value = '';
        threadEditError.value = null;
        threadEditSaving.value = false;
        threadTempIdentifier.value = Date.now().toString();
        threadComposerRef.value?.reset?.();
        contextMenuMessageId.value = null;
        contextMenuSelectionText.value = '';
        threadMentionCandidates.value = [];
        threadMentionError.value = null;
    }

    function onMessageDblClick(message: ThreadMessage, event: MouseEvent) {
        if (shouldIgnoreEditGestureForEvent(event)) return;
        startThreadEdit(message);
    }

    function onMessageTouchEnd(message: ThreadMessage, event: TouchEvent) {
        if (shouldIgnoreEditGestureForEvent(event)) return;
        if (!message.isYou || !message.id || message.pending) return;
        const { isDoubleTap, nextTapState } = resolveTouchTap(message.id, {
            lastTapAt: lastTouchTapAt.value,
            lastTapId: lastTouchTapId.value,
        });
        lastTouchTapAt.value = nextTapState.lastTapAt;
        lastTouchTapId.value = nextTapState.lastTapId;
        if (isDoubleTap) {
            startThreadEdit(message);
        }
    }

    async function deleteThreadMessage(message: ThreadMessage): Promise<boolean> {
        if (!options.editTask.value) return false;
        if (!message.id || !message.isYou || message.pending) return false;

        try {
            await options.deleteThread(options.getTaskId(options.editTask.value), message.id);
            threadMessages.value = threadMessages.value.filter((threadMessage) => threadMessage.id !== message.id);
            if (threadEditingId.value === message.id) {
                cancelThreadEdit();
            }

            return true;
        } catch (error: any) {
            const messageText = getThreadErrorMessage(error, 'Failed to delete comment');
            if (options.onDeleteError) {
                options.onDeleteError(messageText);
            } else {
                threadError.value = messageText;
            }

            return false;
        }
    }

    return {
        cancelThreadEdit,
        commentsScrollRef,
        contextMenuMessageId,
        contextMenuSelectionText,
        copyEntireMessage,
        copySelectedMessage,
        deleteThreadMessage,
        fetchThreads,
        handleReplyReferenceClick,
        handleMentionQuery,
        handleSlashCommand,
        handleThreadSend,
        lastTouchTapAt,
        lastTouchTapId,
        lightboxAlt,
        lightboxOpen,
        lightboxSrc,
        onCommentContextMenuOpen,
        onCommentsMediaLoadCapture,
        onGlobalClickCapture,
        onGlobalDblClickCapture,
        onGlobalKeyDownCapture,
        onMessageDblClick,
        onMessageCopy,
        onMessageTouchEnd,
        onRichContentClick,
        resetThreadState,
        retryThreadSend,
        publishThreadMessage,
        scrollCommentsToBottomSoon,
        shouldShowCopySelection,
        startReplyToMessage,
        startThreadEdit,
        setThreadAudience,
        threadAudience,
        threadAudienceError,
        threadAiContext,
        threadComposerHtml,
        threadComposerRef,
        threadComposerUploading,
        threadEditError,
        threadEditSaving,
        threadError,
        threadLoading,
        threadMentionCandidates,
        threadMentionError,
        threadMentionLoading,
        threadMessages,
        threadSending,
        threadEditingId,
        threadTempIdentifier,
    };
}
