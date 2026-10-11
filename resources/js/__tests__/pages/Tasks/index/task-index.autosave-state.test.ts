import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, defineComponent, h } from 'vue';
import { axiosGetMock, axiosPatchMock, axiosPutMock } from './test-helpers';

type EditState = ReturnType<(typeof import('@/composables/useTaskIndexEditState'))['useTaskIndexEditState']>;

const task = {
    id: 1,
    project_id: 42,
    title: 'Original',
    priority: 'high',
    status: 'pending',
    requirement_status: 'submitted',
    description: '',
    environment: 'staging',
    can_edit_task: true,
    can_manage_collaborators: true,
    attachments: [],
    internal_collaborators: [],
    external_collaborators: [],
};

async function openedState() {
    const { useTaskIndexEditState } = await import('@/composables/useTaskIndexEditState');
    axiosGetMock.mockReset();
    axiosPutMock.mockReset();
    axiosPatchMock.mockReset();
    axiosGetMock.mockResolvedValueOnce({ data: task }).mockResolvedValue({ data: { data: [] } });
    let state!: EditState;
    const wrapper = mount(
        defineComponent({
            setup() {
                state = useTaskIndexEditState({ projects: [], aiImproveEnabled: computed(() => false), onTaskSaved: vi.fn() });
                return () => h('div');
            },
        }),
    );
    await state.openEdit(1, { updateHistory: false });
    await flushPromises();
    return { state, wrapper };
}

afterEach(() => {
    vi.useRealTimers();
});

describe('task edit autosave state', () => {
    it('keeps a failed edit in error until an explicit retry succeeds', async () => {
        vi.useFakeTimers();
        const { state, wrapper } = await openedState();
        expect(state.taskSaveState.value).toBe('idle');

        state.editForm.value.title = 'Revised';
        await flushPromises();
        expect(state.taskSaveState.value).toBe('pending');
        axiosPutMock.mockRejectedValueOnce(new Error('Connection lost'));
        await state.saveTaskChanges();

        expect(state.taskSaveState.value).toBe('error');
        expect(state.taskSaveError.value).toBe('Connection lost');
        expect(state.editForm.value.title).toBe('Revised');
        expect(axiosPutMock).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(800);
        await flushPromises();
        expect(axiosPutMock).toHaveBeenCalledTimes(1);

        axiosPutMock.mockResolvedValueOnce({ data: { task: { title: 'Revised' } } });
        await state.saveTaskChanges();
        expect(state.taskSaveState.value).toBe('saved');
        expect(state.taskSaveError.value).toBeNull();
        expect(axiosPutMock).toHaveBeenCalledTimes(2);

        state.closeEditNow(false);
        expect(state.taskSaveState.value).toBe('idle');
        wrapper.unmount();
    });

    it('preserves edits made during a request and queues their own snapshot', async () => {
        vi.useFakeTimers();
        const { state, wrapper } = await openedState();
        let resolveFirst!: (response: unknown) => void;
        let resolveSecond!: (response: unknown) => void;
        axiosPutMock
            .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
            .mockImplementationOnce(() => new Promise((resolve) => (resolveSecond = resolve)));

        state.editForm.value.title = 'First';
        const firstSave = state.saveTaskChanges();
        expect(state.taskSaveState.value).toBe('saving');
        state.editForm.value.title = 'Second';
        state.updateEditCollaborators({ internal: [{ id: 91, name: 'Jane', email: 'jane@example.com' }], external: [] });
        await state.saveTaskChanges();
        expect(axiosPutMock).toHaveBeenCalledTimes(1);

        resolveFirst({ data: { task: { title: 'First', internal_collaborators: [], external_collaborators: [] } } });
        await firstSave;
        await flushPromises();
        expect(state.editForm.value.title).toBe('Second');
        expect(state.editForm.value.collaborators.internal).toHaveLength(1);
        expect(state.editTask.value?.title).toBe('First');
        expect(state.taskSaveState.value).toBe('saving');
        expect(axiosPutMock).toHaveBeenCalledTimes(2);
        expect(axiosPutMock).toHaveBeenNthCalledWith(1, '/tasks.update', expect.objectContaining({ title: 'First' }));
        expect(axiosPutMock).toHaveBeenNthCalledWith(2, '/tasks.update', expect.objectContaining({ title: 'Second' }));

        axiosPatchMock.mockResolvedValueOnce({ data: { task: { internal_collaborators: [{ id: 91, name: 'Jane', email: 'jane@example.com' }] } } });
        resolveSecond({ data: { task: { title: 'Second' } } });
        await flushPromises();
        expect(axiosPatchMock).toHaveBeenCalledWith('/tasks.collaborators.update', expect.objectContaining({ internal_collaborator_ids: [91] }));
        expect(state.taskSaveState.value).toBe('saved');
        expect(state.editTask.value?.title).toBe('Second');
        wrapper.unmount();
    });

    it('keeps submitted collaborators when the core response contains the previous list', async () => {
        vi.useFakeTimers();
        const { state, wrapper } = await openedState();
        state.editForm.value.title = 'Combined edit';
        state.updateEditCollaborators({ internal: [{ id: 91, name: 'Jane', email: 'jane@example.com' }], external: [] });
        axiosPutMock.mockResolvedValueOnce({
            data: { task: { title: 'Combined edit', environment: 'staging', internal_collaborators: [], external_collaborators: [] } },
        });
        axiosPatchMock.mockResolvedValueOnce({
            data: { task: { environment: 'staging', internal_collaborators: [{ id: 91, name: 'Jane', email: 'jane@example.com' }] } },
        });

        await state.saveTaskChanges();

        expect(axiosPutMock).toHaveBeenCalledTimes(1);
        expect(axiosPatchMock).toHaveBeenCalledTimes(1);
        expect(axiosPatchMock).toHaveBeenCalledWith('/tasks.collaborators.update', expect.objectContaining({ internal_collaborator_ids: [91] }));
        expect(state.editForm.value.collaborators.internal.map((person) => person.id)).toEqual([91]);
        expect(state.taskSaveState.value).toBe('saved');
        wrapper.unmount();
    });

    it('clears a failed save when the form returns to its persisted value', async () => {
        vi.useFakeTimers();
        const { state, wrapper } = await openedState();
        state.editForm.value.title = 'Unsaved';
        axiosPutMock.mockRejectedValueOnce(new Error('Connection lost'));
        await state.saveTaskChanges();
        expect(state.taskSaveState.value).toBe('error');

        state.editForm.value.title = 'Original';
        await flushPromises();

        expect(state.taskSaveError.value).toBeNull();
        expect(state.taskSaveState.value).toBe('idle');
        expect(axiosPutMock).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('does not show a retry error if the form was restored before the request failed', async () => {
        vi.useFakeTimers();
        const { state, wrapper } = await openedState();
        let rejectSave!: (error: Error) => void;
        axiosPutMock.mockImplementationOnce(() => new Promise((_resolve, reject) => (rejectSave = reject)));
        state.editForm.value.title = 'Unsaved';
        const save = state.saveTaskChanges();
        state.editForm.value.title = 'Original';
        await flushPromises();
        rejectSave(new Error('Connection lost'));
        await save;
        expect(state.taskSaveState.value).toBe('idle');
        expect(state.hasUnsavedChanges.value).toBe(false);
        wrapper.unmount();
    });
});
