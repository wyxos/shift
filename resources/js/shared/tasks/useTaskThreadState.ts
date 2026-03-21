import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import { buildThreadAiContext } from './ai';
import {
    copyTextToClipboard,
    getLightboxImageData,
    getSelectionForMessage as getSelectionForMessageText,
    resolveTouchTap,
    shouldIgnoreEditGesture as shouldIgnoreEditGestureForEvent,
    shouldShowCopySelection as shouldShowCopySelectionForContext,
} from './interaction';
import { buildReplyQuoteHtml, extractPlainTextFromContent } from './rich-content';
import { getReplyTargetFromEventTarget, mapThreadToMessage, shouldHandleImage } from './thread';
import type { TaskAttachment, ThreadMessage } from './types';

type ThreadPayload = {
    html: string;
    tempIdentifier: string;
};

type UseTaskThreadStateOptions<TTaskDetail> = {
    editOpen: Ref<boolean>;
    editTask: Ref<TTaskDetail | null>;
    getTaskId: (task: TTaskDetail) => number;
    fetchThreads: (taskId: number) => Promise<unknown[]>;
    createThread: (taskId: number, payload: ThreadPayload) => Promise<unknown>;
    updateThread: (taskId: number, threadId: number, payload: ThreadPayload) => Promise<unknown>;
    deleteThread: (taskId: number, threadId: number) => Promise<void>;
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
    const threadAiContext = computed(() => buildThreadAiContext(threadMessages.value));
    const threadComposerRef = ref<any>(null);
    const threadComposerHtml = ref('');
    const threadComposerUploading = ref(false);
    const threadEditingId = ref<number | null>(null);
    const threadEditSaving = ref(false);
    const threadEditError = ref<string | null>(null);
    const contextMenuMessageId = ref<number | null>(null);
    const contextMenuSelectionText = ref('');
    const lastTouchTapAt = ref(0);
    const lastTouchTapId = ref<number | null>(null);
    const commentsScrollRef = ref<HTMLElement | null>(null);
    const lightboxOpen = ref(false);
    const lightboxSrc = ref('');
    const lightboxAlt = ref('');

    watch(options.editOpen, (open) => {
        if (!open) return;
        scrollCommentsToBottomSoon();
    });

    watch(
        () => threadMessages.value.length,
        () => {
            if (!options.editOpen.value) return;
            scrollCommentsToBottomSoon();
        },
    );

    onMounted(() => {
        document.addEventListener('click', onGlobalClickCapture, true);
        document.addEventListener('dblclick', onGlobalDblClickCapture, true);
        document.addEventListener('keydown', onGlobalKeyDownCapture, true);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('click', onGlobalClickCapture, true);
        document.removeEventListener('dblclick', onGlobalDblClickCapture, true);
        document.removeEventListener('keydown', onGlobalKeyDownCapture, true);
    });

    function resetThreadState() {
        threadTempIdentifier.value = Date.now().toString();
        threadLoading.value = false;
        threadSending.value = false;
        threadError.value = null;
        threadMessages.value = [];
        threadComposerHtml.value = '';
        threadComposerUploading.value = false;
        threadEditingId.value = null;
        threadEditSaving.value = false;
        threadEditError.value = null;
        contextMenuMessageId.value = null;
        contextMenuSelectionText.value = '';
        lastTouchTapAt.value = 0;
        lastTouchTapId.value = null;
        lightboxOpen.value = false;
        lightboxSrc.value = '';
        lightboxAlt.value = '';
    }

    function highlightReplyTargetBubble(target: HTMLElement) {
        target.classList.add('shift-reply-target');
        window.setTimeout(() => {
            target.classList.remove('shift-reply-target');
        }, 1800);
    }

    function scrollToReplyTarget(commentId: number): boolean {
        const selector = `#comment-${commentId}`;
        const withinComments = commentsScrollRef.value?.querySelector(selector) as HTMLElement | null;
        const target = withinComments ?? (document.getElementById(`comment-${commentId}`) as HTMLElement | null);
        if (!target) return false;
        target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        highlightReplyTargetBubble(target);
        return true;
    }

    function handleReplyReferenceClick(target: HTMLElement, event: MouseEvent): boolean {
        if (!options.editOpen.value) return false;
        if (target.closest('[contenteditable="true"]')) return false;
        const commentId = getReplyTargetFromEventTarget(target);
        if (!commentId) return false;
        event.preventDefault();
        event.stopPropagation();
        return scrollToReplyTarget(commentId);
    }

    function openLightboxForImage(img: HTMLImageElement) {
        const data = getLightboxImageData(img);
        if (!data) return;
        lightboxSrc.value = data.src;
        lightboxAlt.value = data.alt;
        lightboxOpen.value = true;
    }

    function onRichContentClick(event: MouseEvent) {
        const target = event.target as HTMLElement | null;
        if (!target) return;
        if (handleReplyReferenceClick(target, event)) return;
        const img = target.closest('img') as HTMLImageElement | null;
        if (!img) return;
        const inRich = Boolean(img.closest('.shift-rich')) || Boolean(img.closest('.tiptap')) || img.classList.contains('editor-tile');
        if (!inRich) return;
        event.preventDefault();
        event.stopPropagation();
        openLightboxForImage(img);
    }

    function onGlobalClickCapture(event: MouseEvent) {
        if (!options.editOpen.value) return;
        const target = event.target as HTMLElement | null;
        if (!target) return;
        if (handleReplyReferenceClick(target, event)) return;
        const img = target.closest('img') as HTMLImageElement | null;
        if (!img) return;
        const { ok, inEditable } = shouldHandleImage(img);
        if (!ok || inEditable) return;
        event.preventDefault();
        event.stopPropagation();
        openLightboxForImage(img);
    }

    function onGlobalDblClickCapture(event: MouseEvent) {
        if (!options.editOpen.value) return;
        const target = event.target as HTMLElement | null;
        if (!target) return;
        const img = target.closest('img') as HTMLImageElement | null;
        if (!img) return;
        const { ok, inEditable } = shouldHandleImage(img);
        if (!ok || !inEditable) return;
        event.preventDefault();
        event.stopPropagation();
        openLightboxForImage(img);
    }

    function onGlobalKeyDownCapture(event: KeyboardEvent) {
        if (!options.editOpen.value) return;
        if (!threadEditingId.value) return;
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        (event as any).stopImmediatePropagation?.();
        cancelThreadEdit();
    }

    function onCommentContextMenuOpen(message: ThreadMessage, open: boolean) {
        if (!open) {
            contextMenuMessageId.value = null;
            contextMenuSelectionText.value = '';
            return;
        }
        contextMenuMessageId.value = message.id ?? null;
        contextMenuSelectionText.value = getSelectionForMessageText(message.id);
    }

    function shouldShowCopySelection(message: ThreadMessage): boolean {
        return shouldShowCopySelectionForContext(message, contextMenuMessageId.value, contextMenuSelectionText.value);
    }

    async function copyEntireMessage(message: ThreadMessage) {
        const copied = await copyTextToClipboard(extractPlainTextFromContent(message.content));
        if (copied) {
            options.onCopyMessageSuccess?.();
            return;
        }
        options.onCopyMessageError?.();
    }

    async function copySelectedMessage() {
        const copied = await copyTextToClipboard(contextMenuSelectionText.value);
        if (copied) {
            options.onCopySelectionSuccess?.();
            return;
        }
        options.onCopySelectionError?.();
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

    async function handleThreadSend(payload: { html: string; attachments?: any[] }) {
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
                });
                const serverMessage = mapThreadToMessage<TaskAttachment>(thread);
                threadMessages.value = threadMessages.value.map((message) =>
                    message.id === threadEditingId.value
                        ? { ...message, content: serverMessage.content, attachments: serverMessage.attachments }
                        : message,
                );
                threadEditingId.value = null;
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
        };
        threadMessages.value = [...threadMessages.value, optimistic];

        try {
            threadSending.value = true;
            const thread = await options.createThread(taskId, {
                html,
                tempIdentifier: threadTempIdentifier.value,
            });
            const serverMessage = mapThreadToMessage<TaskAttachment>(thread);
            threadMessages.value = [...threadMessages.value.filter((message) => message.clientId !== localId), serverMessage];
            threadTempIdentifier.value = Date.now().toString();
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
        threadComposerHtml.value = '';
        threadEditError.value = null;
        threadEditSaving.value = false;
        threadTempIdentifier.value = Date.now().toString();
        threadComposerRef.value?.reset?.();
        contextMenuMessageId.value = null;
        contextMenuSelectionText.value = '';
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

    async function deleteThreadMessage(message: ThreadMessage) {
        if (!options.editTask.value) return;
        if (!message.id || !message.isYou || message.pending) return;
        if (!confirm('Delete this message?')) return;

        try {
            await options.deleteThread(options.getTaskId(options.editTask.value), message.id);
            threadMessages.value = threadMessages.value.filter((threadMessage) => threadMessage.id !== message.id);
            if (threadEditingId.value === message.id) {
                cancelThreadEdit();
            }
        } catch (error: any) {
            const messageText = getErrorMessage(error, 'Failed to delete comment');
            if (options.onDeleteError) {
                options.onDeleteError(messageText);
            } else {
                threadError.value = messageText;
            }
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
        onMessageTouchEnd,
        onRichContentClick,
        resetThreadState,
        scrollCommentsToBottomSoon,
        shouldShowCopySelection,
        startReplyToMessage,
        startThreadEdit,
        threadAiContext,
        threadComposerHtml,
        threadComposerRef,
        threadComposerUploading,
        threadEditError,
        threadEditSaving,
        threadError,
        threadLoading,
        threadMessages,
        threadSending,
        threadEditingId,
        threadTempIdentifier,
    };
}
