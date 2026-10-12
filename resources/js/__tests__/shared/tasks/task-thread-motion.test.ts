import { useTaskThreadRichInteraction } from '@/shared/tasks/useTaskThreadRichInteraction';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllTimers();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('reply navigation motion', () => {
    it.each([true, false])('respects reduced motion %s while retaining the reply highlight', (reduced) => {
        vi.useFakeTimers();
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({ matches: reduced })),
        );
        const history = document.createElement('div');
        history.innerHTML = '<div id="comment-7">Original comment</div><a href="#comment-7">Reply</a>';
        document.body.append(history);
        const target = history.querySelector<HTMLElement>('#comment-7')!;
        target.scrollIntoView = vi.fn();
        const wrapper = mount(
            defineComponent({
                setup() {
                    const interaction = useTaskThreadRichInteraction({
                        cancelThreadEdit: vi.fn(),
                        commentsScrollRef: ref(history),
                        editOpen: ref(true),
                        threadEditingId: ref(null),
                    });
                    return { interaction };
                },
                template: '<div />',
            }),
        );
        const anchor = history.querySelector('a')!;
        const event = new MouseEvent('click', { cancelable: true });
        expect(wrapper.vm.interaction.handleReplyReferenceClick(anchor, event)).toBe(true);
        expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: reduced ? 'instant' : 'smooth', block: 'center', inline: 'nearest' });
        expect(target.classList.contains('shift-reply-target')).toBe(true);
        expect(event.defaultPrevented).toBe(true);
        wrapper.unmount();
    });
});
