import { useTaskThreadState } from '@/shared/tasks/useTaskThreadState';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: Error) => void;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

function mountThreadState(createThread: ReturnType<typeof vi.fn>, fetchThreads = vi.fn().mockResolvedValue([])) {
    return mount(defineComponent({
        setup() {
            const editTask = ref({ id: 1 });
            return {
                editTask,
                ...useTaskThreadState({
                    editOpen: ref(true),
                    editTask,
                    getTaskId: (task) => task.id,
                    fetchThreads,
                    createThread,
                    updateThread: vi.fn(),
                    deleteThread: vi.fn(),
                }),
            };
        },
        template: '<div />',
    }));
}

const sentMessage = {
    id: 42,
    sender_name: 'You',
    is_current_user: true,
    content: '<p>Hello</p>',
    created_at: '2026-10-01T10:00:00Z',
};

describe('optimistic task comments', () => {
    it('clears the composer immediately and replaces its sending bubble in place', async () => {
        const request = deferred<unknown>();
        const createThread = vi.fn().mockReturnValue(request.promise);
        const wrapper = mountThreadState(createThread);
        const state = wrapper.vm as any;
        state.threadComposerHtml = '<p>Hello</p>';
        state.threadComposerRef = { reset: vi.fn() };

        const sending = state.handleThreadSend({ html: '<p>Hello</p>', attachments: [{ name: 'report.pdf', path: 'temp/report.pdf' }] });
        const clientId = state.threadMessages[0].clientId;
        expect(state.threadComposerHtml).toBe('');
        expect(state.threadComposerRef.reset).toHaveBeenCalledOnce();
        expect(state.threadMessages[0]).toMatchObject({ clientId, pending: true, content: '<p>Hello</p>' });
        expect(state.threadMessages[0].attachments[0].original_filename).toBe('report.pdf');
        expect(createThread.mock.calls[0][1].clientRequestId).toMatch(/^[0-9a-f-]{36}$/i);

        request.resolve(sentMessage);
        await sending;
        expect(state.threadMessages[0]).toMatchObject({ clientId, id: 42, content: '<p>Hello</p>' });
        expect(state.threadMessages[0].pending).toBeUndefined();
        wrapper.unmount();
    });

    it('keeps failed content and retries the same request once', async () => {
        const retry = deferred<unknown>();
        const createThread = vi.fn().mockRejectedValueOnce(new Error('Offline')).mockReturnValueOnce(retry.promise);
        const wrapper = mountThreadState(createThread);
        const state = wrapper.vm as any;

        await state.handleThreadSend({ html: '<p>Hello</p>', mentions: [{ kind: 'internal', id: 7 }] });
        const failed = state.threadMessages[0];
        expect(failed).toMatchObject({ failed: true, pending: false, content: '<p>Hello</p>' });
        expect(state.threadError).toBeNull();

        state.retryThreadSend(failed);
        state.retryThreadSend(state.threadMessages[0]);
        expect(createThread).toHaveBeenCalledTimes(2);
        expect(createThread.mock.calls[1][1]).toEqual(createThread.mock.calls[0][1]);
        expect(state.threadMessages[0]).toMatchObject({ clientId: failed.clientId, pending: true });

        retry.resolve(sentMessage);
        await flushPromises();
        expect(state.threadMessages[0]).toMatchObject({ clientId: failed.clientId, id: 42 });
        wrapper.unmount();
    });

    it('preserves an in-flight comment across task resets and stale fetches', async () => {
        const request = deferred<unknown>();
        const oldFetch = deferred<unknown[]>();
        const fetchThreads = vi.fn().mockReturnValueOnce(oldFetch.promise).mockResolvedValue([]);
        const wrapper = mountThreadState(vi.fn().mockReturnValue(request.promise), fetchThreads);
        const state = wrapper.vm as any;
        const staleFetch = state.fetchThreads(1);
        const sending = state.handleThreadSend({ html: '<p>Hello</p>' });
        const clientId = state.threadMessages[0].clientId;

        state.resetThreadState();
        state.editTask = { id: 2 };
        await state.fetchThreads(2);
        oldFetch.resolve([sentMessage]);
        await staleFetch;
        expect(state.threadMessages).toEqual([]);

        state.resetThreadState();
        state.editTask = { id: 1 };
        await state.fetchThreads(1);
        expect(state.threadMessages[0]).toMatchObject({ clientId, pending: true });

        request.resolve(sentMessage);
        await sending;
        expect(state.threadMessages[0]).toMatchObject({ clientId, id: 42 });
        wrapper.unmount();
    });

    it('keeps a newly delivered comment when an older fetch resolves afterward', async () => {
        const staleFetch = deferred<unknown[]>();
        const wrapper = mountThreadState(vi.fn().mockResolvedValue(sentMessage), vi.fn().mockReturnValue(staleFetch.promise));
        const state = wrapper.vm as any;
        const loading = state.fetchThreads(1);

        await state.handleThreadSend({ html: '<p>Hello</p>' });
        const clientId = state.threadMessages[0].clientId;
        staleFetch.resolve([]);
        await loading;

        expect(state.threadMessages).toHaveLength(1);
        expect(state.threadMessages[0]).toMatchObject({ clientId, id: 42 });
        wrapper.unmount();
    });
});
