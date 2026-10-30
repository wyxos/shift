import { useProjectsPageState } from '@/composables/useProjectsPageState';
import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const mocks = vi.hoisted(() => ({ get: vi.fn(), fetch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { get: mocks.get },
    usePage: () => ({ url: '/projects', props: {} }),
    useForm: (value: Record<string, unknown>) => reactive({ ...value, errors: {}, processing: false, clearErrors: vi.fn() }),
}));

function state() {
    return useProjectsPageState({
        projects: { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null },
        accessUsers: [],
        clients: [],
        organisations: [],
        filters: {},
    });
}

beforeEach(() => {
    mocks.get.mockReset();
    mocks.fetch.mockReset();
    vi.stubGlobal('fetch', mocks.fetch);
});

afterEach(() => vi.unstubAllGlobals());

it('opens connected participants for a standalone project', () => {
    state().openProjectParticipants({ id: 3, name: 'Independent project' });
    expect(mocks.get).toHaveBeenCalledWith('/project-participants', { project_id: 3 });
});

it('discards access results from a previously selected project', async () => {
    let resolveFirst!: (value: unknown) => void;
    mocks.fetch.mockReturnValueOnce(
        new Promise((resolve) => {
            resolveFirst = resolve;
        }),
    );
    mocks.fetch.mockResolvedValueOnce({ ok: true, json: async () => [{ id: 20, user_name: 'Current member' }] });
    const page = state();
    const firstRequest = page.openManageUsersModal({ id: 1, name: 'First project' });
    await page.openManageUsersModal({ id: 2, name: 'Second project' });
    resolveFirst({ ok: true, json: async () => [{ id: 10, user_name: 'Outdated member' }] });
    await firstRequest;
    await flushPromises();
    expect(page.manageUsersForm.project_id).toBe(2);
    expect(page.manageUsersForm.users).toEqual([{ id: 20, user_name: 'Current member' }]);
});
