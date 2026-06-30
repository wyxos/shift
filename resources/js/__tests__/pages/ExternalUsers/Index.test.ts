import ExternalUsersIndex from '@/pages/ExternalUsers/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const routerGetMock = vi.fn();
const routerVisitMock = vi.fn();
const routerReloadMock = vi.fn();
const axiosPutMock = vi.fn();
const axiosPostMock = vi.fn();
const axiosDeleteMock = vi.fn();

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/admin/AdminListShell.vue', () => ({
    default: {
        props: ['title', 'description', 'page', 'activeFilterCount', 'filtersOpen'],
        emits: ['update:filtersOpen', 'page-change'],
        render() {
            return h('div', { class: 'admin-list-shell-stub' }, [
                h('div', { class: 'title' }, this.title),
                h('button', { type: 'button', 'data-testid': 'emit-page-change', onClick: () => this.$emit('page-change', 2) }, 'page 2'),
                h('div', { class: 'filters-slot' }, this.$slots.filters?.()),
                h('div', { class: 'filter-actions-slot' }, this.$slots['filter-actions']?.()),
                h('div', { class: 'default-slot' }, this.$slots.default?.()),
            ]);
        },
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        render() {
            return h('input', {
                ...this.$attrs,
                value: this.modelValue,
                placeholder: this.placeholder,
                onInput: (event: Event) => this.$emit('update:modelValue', (event.target as HTMLInputElement).value),
            });
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'size'],
        emits: ['click'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    disabled: this.disabled,
                    onClick: (event: MouseEvent) => this.$emit('click', event),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/button-group', () => ({
    ButtonGroup: {
        props: ['modelValue', 'options', 'testIdPrefix'],
        emits: ['update:modelValue'],
        render() {
            return h(
                'div',
                { class: 'button-group-stub' },
                (this.options ?? []).map((option: { value: string; label: string }) =>
                    h(
                        'button',
                        {
                            type: 'button',
                            'data-testid': this.testIdPrefix ? `${this.testIdPrefix}-${option.value}` : undefined,
                            onClick: () => this.$emit('update:modelValue', option.value),
                        },
                        option.label,
                    ),
                ),
            );
        },
    },
}));

vi.mock('@/components/ui/select', () => ({
    Select: {
        props: ['modelValue', 'options', 'placeholder', 'testId'],
        emits: ['update:modelValue'],
        render() {
            const options = Array.isArray((this as any).options) ? (this as any).options : [];

            return h(
                'select',
                {
                    'data-testid': (this as any).testId,
                    value: (this as any).modelValue ?? '',
                    onChange: (event: Event) => {
                        const value = (event.target as HTMLSelectElement).value;
                        const option = options.find((item: any) => String(item.value ?? '') === value);

                        (this as any).$emit('update:modelValue', option ? option.value : value || '');
                    },
                },
                [
                    (this as any).placeholder ? h('option', { value: '' }, (this as any).placeholder) : null,
                    ...options.map((option: any) => h('option', { value: option.value ?? '' }, option.label)),
                ],
            );
        },
    },
}));

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        props: ['open'],
        emits: ['update:open'],
        render() {
            return this.open ? h('div', { class: 'sheet-stub', 'data-testid': 'external-user-edit-sheet' }, this.$slots.default?.()) : null;
        },
    },
    SheetContent: {
        render() {
            return h('div', { class: 'sheet-content-stub' }, this.$slots.default?.());
        },
    },
    SheetDescription: {
        render() {
            return h('p', { class: 'sheet-description-stub' }, this.$slots.default?.());
        },
    },
    SheetFooter: {
        render() {
            return h('div', { class: 'sheet-footer-stub' }, this.$slots.default?.());
        },
    },
    SheetHeader: {
        render() {
            return h('div', { class: 'sheet-header-stub' }, this.$slots.default?.());
        },
    },
    SheetTitle: {
        render() {
            return h('h2', { class: 'sheet-title-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        render() {
            return h('span', { ...this.$attrs }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/table', () => ({
    Table: {
        render() {
            return h('table', {}, this.$slots.default?.());
        },
    },
    TableHeader: {
        render() {
            return h('thead', {}, this.$slots.default?.());
        },
    },
    TableHead: {
        render() {
            return h('th', {}, this.$slots.default?.());
        },
    },
    TableBody: {
        render() {
            return h('tbody', {}, this.$slots.default?.());
        },
    },
    TableRow: {
        render() {
            return h('tr', { ...this.$attrs }, this.$slots.default?.());
        },
    },
    TableCell: {
        render() {
            return h('td', { ...this.$attrs }, this.$slots.default?.());
        },
    },
    TableEmpty: {
        props: ['colspan'],
        render() {
            return h('tr', {}, [h('td', { colspan: this.colspan }, this.$slots.default?.())]);
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        render: () => null,
    },
    router: {
        get: (...args: unknown[]) => routerGetMock(...args),
        reload: (...args: unknown[]) => routerReloadMock(...args),
        visit: (...args: unknown[]) => routerVisitMock(...args),
    },
}));

vi.mock('axios', () => ({
    default: {
        delete: (...args: unknown[]) => axiosDeleteMock(...args),
        post: (...args: unknown[]) => axiosPostMock(...args),
        put: (...args: unknown[]) => axiosPutMock(...args),
    },
}));

vi.mock('lucide-vue-next', () => ({
    Pencil: { render: () => h('span') },
}));

describe('ExternalUsers/Index.vue', () => {
    const externalUsers = {
        data: [
            {
                id: 7,
                name: 'Client QA',
                email: 'qa@example.com',
                environment: 'Staging',
                role: 'owner',
                role_label: 'Owner',
                project: { id: 2, name: 'Portal' },
                linked_accounts: [
                    {
                        id: 12,
                        label: 'Portal SSO',
                        email: 'linked@example.com',
                        unlink_url: '/external-users/7/linked-accounts/12',
                        can_unlink: true,
                    },
                ],
                linkable_accounts: [
                    {
                        id: 13,
                        label: 'Production Login',
                        email: 'prod@example.com',
                        environment: 'Production',
                    },
                ],
                links: {
                    link_accounts: '/external-users/7/linked-accounts',
                },
            },
            {
                id: 8,
                name: 'No Project User',
                email: null,
                environment: null,
                role: 'guest',
                role_label: 'Guest',
                project: null,
            },
        ],
        current_page: 1,
        last_page: 2,
        total: 12,
        from: 1,
        to: 2,
    };

    it('renders combined identity and project context columns with unambiguous role labels', () => {
        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: '', sort_by: null, organisation_id: 3 },
                projects: [{ id: 2, name: 'Portal' }],
            },
        });

        expect(wrapper.findAll('th').map((header) => header.text())).toEqual(['Name', 'Project', 'Role', 'Actions']);
        expect(wrapper.find('[data-testid="external-user-identity-7"]').text()).toContain('Client QA');
        expect(wrapper.find('[data-testid="external-user-identity-7"]').text()).toContain('qa@example.com');
        expect(wrapper.find('[data-testid="external-user-project-environment-7"]').text()).toContain('Portal');
        expect(wrapper.find('[data-testid="external-user-project-environment-7"]').text()).toContain('Staging');
        expect(wrapper.find('[data-testid="external-user-role-7"]').text()).toContain('Client Owner');
        expect(wrapper.text()).toContain('Portal');
        expect(wrapper.text()).toContain('No project assigned');
    });

    it('applies search and sort filters', async () => {
        routerGetMock.mockReset();

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: '', sort_by: null, organisation_id: 3 },
                projects: [{ id: 2, name: 'Portal' }],
            },
        });

        await wrapper.get('[data-testid="filter-search"]').setValue('qa');
        await wrapper.get('[data-testid="filter-project"]').setValue('2');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisation/3/external-users',
            {
                page: 1,
                project_id: '2',
                search: 'qa',
                sort_by: 'name',
            },
            expect.objectContaining({ replace: true }),
        );
    });

    it('keeps filters on the organisation scoped route', async () => {
        routerGetMock.mockReset();

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: '', sort_by: null, organisation_id: 3 },
                projects: [{ id: 2, name: 'Portal' }],
            },
        });

        await wrapper.get('[data-testid="filter-project"]').setValue('2');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisation/3/external-users',
            expect.objectContaining({
                page: 1,
                project_id: '2',
            }),
            expect.objectContaining({ replace: true }),
        );
    });

    it('opens edit in a sheet and saves inline without project reassignment', async () => {
        routerVisitMock.mockReset();
        routerReloadMock.mockReset();
        axiosPutMock.mockReset();
        axiosPutMock.mockResolvedValue({ data: { external_user: { id: 7 } } });

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: 'qa', sort_by: 'name', project_id: 2, organisation_id: 3 },
                projects: [
                    { id: 2, name: 'Portal' },
                    { id: 3, name: 'Billing' },
                ],
            },
        });

        await wrapper.get('[data-testid="external-user-edit-7"]').trigger('click');

        expect(routerVisitMock).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="external-user-edit-sheet"]').text()).toContain('Edit external user');
        expect((wrapper.get('[data-testid="external-user-edit-name"]').element as HTMLInputElement).value).toBe('Client QA');
        expect(wrapper.find('[data-testid="external-user-edit-project"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="external-user-project-context"]').text()).toContain('Portal');
        expect(wrapper.get('[data-testid="external-user-project-context"]').text()).toContain('Staging');
        expect(wrapper.get('[data-testid="external-user-role-readonly"]').text()).toContain('Client Owner');

        await wrapper.get('[data-testid="external-user-edit-name"]').setValue('Client QA Lead');
        await wrapper.get('[data-testid="external-user-edit-email"]').setValue('lead@example.com');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith(
            '/external-users/7',
            {
                email: 'lead@example.com',
                name: 'Client QA Lead',
            },
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(routerReloadMock).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['externalUsers'],
                preserveScroll: true,
            }),
        );
    });

    it('edits roles when allowed and manages linked accounts from the sheet', async () => {
        routerReloadMock.mockReset();
        axiosPutMock.mockReset();
        axiosPostMock.mockReset();
        axiosDeleteMock.mockReset();
        axiosPutMock.mockResolvedValue({ data: { external_user: { id: 7 } } });
        axiosPostMock.mockResolvedValue({ data: {} });
        axiosDeleteMock.mockResolvedValue({ data: {} });

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                canManageExternalRoles: true,
                canManageLinkedAccounts: true,
                externalUsers,
                filters: { search: 'qa', sort_by: 'name', project_id: 2, organisation_id: 3 },
                projects: [{ id: 2, name: 'Portal' }],
                roles: [
                    { value: 'owner', label: 'Client Owner' },
                    { value: 'guest', label: 'Guest' },
                ],
            },
        });

        await wrapper.get('[data-testid="external-user-edit-7"]').trigger('click');

        expect(wrapper.get('[data-testid="external-user-edit-role"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="external-user-link-account-select"]').text()).toContain('Production Login');
        expect(wrapper.get('[data-testid="external-user-linked-accounts"]').text()).toContain('Portal SSO');
        expect(wrapper.get('[data-testid="external-user-linked-account-12"]').text()).toContain('linked@example.com');

        await wrapper.get('[data-testid="external-user-link-account-select"]').setValue('13');
        await wrapper.get('[data-testid="external-user-link-account-submit"]').trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/external-users/7/linked-accounts',
            {
                linked_external_user_id: '13',
            },
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );

        await wrapper.get('[data-testid="external-user-linked-account-unlink-12"]').trigger('click');
        await flushPromises();

        expect(axiosDeleteMock).toHaveBeenCalledWith(
            '/external-users/7/linked-accounts/12',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(routerReloadMock).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['externalUsers'],
                preserveScroll: true,
            }),
        );

        await wrapper.get('[data-testid="external-user-edit-role"]').setValue('guest');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axiosPutMock).toHaveBeenCalledWith(
            '/external-users/7',
            {
                email: 'qa@example.com',
                name: 'Client QA',
                role: 'guest',
            },
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
    });

    it('preserves filters on page change', async () => {
        routerVisitMock.mockReset();
        routerGetMock.mockReset();

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: 'qa', sort_by: 'name', project_id: 2, organisation_id: 3 },
                projects: [{ id: 2, name: 'Portal' }],
            },
        });

        await wrapper.get('[data-testid="emit-page-change"]').trigger('click');
        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisation/3/external-users',
            {
                page: 2,
                project_id: '2',
                search: 'qa',
                sort_by: 'name',
            },
            expect.objectContaining({ replace: true }),
        );
    });
});
