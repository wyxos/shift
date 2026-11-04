import { nextTick, ref, watch, type Ref } from 'vue';

export function useTaskThreadViewport(editOpen: Ref<boolean>) {
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
    });

    return { commentsScrollRef, onCommentsMediaLoadCapture, scrollCommentsToBottomSoon };
}
