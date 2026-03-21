/* eslint-disable max-lines */
import Index from '@/pages/Organisations/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, reactive } from 'vue';

const routerGetMock = vi.fn();
const routerDeleteMock = vi.fn();
const fetchMock = vi.fn();
const formInstances: any[] = [];

function cloneInitial<T>(value: T): T {
    return JSON.parse(JSON.stringify(value));
}

function buildForm<T extends Record<string, any>>(initial: T) {
    const form = reactive({
        ...cloneInitial(initial),
        errors: {},
        processing: false,
        reset: vi.fn(() => {
            Object.assign(form, cloneInitial(initial));
            form.errors = {};
            form.processing = false;
        }),
        post: vi.fn((url: string, options?: Record<string, any>) => {
            options?.onSuccess?.();
        }),
        put: vi.fn((url: string, options?: Record<string, any>) => {
            options?.onSuccess?.();
        }),
    });

    formInstances.push(form);

    return form;
}

function findForm(predicate: (form: any) => boolean) {
    const form = formInstances.find(predicate);
    expect(form).toBeTruthy();
    return form;
}

function getEditForm() {
    return findForm((form) => 'id' in form && 'name' in form && !('isActive' in form) && !('email' in form));
}

function getCreateForm() {
    return findForm((form) => 'name' in form && 'isActive' in form && !('id' in form));
}

function getInviteForm() {
    return findForm((form) => 'organisation_id' in form && 'email' in form && 'name' in form);
}

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        render: () => null,
    },
    router: {
        get: (...args: any[]) => routerGetMock(...args),
        delete: (url: string, options?: Record<string, any>) => {
            routerDeleteMock(url, options);
            options?.onSuccess?.();
        },
    },
    useForm: (initial: Record<string, any>) => buildForm(initial),
}));

vi.mock('@/components/admin/AdminListShell.vue', () => ({
    default: {
        props: ['title', 'description', 'filtersOpen', 'activeFilterCount', 'page', 'itemsLabel', 'filterTitle', 'filterDescription'],
        emits: ['update:filtersOpen', 'page-change'],
        render() {
            return h('div', { class: 'admin-list-shell' }, [
                h('div', { class: 'shell-title' }, this.title),
                this.$slots.actions?.(),
                this.$slots.filters?.(),
                this.$slots['filter-actions']?.(),
                this.$slots.default?.(),
                h(
                    'button',
                    {
                        'data-testid': 'page-2',
                        onClick: () => this.$emit('page-change', 2),
                    },
                    'page 2',
                ),
            ]);
        },
    },
}));

vi.mock('@/components/DeleteDialog.vue', () => ({
    default: {
        props: ['isOpen'],
        emits: ['cancel', 'confirm'],
        render() {
            return h('div', { 'data-testid': 'delete-dialog', 'data-open': String(this.isOpen) }, [
                this.$slots.title?.(),
                this.$slots.description?.(),
                h(
                    'button',
                    {
                        'data-testid': 'delete-dialog-confirm',
                        onClick: () => this.$emit('confirm'),
                    },
                    this.$slots.confirm?.() ?? 'Confirm',
                ),
                h(
                    'button',
                    {
                        'data-testid': 'delete-dialog-cancel',
                        onClick: () => this.$emit('cancel'),
                    },
                    this.$slots.cancel?.() ?? 'Cancel',
                ),
            ]);
        },
    },
}));

vi.mock('@/components/ui/alert-dialog', () => ({
    AlertDialog: {
        props: ['open'],
        emits: ['update:open'],
        render() {
            return h('div', { class: 'alert-dialog' }, this.$slots.default?.());
        },
    },
    AlertDialogTrigger: {
        render() {
            return h('div', { class: 'alert-dialog-trigger' }, this.$slots.default?.());
        },
    },
    AlertDialogContent: {
        render() {
            return h('div', { class: 'alert-dialog-content' }, this.$slots.default?.());
        },
    },
    AlertDialogHeader: {
        render() {
            return h('div', { class: 'alert-dialog-header' }, this.$slots.default?.());
        },
    },
    AlertDialogFooter: {
        render() {
            return h('div', { class: 'alert-dialog-footer' }, this.$slots.default?.());
        },
    },
    AlertDialogTitle: {
        render() {
            return h('div', { class: 'alert-dialog-title' }, this.$slots.default?.());
        },
    },
    AlertDialogDescription: {
        render() {
            return h('div', { class: 'alert-dialog-description' }, this.$slots.default?.());
        },
    },
    AlertDialogAction: {
        props: ['disabled'],
        render() {
            return h('button', { ...this.$attrs, disabled: this.disabled }, this.$slots.default?.());
        },
    },
    AlertDialogCancel: {
        render() {
            return h('button', { ...this.$attrs }, this.$slots.default?.());
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
        props: ['variant', 'size', 'disabled'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    disabled: this.disabled,
                    class: `button ${this.variant || ''} ${this.size || ''}`,
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/button-group', () => ({
    ButtonGroup: {
        props: ['modelValue', 'options', 'disabled', 'testIdPrefix'],
        emits: ['update:modelValue'],
        render() {
            return h(
                'div',
                { class: 'button-group' },
                (this.options || []).map((option: any) =>
                    h(
                        'button',
                        {
                            type: 'button',
                            'data-testid': this.testIdPrefix ? `${this.testIdPrefix}-${option.value}` : undefined,
                            disabled: this.disabled,
                            onClick: () => this.$emit('update:modelValue', option.value),
                        },
                        option.label,
                    ),
                ),
            );
        },
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        render() {
            return h('span', { ...this.$attrs, class: 'badge' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/label', () => ({
    Label: {
        render() {
            return h('label', { ...this.$attrs }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/table', () => ({
    Table: {
        render() {
            return h('table', { class: 'table' }, this.$slots.default?.());
        },
    },
    TableHeader: {
        render() {
            return h('thead', {}, this.$slots.default?.());
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
    TableHead: {
        render() {
            return h('th', { ...this.$attrs }, this.$slots.default?.());
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
            return h('tr', [h('td', { colspan: this.colspan }, this.$slots.default?.())]);
        },
    },
}));

describe('Organisations/Index.vue', () => {
    beforeEach(() => {
        routerGetMock.mockReset();
        routerDeleteMock.mockReset();
        fetchMock.mockReset();
        fetchMock.mockResolvedValue({
            json: async () => [
                {
                    id: 20,
                    user_name: 'Jane Admin',
                    user_email: 'jane@example.com',
                },
            ],
        });
        formInstances.length = 0;
        vi.stubGlobal('fetch', fetchMock);
    });

    function makeProps(overrides: Partial<any> = {}) {
        return {
            organisations: {
                data: [
                    {
                        id: 1,
                        name: 'Acme Labs',
                        created_at: '2026-03-10T09:00:00Z',
                        organisation_users_count: 1,
                        projects_count: 3,
                    },
                    {
                        id: 2,
                        name: 'Beta Systems',
                        created_at: null,
                        organisation_users_count: 4,
                        projects_count: 0,
                    },
                ],
                current_page: 1,
                last_page: 4,
                total: 26,
                from: 1,
                to: 10,
            },
            filters: {
                search: '',
                sort_by: null,
            },
            ...overrides,
        };
    }

    it('renders organisation rows with access counts and unknown date fallback', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        expect(wrapper.find('[data-testid="organisation-row-1"]').text()).toContain('Acme Labs');
        expect(wrapper.find('[data-testid="organisation-row-2"]').text()).toContain('Beta Systems');
        expect(wrapper.text()).toContain('1 user');
        expect(wrapper.text()).toContain('3 projects');
        expect(wrapper.text()).toContain('Unknown');
    });

    it('shows an empty state when no organisations are returned', () => {
        const wrapper = mount(Index, {
            props: makeProps({
                organisations: {
                    data: [],
                    current_page: 1,
                    last_page: 1,
                    total: 0,
                    from: null,
                    to: null,
                },
            }),
        });

        expect(wrapper.text()).toContain('No organisations found.');
    });

    it('applies search and sort filters through the sheet', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="filter-search"]').setValue('acme');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisations',
            {
                search: 'acme',
                sort_by: 'name',
                page: 1,
            },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

    it('resets filters back to defaults', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                filters: {
                    search: 'beta',
                    sort_by: 'oldest',
                },
            }),
        });

        await wrapper.get('[data-testid="filters-reset"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisations',
            {
                search: undefined,
                sort_by: undefined,
                page: 1,
            },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

    it('navigates pages while preserving active filters', async () => {
        const wrapper = mount(Index, {
            props: makeProps({
                filters: {
                    search: 'labs',
                    sort_by: 'oldest',
                },
            }),
        });

        await wrapper.get('[data-testid="page-2"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/organisations',
            {
                search: 'labs',
                sort_by: 'oldest',
                page: 2,
            },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

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

    it('invites a user from the row action', async () => {
        const wrapper = mount(Index, {
            props: makeProps(),
        });

        await wrapper.get('[data-testid="organisation-invite-1"]').trigger('click');

        const inviteForm = getInviteForm();

        expect(inviteForm.organisation_id).toBe(1);
        expect(inviteForm.organisation_name).toBe('Acme Labs');

        await wrapper.get('[data-testid="invite-organisation-email"]').setValue('staff@example.com');
        await wrapper.get('[data-testid="invite-organisation-name"]').setValue('Staff Member');
        await wrapper.get('[data-testid="submit-invite-organisation"]').trigger('click');

        expect(inviteForm.post).toHaveBeenCalledWith(
            '/organisations/1/users',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
                onError: expect.any(Function),
            }),
        );
        expect(inviteForm.reset).toHaveBeenCalled();
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
