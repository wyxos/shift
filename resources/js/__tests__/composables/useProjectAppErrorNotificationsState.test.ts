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
    it('loads alert rules and preserves all edited values after a failed save', async () => {
        const settings = {
            recurrence_enabled: true,
            quiet_hours: 3,
            daily_summary_enabled: true,
            daily_summary_time: '10:15',
            daily_summary_timezone: 'Asia/Dubai',
        };
        mocks.axiosGet.mockResolvedValue({ data: { selected_user_ids: [7], users: [], settings } });
        mocks.axiosPut.mockRejectedValueOnce({ response: { data: { errors: { 'settings.quiet_hours': ['Choose a valid alert cooldown.'] } } } });
        const state = useProjectAppErrorNotificationsState();
        await state.openAppErrorNotificationsModal({ id: 1, name: 'Project' } as any);
        state.appErrorNotificationsForm.value.settings.quiet_hours = 4;
        await state.saveAppErrorNotifications();
        expect(state.appErrorNotificationsOpen.value).toBe(true);
        expect(state.appErrorNotificationsForm.value.settings.quiet_hours).toBe(4);
        expect(state.appErrorNotificationsForm.value.selected_user_ids).toEqual([7]);
        expect(state.appErrorNotificationsError.value).toBe('Choose a valid alert cooldown.');
        await state.saveAppErrorNotifications();
        expect(mocks.axiosPut).toHaveBeenLastCalledWith(
            '/projects/1/app-error-notifications',
            { user_ids: [7], settings: { ...settings, quiet_hours: 4 } },
            expect.anything(),
        );
        expect(state.appErrorNotificationsOpen.value).toBe(false);
    });

    it('does not let an older save close or overwrite a newly opened project', async () => {
        const pendingSave = deferred<{ data: Record<string, never> }>();
        mocks.axiosGet.mockResolvedValue({ data: { selected_user_ids: [], users: [] } });
        const state = useProjectAppErrorNotificationsState();
        await state.openAppErrorNotificationsModal({ id: 1, name: 'First' } as any);
        mocks.axiosPut.mockReturnValueOnce(pendingSave.promise);
        const save = state.saveAppErrorNotifications();
        await state.openAppErrorNotificationsModal({ id: 2, name: 'Second' } as any);
        pendingSave.resolve({ data: {} });
        await save;
        expect(state.appErrorNotificationsOpen.value).toBe(true);
        expect(state.appErrorNotificationsForm.value.project_id).toBe(2);
        expect(mocks.routerReload).not.toHaveBeenCalled();
    });

    it('keeps the settings visible when scheduling fails after persistence', async () => {
        mocks.axiosGet.mockResolvedValue({ data: { selected_user_ids: [], users: [] } });
        mocks.axiosPut.mockResolvedValueOnce({ data: { scheduling_warning: 'Settings saved; retry scheduling.' } });
        const state = useProjectAppErrorNotificationsState();
        await state.openAppErrorNotificationsModal({ id: 1, name: 'Project' } as any);
        await state.saveAppErrorNotifications();
        expect(state.appErrorNotificationsOpen.value).toBe(true);
        expect(state.appErrorNotificationsLoading.value).toBe(false);
        expect(state.appErrorNotificationsError.value).toBe('Settings saved; retry scheduling.');
    });

    it('rejects malformed remote rules and prevents saving unconfirmed defaults', async () => {
        mocks.axiosGet.mockResolvedValue({ data: { selected_user_ids: [], users: [], settings: { quiet_hours: '1' } } });
        const state = useProjectAppErrorNotificationsState();
        await state.openAppErrorNotificationsModal({ id: 1, name: 'Project' } as any);
        await state.saveAppErrorNotifications();
        expect(state.appErrorNotificationsLoaded.value).toBe(false);
        expect(mocks.axiosPut).not.toHaveBeenCalled();
    });
});
