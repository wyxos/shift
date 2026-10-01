import { useTaskIndexThreadState } from '@/composables/useTaskIndexThreadState';
import type { TaskDetail } from '@/shared/tasks/types';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, defineComponent, ref } from 'vue';

const mocks = vi.hoisted(() => ({ useTaskThreadState: vi.fn() }));
vi.mock('@/shared/tasks/useTaskThreadState', () => ({ useTaskThreadState: mocks.useTaskThreadState }));

describe('open task conversation refresh', () => {
    let wrapper: VueWrapper | undefined;
    const editOpen = ref(true);
    const editTask = ref<TaskDetail | null>(null);
    const threadLoading = ref(false);
    const fetchThreads = vi.fn();

    function openConversation() {
        wrapper = mount(
            defineComponent({
                setup() {
                    useTaskIndexThreadState({ editOpen, editTask, aiImproveEnabled: computed(() => false) });
                    return () => null;
                },
            }),
        );
    }

    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
        editOpen.value = true;
        editTask.value = { id: 180 } as TaskDetail;
        threadLoading.value = false;
        fetchThreads.mockReset().mockResolvedValue(undefined);
        mocks.useTaskThreadState.mockReturnValue({ threadLoading, fetchThreads });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('quietly fetches new drafts while the conversation is open', async () => {
        openConversation();
        expect(fetchThreads).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetchThreads).toHaveBeenCalledExactlyOnceWith(180, { quiet: true });
    });

    it.each(['closed', 'missing task', 'loading', 'hidden'] as const)('does not refresh when %s', async (state) => {
        if (state === 'closed') editOpen.value = false;
        if (state === 'missing task') editTask.value = null;
        if (state === 'loading') threadLoading.value = true;
        if (state === 'hidden') vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
        openConversation();
        await vi.advanceTimersByTimeAsync(15000);
        expect(fetchThreads).not.toHaveBeenCalled();
    });

    it('waits for the previous refresh and then follows the active task', async () => {
        let complete!: () => void;
        fetchThreads.mockReturnValueOnce(new Promise<void>((resolve) => (complete = resolve)));
        openConversation();
        await vi.advanceTimersByTimeAsync(5000);
        editTask.value = { id: 28 } as TaskDetail;
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetchThreads).toHaveBeenCalledTimes(1);
        complete();
        await vi.advanceTimersByTimeAsync(5000);
        expect(fetchThreads).toHaveBeenLastCalledWith(28, { quiet: true });
        expect(fetchThreads).toHaveBeenCalledTimes(2);
    });

    it('stops fetching when the page unmounts', async () => {
        openConversation();
        wrapper?.unmount();
        wrapper = undefined;
        await vi.advanceTimersByTimeAsync(15000);
        expect(fetchThreads).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });
});
