/* eslint-disable @typescript-eslint/no-unused-vars */
import Index from '@/pages/Organisations/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { fetchMock, getAccessForm, getCreateForm, getEditForm, makeProps, routerDeleteMock } from './test-helpers';

describe('Organisations/Index.vue', () => {
    it('creates a new organisation from the create dialog', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="create-organisation-trigger"]').trigger('click');
        await wrapper.get('[data-testid="create-organisation-name"]').setValue('Northwind');
        await wrapper.get('[data-testid="submit-create-organisation"]').trigger('click');

        const createForm = getCreateForm();

        expect(createForm.post).toHaveBeenCalledWith(
            '/organisations',
            expect.objectContaining({
                onSuccess: expect.any(Function),
                onError: expect.any(Function),
            }),
        );
        expect(createForm.reset).toHaveBeenCalled();
        expect(createForm.isActive).toBe(false);
    });

    it('opens the edit dialog with organisation values and saves changes', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="organisation-edit-1"]').trigger('click');

        const editInput = wrapper.get('[data-testid="edit-organisation-name"]');
        expect((editInput.element as HTMLInputElement).value).toBe('Acme Labs');

        await editInput.setValue('Acme Labs Updated');
        await wrapper.get('[data-testid="submit-edit-organisation"]').trigger('click');

        const editForm = getEditForm();

        expect(editForm.put).toHaveBeenCalledWith(
            '/organisations/1',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('adds a user from the manage access dialog', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                accessUsers: [{ id: 9, name: 'Existing Staff', email: 'existing@example.com' }],
            }),
        });

        await wrapper.get('[data-testid="organisation-manage-1"]').trigger('click');
        await flushPromises();

        const accessForm = getAccessForm();

        expect(accessForm.organisation_id).toBe(1);
        expect(accessForm.organisation_name).toBe('Acme Labs');

        await wrapper.get('[data-testid="organisation-access-email"]').setValue('staff@example.com');
        await wrapper.get('[data-testid="organisation-access-submit"]').trigger('click');

        expect(accessForm.post).toHaveBeenCalledWith(
            '/organisations/1/users',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
                onError: expect.any(Function),
            }),
        );
        expect(accessForm.email).toBe('');
        expect(accessForm.name).toBe('');
    });

    it('loads organisation users and removes access from the manage users dialog', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="organisation-manage-1"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/organisations/1/users');
        expect(wrapper.text()).toContain('Jane Admin');

        await wrapper.get('[data-testid="organisation-remove-access-20"]').trigger('click');
        await flushPromises();

        expect(routerDeleteMock).toHaveBeenCalledWith(
            '/organisations/1/users/20',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('deletes an organisation after confirmation', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="organisation-delete-1"]').trigger('click');
        await wrapper.get('[data-testid="delete-dialog-confirm"]').trigger('click');

        expect(routerDeleteMock).toHaveBeenCalledWith(
            '/organisations/1',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
    });
});
