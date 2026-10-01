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

function mountThreadState(
    createThread: ReturnType<typeof vi.fn>,
    fetchThreads = vi.fn().mockResolvedValue([]),
    publishThread?: ReturnType<typeof vi.fn>,
) {
    return mount(
        defineComponent({
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
                        publishThread,
                        updateThread: vi.fn(),
                        deleteThread: vi.fn(),
                    }),
                };
            },
            template: '<div />',
        }),
    );
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

    it('reconciles a saved server message by request id after a lost response', async () => {
        const createThread = vi.fn().mockRejectedValue(new Error('Response lost'));
        const fetchThreads = vi.fn().mockResolvedValueOnce([]);
        const wrapper = mountThreadState(createThread, fetchThreads);
        const state = wrapper.vm as any;
        await state.fetchThreads(1);
        await state.handleThreadSend({ html: '<p>Hello</p>' });
        const failed = state.threadMessages[0];
        const requestId = createThread.mock.calls[0][1].clientRequestId;
        expect(failed.failed).toBe(true);

        fetchThreads.mockResolvedValueOnce([{ ...sentMessage, client_request_id: requestId }]);
        await state.fetchThreads(1);
        expect(state.threadMessages).toHaveLength(1);
        expect(state.threadMessages[0]).toMatchObject({ clientId: failed.clientId, id: 42 });
        expect(state.threadMessages[0].failed).toBeUndefined();
        state.retryThreadSend(failed);
        expect(createThread).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('keeps one bubble through repeated refreshes before a delayed send response', async () => {
        const request = deferred<unknown>();
        const createThread = vi.fn().mockReturnValue(request.promise);
        const fetchThreads = vi.fn().mockResolvedValueOnce([]);
        const wrapper = mountThreadState(createThread, fetchThreads);
        const state = wrapper.vm as any;
        await state.fetchThreads(1);
        const sending = state.handleThreadSend({ html: '<p>Hello</p>' });
        const clientId = state.threadMessages[0].clientId;
        const saved = { ...sentMessage, client_request_id: createThread.mock.calls[0][1].clientRequestId };
        fetchThreads.mockResolvedValue([saved]);

        await state.fetchThreads(1, { quiet: true });
        await state.fetchThreads(1, { quiet: true });
        expect(state.threadMessages).toHaveLength(1);
        expect(state.threadMessages[0]).toMatchObject({ clientId, id: 42 });

        request.resolve(saved);
        await sending;
        expect(state.threadMessages).toHaveLength(1);
        expect(state.threadMessages[0].clientId).toBe(clientId);
        wrapper.unmount();
    });

    it('removes a previously delivered local comment when a later refresh no longer returns it', async () => {
        const fetchThreads = vi.fn().mockResolvedValue([]);
        const wrapper = mountThreadState(vi.fn().mockResolvedValue(sentMessage), fetchThreads);
        const state = wrapper.vm as any;
        await state.fetchThreads(1);
        await state.handleThreadSend({ html: '<p>Hello</p>' });
        expect(state.threadMessages).toHaveLength(1);

        await state.fetchThreads(1, { quiet: true });
        expect(state.threadMessages).toEqual([]);
        wrapper.unmount();
    });

    it('marks author drafts and publishes them in the conversation, with retry after failure', async () => {
        const draft = { ...sentMessage, id: 41, is_draft: true, can_publish: true, created_at: '2026-09-30T10:00:00Z' };
        const publishThread = vi
            .fn()
            .mockRejectedValueOnce(new Error('Offline'))
            .mockResolvedValueOnce({
                ...sentMessage,
                id: 41,
                is_draft: false,
                can_publish: false,
            });
        const wrapper = mountThreadState(vi.fn(), vi.fn().mockResolvedValue([draft]), publishThread);
        const state = wrapper.vm as any;
        await state.fetchThreads(1);
        expect(state.threadMessages[0]).toMatchObject({ isDraft: true, canPublish: true });

        await state.publishThreadMessage(state.threadMessages[0]);
        expect(state.threadMessages[0]).toMatchObject({ isDraft: true, publishing: false, publishError: 'Offline' });
        await state.publishThreadMessage(state.threadMessages[0]);
        expect(publishThread).toHaveBeenCalledTimes(2);
        expect(publishThread).toHaveBeenCalledWith(1, 41);
        expect(state.threadMessages[0]).toMatchObject({ isDraft: false, canPublish: false, id: 41 });
        wrapper.unmount();
    });
});
