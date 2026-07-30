import { nextTick, ref, watch, type Ref } from 'vue';
import type { MentionIdentity } from '../components/shift-editor/types';
import { resolveTouchTap, shouldIgnoreEditGesture as shouldIgnoreEditGestureForEvent } from './interaction';
import { buildReplyQuoteHtml, highlightRichCodeBlocks } from './rich-content';
import { mapThreadToMessage } from './thread';
import type { TaskAttachment, ThreadMessage } from './types';
import { useTaskThreadAudienceState, type TaskThreadMentionCandidateFetcher } from './useTaskThreadAudienceState';
import { useTaskThreadRichInteraction } from './useTaskThreadRichInteraction';

type ThreadPayload = {
    html: string;
    tempIdentifier: string;
    audience: 'all' | 'team';
    mentions: MentionIdentity[];
    addCollaborators: MentionIdentity[];
};

type UseTaskThreadStateOptions<TTaskDetail> = {
    editOpen: Ref<boolean>;
    editTask: Ref<TTaskDetail | null>;
    getTaskId: (task: TTaskDetail) => number;
    fetchThreads: (taskId: number) => Promise<unknown[]>;
    createThread: (taskId: number, payload: ThreadPayload) => Promise<unknown>;
    updateThread: (taskId: number, threadId: number, payload: ThreadPayload) => Promise<unknown>;
    deleteThread: (taskId: number, threadId: number) => Promise<void>;
    fetchMentionCandidates?: TaskThreadMentionCandidateFetcher;
    optimisticAuthor?: () => string;
    onCopyMessageSuccess?: () => void;
    onCopyMessageError?: () => void;
    onCopySelectionSuccess?: () => void;
    onCopySelectionError?: () => void;
    onSendError?: (message: string) => void;
    onDeleteError?: (message: string) => void;
};

function getErrorMessage(error: any, fallback: string): string {
    return error?.response?.data?.error || error?.response?.data?.message || error?.message || fallback;
}

export function useTaskThreadState<TTaskDetail>(options: UseTaskThreadStateOptions<TTaskDetail>) {
    const threadTempIdentifier = ref(Date.now().toString());
    const threadLoading = ref(false);
    const threadSending = ref(false);
    const threadError = ref<string | null>(null);
    const threadMessages = ref<ThreadMessage[]>([]);
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
    const commentsScrollRef = ref<HTMLElement | null>(null);
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

    watch(options.editOpen, (open) => {
        if (!open) return;
        scrollCommentsToBottomSoon();
        highlightCommentsSoon();
    });

    watch(
        () => threadMessages.value.length,
        () => {
            if (!options.editOpen.value) return;
            scrollCommentsToBottomSoon();
        },
    );

    watch(
        () => threadMessages.value.map((message) => `${message.id ?? message.clientId}:${message.content}`).join('\n'),
        () => {
            if (!options.editOpen.value) return;
            highlightCommentsSoon();
        },
    );

    function resetThreadState() {
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

    function scrollCommentsToBottom() {
        const el = commentsScrollRef.value;
        if (!el) return;
        if (typeof (el as any).scrollTo === 'function') {
            (el as any).scrollTo({ top: el.scrollHeight, behavior: 'auto' });
            return;
        }
        el.scrollTop = el.scrollHeight;
    }

    function scrollCommentsToBottomSoon() {
        void nextTick().then(scrollCommentsToBottom);
        const raf = globalThis.requestAnimationFrame ?? ((cb: FrameRequestCallback) => window.setTimeout(cb, 0));
        raf(scrollCommentsToBottom);
        window.setTimeout(scrollCommentsToBottom, 50);
        window.setTimeout(scrollCommentsToBottom, 250);
    }

    function highlightCommentsSoon() {
        void nextTick().then(() => highlightRichCodeBlocks(commentsScrollRef.value));
    }

    function onCommentsMediaLoadCapture(event: Event) {
        const target = event.target as HTMLElement | null;
        if (!target) return;
        const tag = target.tagName?.toLowerCase();
        if (tag !== 'img' && tag !== 'video') return;
        scrollCommentsToBottomSoon();
    }

    async function fetchThreads(taskId: number) {
        threadLoading.value = true;
        threadError.value = null;
        try {
            const list = await options.fetchThreads(taskId);
            threadMessages.value = list.map((thread) => mapThreadToMessage<TaskAttachment>(thread));
            scrollCommentsToBottomSoon();
        } catch (error: any) {
            threadError.value = getErrorMessage(error, 'Failed to load comments');
        } finally {
            threadLoading.value = false;
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
                threadEditError.value = getErrorMessage(error, 'Failed to update comment');
            } finally {
                threadEditSaving.value = false;
            }

            return;
        }

        const localId = `local-${Date.now()}`;
        const optimistic: ThreadMessage = {
            clientId: localId,
            author: options.optimisticAuthor?.() || 'You',
            time: 'Sending...',
            content: html,
            isYou: true,
            pending: true,
            failed: false,
            audience: threadAudience.value,
        };
        threadMessages.value = [...threadMessages.value, optimistic];

        try {
            threadSending.value = true;
            const thread = await options.createThread(taskId, {
                html,
                tempIdentifier: threadTempIdentifier.value,
                audience: threadAudience.value,
                mentions: payload.mentions ?? [],
                addCollaborators: payload.addCollaborators ?? [],
            });
            const serverMessage = mapThreadToMessage<TaskAttachment>(thread);
            threadMessages.value = [...threadMessages.value.filter((message) => message.clientId !== localId), serverMessage];
            threadTempIdentifier.value = Date.now().toString();
            threadAudience.value = 'all';
            threadAudienceError.value = null;
            threadComposerHtml.value = '';
            threadComposerRef.value?.reset?.();
            scrollCommentsToBottomSoon();
        } catch (error: any) {
            const message = getErrorMessage(error, 'Failed to send comment');
            threadMessages.value = threadMessages.value.map((item) =>
                item.clientId === localId ? { ...item, pending: false, failed: true, time: 'Failed to send' } : item,
            );
            if (options.onSendError) {
                options.onSendError(message);
            } else {
                threadError.value = message;
            }
        } finally {
            threadSending.value = false;
        }
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
            const messageText = getErrorMessage(error, 'Failed to delete comment');
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
