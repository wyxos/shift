import { useProjectCreateState } from '@/composables/useProjectCreateState';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

function makeCreateForm() {
    const submissions: Array<{ client_id: number | null; organisation_id: number | null }> = [];
    const initial = {
        name: '',
        client_id: null,
        organisation_id: null,
        isActive: false,
        processing: false,
        errors: {},
    };
    const form = reactive({
        ...initial,
        post: vi.fn((_url: string, options?: { onSuccess?: () => void }) => {
            submissions.push({
                client_id: form.client_id,
                organisation_id: form.organisation_id,
            });
            options?.onSuccess?.();
        }),
        reset: vi.fn(() => {
            Object.assign(form, initial);
        }),
    });

    return { form, submissions };
}

describe('useProjectCreateState', () => {
    it('submits the active organisation when no client is selected', () => {
        const { form, submissions } = makeCreateForm();
        const state = useProjectCreateState(
            {
                currentOrganisation: { id: 3, name: 'Atlas Commerce' },
                filters: { organisation_id: 3 },
                organisations: [{ id: 3, name: 'Atlas Commerce' }],
            },
            form,
        );

        state.openCreateModal();
        form.name = 'Atlas Checkout';
        state.submitCreateForm();

        expect(submissions).toEqual([{ client_id: null, organisation_id: 3 }]);
        expect(form.post).toHaveBeenCalledWith('/projects', expect.objectContaining({ preserveScroll: true }));
        expect(form.isActive).toBe(false);
    });

    it('submits the selected client without also setting organisation_id', () => {
        const { form, submissions } = makeCreateForm();
        const state = useProjectCreateState(
            {
                currentOrganisation: { id: 3, name: 'Atlas Commerce' },
                filters: { organisation_id: 3 },
                organisations: [{ id: 3, name: 'Atlas Commerce' }],
            },
            form,
        );

        state.openCreateModal();
        form.name = 'Atlas Storefront';
        form.client_id = 12;
        state.submitCreateForm();

        expect(submissions).toEqual([{ client_id: 12, organisation_id: null }]);
    });
});
