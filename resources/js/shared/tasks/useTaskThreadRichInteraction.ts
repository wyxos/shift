import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';
import {
    copyTextToClipboard,
    getLightboxImageData,
    getSelectionForMessage,
    shouldShowCopySelection as shouldShowCopySelectionForContext,
} from './interaction';
import { buildReplyQuoteHtml, escapeHtml, extractPlainTextFromContent } from './rich-content';
import { getReplyTargetFromEventTarget, shouldHandleImage } from './thread';
import type { ThreadMessage } from './types';

type RichInteractionOptions = {
    cancelThreadEdit: () => void;
    commentsScrollRef: Ref<HTMLElement | null>;
    editOpen: Ref<boolean>;
    threadEditingId: Ref<number | null>;
    onCopyMessageSuccess?: () => void;
    onCopyMessageError?: () => void;
    onCopySelectionSuccess?: () => void;
    onCopySelectionError?: () => void;
    onCopyTeamContent?: () => boolean;
};

export function useTaskThreadRichInteraction(options: RichInteractionOptions) {
    const contextMenuMessageId = ref<number | null>(null);
    const contextMenuSelectionText = ref('');
    const contextMenuAudience = ref<'all' | 'team'>('all');
    const contextMenuAuthor = ref('');
    const lightboxOpen = ref(false);
    const lightboxSrc = ref('');
    const lightboxAlt = ref('');

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

    function highlightReplyTargetBubble(target: HTMLElement) {
        target.classList.add('shift-reply-target');
        window.setTimeout(() => target.classList.remove('shift-reply-target'), 1800);
    }

    function scrollToReplyTarget(commentId: number): boolean {
        const withinComments = options.commentsScrollRef.value?.querySelector(`#comment-${commentId}`) as HTMLElement | null;
        const target = withinComments ?? (document.getElementById(`comment-${commentId}`) as HTMLElement | null);
        if (!target) return false;
        target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        highlightReplyTargetBubble(target);
        return true;
    }

    function handleReplyReferenceClick(target: HTMLElement, event: MouseEvent): boolean {
        if (!options.editOpen.value || target.closest('[contenteditable="true"]')) return false;
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
        if (!target || handleReplyReferenceClick(target, event)) return;
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
        if (!target || handleReplyReferenceClick(target, event)) return;
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
        if (!options.editOpen.value || !options.threadEditingId.value || event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        (event as any).stopImmediatePropagation?.();
        options.cancelThreadEdit();
    }

    function onCommentContextMenuOpen(message: ThreadMessage, open: boolean) {
        contextMenuMessageId.value = open ? (message.id ?? null) : null;
        contextMenuSelectionText.value = open ? getSelectionForMessage(message.id) : '';
        contextMenuAudience.value = open ? message.audience : 'all';
        contextMenuAuthor.value = open ? message.author : '';
    }

    function shouldShowCopySelection(message: ThreadMessage): boolean {
        return shouldShowCopySelectionForContext(message, contextMenuMessageId.value, contextMenuSelectionText.value);
    }

    async function copyEntireMessage(message: ThreadMessage) {
        if (message.audience === 'team' && options.onCopyTeamContent?.() === false) return;
        const copied = await copyTextToClipboard(
            extractPlainTextFromContent(message.content),
            message.audience === 'team' ? buildReplyQuoteHtml(message) : undefined,
        );
        if (copied) options.onCopyMessageSuccess?.();
        else options.onCopyMessageError?.();
    }

    async function copySelectedMessage() {
        if (contextMenuAudience.value === 'team' && options.onCopyTeamContent?.() === false) return;
        const copied = await copyTextToClipboard(
            contextMenuSelectionText.value,
            contextMenuAudience.value === 'team'
                ? buildReplyQuoteHtml({
                      id: contextMenuMessageId.value ?? undefined,
                      author: contextMenuAuthor.value,
                      content: escapeHtml(contextMenuSelectionText.value),
                  })
                : undefined,
        );
        if (copied) options.onCopySelectionSuccess?.();
        else options.onCopySelectionError?.();
    }

    function onMessageCopy(message: ThreadMessage, event: ClipboardEvent) {
        if (message.audience !== 'team' || !message.id) return;
        const selectedText = getSelectionForMessage(message.id);
        if (!selectedText) return;

        event.preventDefault();
        options.onCopyTeamContent?.();
        event.clipboardData?.setData('text/plain', selectedText);
        event.clipboardData?.setData(
            'text/html',
            buildReplyQuoteHtml({
                id: message.id,
                author: message.author,
                content: escapeHtml(selectedText),
            }),
        );
    }

    function resetRichInteractionState() {
        contextMenuMessageId.value = null;
        contextMenuSelectionText.value = '';
        contextMenuAudience.value = 'all';
        contextMenuAuthor.value = '';
        lightboxOpen.value = false;
        lightboxSrc.value = '';
        lightboxAlt.value = '';
    }

    return {
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
    };
}
