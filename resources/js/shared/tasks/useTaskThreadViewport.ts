import { nextTick, ref, watch, type Ref } from 'vue';
import { highlightRichCodeBlocks } from './rich-content';
import type { ThreadMessage } from './types';

export function useTaskThreadViewport(editOpen: Ref<boolean>, threadMessages: Ref<ThreadMessage[]>) {
    const commentsScrollRef = ref<HTMLElement | null>(null);

    function scrollCommentsToBottom() {
        const element = commentsScrollRef.value;
        if (!element) return;
        if (typeof element.scrollTo === 'function') {
            element.scrollTo({ top: element.scrollHeight, behavior: 'auto' });
            return;
        }
        element.scrollTop = element.scrollHeight;
    }

    function scrollCommentsToBottomSoon() {
        void nextTick().then(scrollCommentsToBottom);
        const raf = globalThis.requestAnimationFrame ?? ((callback: FrameRequestCallback) => window.setTimeout(callback, 0));
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
        const element = commentsScrollRef.value;
        if ((tag === 'img' || tag === 'video') && element && element.scrollHeight - element.scrollTop - element.clientHeight < 100) {
            scrollCommentsToBottomSoon();
        }
    }

    watch(editOpen, (open) => {
        if (!open) return;
        scrollCommentsToBottomSoon();
        highlightCommentsSoon();
    });

    watch(
        () => threadMessages.value.map((message) => `${message.id ?? message.clientId}:${message.content}`).join('\n'),
        () => {
            if (editOpen.value) highlightCommentsSoon();
        },
    );

    return { commentsScrollRef, onCommentsMediaLoadCapture, scrollCommentsToBottomSoon };
}
