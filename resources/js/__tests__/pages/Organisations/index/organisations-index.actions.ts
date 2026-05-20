import Index from '@/pages/Organisations/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosPatchMock, fetchMock, getAccessForm, getCreateForm, getEditForm, makeProps, routerDeleteMock } from './test-helpers';

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

    it('links owner edit actions to organisation settings', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        const editAction = wrapper.get('[data-testid="organisation-edit-1"]');

        expect(editAction.find('a[href="/organisation/1/settings"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-organisation-name"]').exists()).toBe(false);
    });

    it('hides owner-only organisation actions on shared rows', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        expect(wrapper.find('[data-testid="organisation-manage-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="organisation-edit-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="organisation-delete-2"]').exists()).toBe(false);
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

    it('renders the team screen from the organisation sidebar state', () => {
        const wrapper = mount(Index, {
            props: makeProps({
                panel: {
                    team: 1,
                    manage: null,
                    settings: null,
                    create: false,
                },
                panelOrganisation: {
                    id: 1,
                    name: 'Acme Labs',
                    projects: [
                        { id: 30, name: 'Portal Refresh' },
                        { id: 31, name: 'Billing Console' },
                    ],
                    teamUsers: [
                        {
                            id: 'owner-7',
                            name: 'Owner User',
                            email: 'owner@example.com',
                            status: 'owner',
                            statusLabel: 'Owner',
                            projectIds: [30, 31],
                        },
                        {
                            id: 'access-20',
                            organisationUserId: 20,
                            name: 'Jane Admin',
                            email: 'jane@example.com',
                            status: 'registered',
                            statusLabel: 'Registered',
                            projectIds: [30],
                        },
                    ],
                },
            }),
        });

        expect(wrapper.text()).toContain('Team');
        expect(wrapper.text()).toContain('Acme Labs');
        expect(wrapper.get('[data-testid="organisation-team-user-owner-7"]').text()).toContain('Owner User (owner@example.com)');
        expect(wrapper.get('[data-testid="organisation-team-user-owner-7"]').text()).toContain('Owner');
        expect(wrapper.get('[data-testid="organisation-team-user-access-20"]').text()).toContain('Jane Admin (jane@example.com)');
        expect(wrapper.find('[data-testid="organisation-team-edit-20"]').exists()).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('edits organisation team member project access and removes organisation access', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                panel: {
                    team: 1,
                    manage: null,
                    settings: null,
                    create: false,
                },
                panelOrganisation: {
                    id: 1,
                    name: 'Acme Labs',
                    projects: [
                        { id: 30, name: 'Portal Refresh' },
                        { id: 31, name: 'Billing Console' },
                    ],
                    teamUsers: [
                        {
                            id: 'owner-7',
                            name: 'Owner User',
                            email: 'owner@example.com',
                            status: 'owner',
                            statusLabel: 'Owner',
                            projectIds: [30, 31],
                        },
                        {
                            id: 'access-20',
                            organisationUserId: 20,
                            name: 'Jane Admin',
                            email: 'jane@example.com',
                            status: 'registered',
                            statusLabel: 'Registered',
                            projectIds: [30],
                        },
                    ],
                },
            }),
        });

        expect(wrapper.find('[data-testid="organisation-team-edit-undefined"]').exists()).toBe(false);
        await wrapper.get('[data-testid="organisation-team-edit-20"]').trigger('click');

        const billingCheckbox = wrapper.get('[data-testid="organisation-team-project-checkbox-31"]');
        expect((wrapper.get('[data-testid="organisation-team-project-checkbox-30"]').element as HTMLInputElement).checked).toBe(true);
        expect((billingCheckbox.element as HTMLInputElement).checked).toBe(false);

        await billingCheckbox.setValue(true);
        await wrapper.get('[data-testid="organisation-team-save-projects"]').trigger('click');
        await flushPromises();

        expect(axiosPatchMock).toHaveBeenCalledWith(
            '/organisations/1/users/20/projects',
            { project_ids: [30, 31] },
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );

        await wrapper.get('[data-testid="organisation-team-edit-20"]').trigger('click');
        await wrapper.get('[data-testid="organisation-team-remove-access"]').trigger('click');

        expect(routerDeleteMock).toHaveBeenCalledWith(
            '/organisations/1/users/20',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('renders the settings screen and saves or deletes the selected organisation', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                panel: {
                    manage: null,
                    settings: 1,
                    create: false,
                },
                panelOrganisation: {
                    id: 1,
                    name: 'Acme Labs',
                    projects: [],
                    teamUsers: [],
                },
            }),
        });

        const settingsInput = wrapper.get('[data-testid="settings-organisation-name"]');
        expect((settingsInput.element as HTMLInputElement).value).toBe('Acme Labs');

        await settingsInput.setValue('Acme Labs Updated');
        await wrapper.get('[data-testid="settings-save-organisation"]').trigger('click');

        const editForm = getEditForm();

        expect(editForm.put).toHaveBeenCalledWith(
            '/organisations/1',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );

        await wrapper.get('[data-testid="settings-delete-organisation"]').trigger('click');
        await wrapper.get('[data-testid="delete-dialog-confirm"]').trigger('click');

        expect(routerDeleteMock).toHaveBeenCalledWith(
            '/organisations/1',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
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
