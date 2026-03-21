import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, type ComputedRef, type Ref } from 'vue';
import { toast } from 'vue-sonner';
import { buildThreadAiContext } from '@/shared/tasks/ai';
import {
    copyTextToClipboard,
    getLightboxImageData,
    getSelectionForMessage as getSelectionForMessageText,
    resolveTouchTap,
    shouldIgnoreEditGesture as shouldIgnoreEditGestureForEvent,
    shouldShowCopySelection as shouldShowCopySelectionForContext,
} from '@/shared/tasks/interaction';
import { buildReplyQuoteHtml } from '@/shared/tasks/rich-content';
import { getReplyTargetFromEventTarget, mapThreadToMessage, shouldHandleImage } from '@/shared/tasks/thread';
import type { TaskAttachment, TaskDetail, ThreadMessage } from '@/shared/tasks/types';

type UseTaskIndexThreadStateOptions = {
    aiImproveEnabled: ComputedRef<boolean>;
    editOpen: Ref<boolean>;
    editTask: Ref<TaskDetail | null>;
};

export function useTaskIndexThreadState(options: UseTaskIndexThreadStateOptions) {
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
        const plainText = (() => {
            const el = document.createElement('div');
            el.innerHTML = message.content;
            return el.textContent?.trim() ?? message.content;
        })();
        const copied = await copyTextToClipboard(plainText);
        if (copied) {
            toast.success('Message copied');
            return;
        }
        toast.error('Unable to copy message');
    }

    async function copySelectedMessage() {
        const copied = await copyTextToClipboard(contextMenuSelectionText.value);
        if (copied) {
            toast.success('Selection copied');
            return;
        }
        toast.error('Unable to copy selection');
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

    async function handleThreadSend(payload: { html: string }) {
        if (!options.editTask.value) return;
        if (threadComposerUploading.value) return;
        if (threadSending.value || threadEditSaving.value) return;
        const html = payload?.html?.trim();
        if (!html) return;

        if (threadEditingId.value) {
            threadEditSaving.value = true;
            threadEditError.value = null;
            try {
                const response = await axios.put(route('task-threads.update', { task: options.editTask.value.id, thread: threadEditingId.value }), {
                    content: html,
                    temp_identifier: threadTempIdentifier.value,
                });
                const thread = response.data?.thread ?? response.data;
                const serverMsg = mapThreadToMessage<TaskAttachment>(thread);
                threadMessages.value = threadMessages.value.map((message) =>
                    message.id === threadEditingId.value ? { ...message, content: serverMsg.content, attachments: serverMsg.attachments } : message,
                );
                threadEditingId.value = null;
                threadTempIdentifier.value = Date.now().toString();
                threadComposerHtml.value = '';
                threadComposerRef.value?.reset?.();
                scrollCommentsToBottomSoon();
            } catch (e: any) {
                threadEditError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to update comment';
            } finally {
                threadEditSaving.value = false;
            }
            return;
        }

        const localId = `local-${Date.now()}`;
        const optimistic: ThreadMessage = {
            clientId: localId,
            author: 'You',
            time: 'Sending...',
            content: html,
            isYou: true,
            pending: true,
            failed: false,
        };
        threadMessages.value = [...threadMessages.value, optimistic];

        try {
            threadSending.value = true;
            const response = await axios.post(route('task-threads.store', { task: options.editTask.value.id }), {
                content: html,
                type: 'external',
                temp_identifier: threadTempIdentifier.value,
            });
            const thread = response.data?.thread ?? response.data;
            const serverMsg = mapThreadToMessage<TaskAttachment>(thread);
            threadMessages.value = [...threadMessages.value.filter((message) => message.clientId !== localId), serverMsg];
            threadTempIdentifier.value = Date.now().toString();
            threadComposerHtml.value = '';
            threadComposerRef.value?.reset?.();
            scrollCommentsToBottomSoon();
        } catch (e: any) {
            threadMessages.value = threadMessages.value.map((message) =>
                message.clientId === localId ? { ...message, pending: false, failed: true, time: 'Failed to send' } : message,
            );
            threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to send comment';
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
            await axios.delete(route('task-threads.destroy', { task: options.editTask.value.id, thread: message.id }));
            threadMessages.value = threadMessages.value.filter((threadMessage) => threadMessage.id !== message.id);
            if (threadEditingId.value === message.id) {
                cancelThreadEdit();
            }
        } catch (e: any) {
            threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to delete comment';
        }
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
            const response = await axios.get(route('task-threads.index', { task: taskId }));
            const list = Array.isArray(response.data?.external) ? response.data.external : [];
            threadMessages.value = list.map((thread) => mapThreadToMessage<TaskAttachment>(thread));
            scrollCommentsToBottomSoon();
        } catch (e: any) {
            threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to load comments';
        } finally {
            threadLoading.value = false;
        }
    }

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

    return {
        aiImproveEnabled: options.aiImproveEnabled,
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
