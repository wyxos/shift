import { useProjectAppErrorNotificationsState } from '@/composables/useProjectAppErrorNotificationsState';
import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    axiosGet: vi.fn(),
    axiosPut: vi.fn(),
    routerReload: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        reload: mocks.routerReload,
    },
}));

vi.mock('axios', () => ({
    default: {
        get: mocks.axiosGet,
        put: mocks.axiosPut,
    },
}));

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

describe('useProjectAppErrorNotificationsState', () => {
    beforeEach(() => {
        mocks.axiosGet.mockReset();
        mocks.axiosPut.mockReset();
        mocks.routerReload.mockReset();
        mocks.axiosPut.mockResolvedValue({ data: {} });
    });

    it('ignores stale recipient loads from a previously opened project', async () => {
        const firstLoad = deferred<{
            data: { project_id: number; selected_user_ids: number[]; users: Array<{ id: number; name: string; email: string }> };
        }>();
        const secondLoad = deferred<{
            data: { project_id: number; selected_user_ids: number[]; users: Array<{ id: number; name: string; email: string }> };
        }>();
        mocks.axiosGet.mockReturnValueOnce(firstLoad.promise).mockReturnValueOnce(secondLoad.promise);

        const state = useProjectAppErrorNotificationsState();

        const firstOpen = state.openAppErrorNotificationsModal({ id: 1, name: 'First Project' } as any);
        const secondOpen = state.openAppErrorNotificationsModal({ id: 2, name: 'Second Project' } as any);

        secondLoad.resolve({
            data: {
                project_id: 2,
                selected_user_ids: [8],
                users: [{ id: 8, name: 'Second User', email: 'second@example.com' }],
            },
        });
        await flushPromises();

        firstLoad.resolve({
            data: {
                project_id: 1,
                selected_user_ids: [7],
                users: [{ id: 7, name: 'First User', email: 'first@example.com' }],
            },
        });
        await Promise.allSettled([firstOpen, secondOpen]);
        await flushPromises();

        expect(state.appErrorNotificationsForm.value.project_id).toBe(2);
        expect(state.appErrorNotificationsForm.value.selected_user_ids).toEqual([8]);
        expect(state.appErrorNotificationsForm.value.users).toEqual([{ id: 8, name: 'Second User', email: 'second@example.com' }]);
    });

    it('does not save an empty recipient list after settings fail to load', async () => {
        mocks.axiosGet.mockRejectedValueOnce(new Error('Load failed'));

        const state = useProjectAppErrorNotificationsState();

        await state.openAppErrorNotificationsModal({ id: 1, name: 'First Project' } as any);
        await flushPromises();
        await state.saveAppErrorNotifications();

        expect(mocks.axiosPut).not.toHaveBeenCalled();
        expect(mocks.routerReload).not.toHaveBeenCalled();
    });
});
