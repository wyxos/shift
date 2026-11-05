import { onBeforeUnmount, type Ref } from 'vue';

/** Track only the active message; offsets must follow its scrolling viewport. */
export function useTaskCommentQuickActions(container: Ref<HTMLElement | null>) {
    let hovered = false;
    let focused = false;
    let history: HTMLElement | null = null;
    let observer: ResizeObserver | undefined;

    function position() {
        const menu = container.value;
        const palette = menu?.querySelector<HTMLElement>('.task-comment-reaction-palette');
        if (!menu || !palette || !history) return;
        const message = menu.getBoundingClientRect();
        const viewport = history.getBoundingClientRect();
        const context = history.querySelector<HTMLElement>('[data-testid="task-report-context"]');
        const viewportTop = Math.max(viewport.top, context?.getBoundingClientRect().bottom ?? viewport.top);
        const height = palette.getBoundingClientRect().height;
        const gap = 4;
        const visibleTop = Math.max(message.top, viewportTop + gap);
        const visibleBottom = Math.min(message.bottom, viewport.bottom - gap);
        const visible = visibleBottom > visibleTop && viewport.bottom - viewportTop >= height + gap * 2;
        menu.style.setProperty('--quick-actions-visibility', visible ? 'visible' : 'hidden');
        if (!visible) return;

        // Prefer above the bubble; otherwise use its visible top edge, bounded
        // by the message and viewport so a stale offset never covers its middle.
        const above = message.top - height;
        const preferred = above >= viewportTop + gap ? above : message.height < height ? message.bottom : visibleTop;
        const top = Math.max(viewportTop + gap, Math.min(preferred, viewport.bottom - gap - height));
        menu.style.setProperty('--quick-actions-offset', `${top - message.top}px`);
    }

    function stop() {
        history?.removeEventListener('scroll', position);
        window.removeEventListener('resize', position);
        observer?.disconnect();
        observer = undefined;
        history = null;
    }

    function sync() {
        if (!hovered && !focused) {
            stop();
            return;
        }
        if (!history) {
            history = container.value?.closest<HTMLElement>('.task-comments-history') ?? null;
            history?.addEventListener('scroll', position, { passive: true });
            window.addEventListener('resize', position, { passive: true });
            if (typeof ResizeObserver !== 'undefined' && history && container.value) {
                observer = new ResizeObserver(position);
                observer.observe(history);
                observer.observe(container.value);
                const context = history.querySelector('[data-testid="task-report-context"]');
                if (context) observer.observe(context);
            }
        }
        position();
    }

    onBeforeUnmount(stop);
    return {
        onPointerEnter() {
            hovered = true;
            sync();
        },
        onPointerLeave() {
            hovered = false;
            sync();
        },
        onFocusIn() {
            focused = true;
            sync();
        },
        onFocusOut(event: FocusEvent) {
            focused = event.relatedTarget instanceof Node && !!container.value?.contains(event.relatedTarget);
            sync();
        },
    };
}
