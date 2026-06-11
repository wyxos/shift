import Index from '@/pages/Organisations/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axiosPatchMock, fetchMock, getAccessForm, getCreateForm, getEditForm, makeProps, routerDeleteMock, routerReloadMock } from './test-helpers';

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

    it('links organisation view actions to organisation dashboards', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        const ownerViewAction = wrapper.get('[data-testid="organisation-view-1"]');
        const sharedViewAction = wrapper.get('[data-testid="organisation-view-2"]');

        expect(ownerViewAction.find('a[href="/organisation/1/dashboard"]').exists()).toBe(true);
        expect(sharedViewAction.find('a[href="/organisation/2/dashboard"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="organisation-edit-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="organisation-delete-1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="edit-organisation-name"]').exists()).toBe(false);
    });

    it('hides owner-only organisation actions on shared rows', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        expect(wrapper.find('[data-testid="organisation-manage-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="organisation-edit-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="organisation-delete-2"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="organisation-view-2"]').exists()).toBe(true);
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
                            projectAccessCount: 2,
                            createdAt: '2026-02-01T10:00:00Z',
                            verifiedAt: '2026-02-02T10:00:00Z',
                            lastLoginAt: '2026-03-01T10:00:00Z',
                        },
                        {
                            id: 'access-20',
                            organisationUserId: 20,
                            name: 'Jane Admin',
                            email: 'jane@example.com',
                            status: 'registered',
                            statusLabel: 'Registered',
                            projectIds: [30],
                            projectAccessCount: 1,
                            createdAt: '2026-02-03T10:00:00Z',
                            verifiedAt: null,
                            lastLoginAt: null,
                        },
                    ],
                },
            }),
        });

        expect(wrapper.text()).toContain('Team');
        expect(wrapper.text()).toContain('Acme Labs');
        expect(wrapper.get('[data-testid="organisation-team-user-owner-7"]').text()).toContain('Owner User (owner@example.com)');
        expect(wrapper.get('[data-testid="organisation-team-user-owner-7"]').text()).toContain('Owner');
        expect(wrapper.get('[data-testid="organisation-team-project-count-owner-7"]').text()).toContain('2 projects');
        expect(wrapper.get('[data-testid="organisation-team-user-access-20"]').text()).toContain('Jane Admin (jane@example.com)');
        expect(wrapper.get('[data-testid="organisation-team-last-login-access-20"]').text()).toContain('Never');
        expect(wrapper.get('[data-testid="organisation-team-verified-access-20"]').text()).toContain('Unverified');
        expect(wrapper.get('[data-testid="organisation-team-project-count-access-20"]').text()).toContain('1 project');
        expect(wrapper.find('[data-testid="organisation-team-edit-20"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="organisation-team-remove-20"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="organisation-team-remove-undefined"]').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('opens organisation invite from the team screen and refreshes the panel after adding access', async () => {
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
                    projects: [{ id: 30, name: 'Portal Refresh' }],
                    teamUsers: [],
                },
            }),
        });

        await wrapper.get('[data-testid="organisation-team-invite"]').trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Invite to Organisation');

        const accessForm = getAccessForm();
        await wrapper.get('[data-testid="organisation-access-email"]').setValue('new@example.com');
        await wrapper.get('[data-testid="organisation-access-submit"]').trigger('click');

        expect(accessForm.post).toHaveBeenCalledWith(
            '/organisations/1/users',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
                onError: expect.any(Function),
            }),
        );
        expect(routerReloadMock).toHaveBeenCalledWith({
            only: ['panelOrganisation'],
            preserveScroll: true,
        });
        await flushPromises();
        expect(wrapper.find('[data-testid="organisation-access-submit"]').exists()).toBe(true);
    });

    it('edits organisation team member project access and removes organisation access after confirmation', async () => {
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

        await wrapper.get('[data-testid="organisation-team-remove-20"]').trigger('click');
        await wrapper.get('[data-testid="delete-dialog-confirm"]').trigger('click');

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

    it('does not expose destructive organisation deletion from the list row', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        expect(wrapper.find('[data-testid="organisation-delete-1"]').exists()).toBe(false);
        expect(routerDeleteMock).not.toHaveBeenCalled();
    });
});
