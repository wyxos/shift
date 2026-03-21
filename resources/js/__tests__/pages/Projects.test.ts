import Projects from '@/pages/Projects.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertiaMocks = vi.hoisted(() => ({
    routerGet: vi.fn(),
    routerDelete: vi.fn(),
    formInstances: [] as Array<Record<string, any>>,
    axiosPost: vi.fn(),
    fetchMock: vi.fn(),
}));

vi.stubGlobal('fetch', inertiaMocks.fetchMock);

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value));
}

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        template: '<div class="app-layout"><slot /></div>',
    },
}));

vi.mock('@/components/admin/AdminListShell.vue', () => ({
    default: {
        props: ['page'],
        emits: ['update:filtersOpen', 'page-change'],
        template: `
            <div class="admin-list-shell">
                <div class="filters"><slot name="filters" /></div>
                <div class="filter-actions"><slot name="filter-actions" /></div>
                <div class="actions"><slot name="actions" /></div>
                <button data-testid="shell-next-page" @click="$emit('page-change', Number(page?.current_page || 1) + 1)">Next page</button>
                <slot />
            </div>
        `,
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'size', 'type'],
        inheritAttrs: false,
        template: '<button v-bind="$attrs" :disabled="disabled" :type="type || `button`"><slot /></button>',
    },
}));

vi.mock('@/components/ui/button-group', () => ({
    ButtonGroup: {
        props: ['modelValue', 'options', 'testIdPrefix'],
        emits: ['update:modelValue'],
        template: `
            <div>
                <button
                    v-for="option in options"
                    :key="option.value"
                    :data-testid="testIdPrefix + '-' + option.value"
                    type="button"
                    @click="$emit('update:modelValue', option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        `,
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'type', 'placeholder'],
        emits: ['update:modelValue'],
        inheritAttrs: false,
        template:
            '<input v-bind="$attrs" :type="type" :placeholder="placeholder" :value="modelValue" @input="$emit(`update:modelValue`, $event.target.value)" />',
    },
}));

vi.mock('@/components/ui/label', () => ({ Label: { template: '<label><slot /></label>' } }));
vi.mock('@/components/ui/badge', () => ({ Badge: { template: '<span v-bind="$attrs"><slot /></span>' } }));
vi.mock('@/components/ui/table', () => ({
    Table: { template: '<table><slot /></table>' },
    TableBody: { template: '<tbody><slot /></tbody>' },
    TableCell: { template: '<td><slot /></td>' },
    TableEmpty: { template: '<div v-bind="$attrs"><slot /></div>' },
    TableHead: { template: '<th><slot /></th>' },
    TableHeader: { template: '<thead><slot /></thead>' },
    TableRow: { template: '<tr v-bind="$attrs"><slot /></tr>' },
}));
vi.mock('@/components/ui/alert-dialog', () => ({
    AlertDialog: {
        props: ['open'],
        template: '<div v-if="open" class="alert-dialog"><slot /></div>',
    },
    AlertDialogCancel: {
        props: ['type'],
        inheritAttrs: false,
        template: '<button v-bind="$attrs" :type="type || `button`"><slot /></button>',
    },
    AlertDialogContent: { template: '<div><slot /></div>' },
    AlertDialogDescription: { template: '<p><slot /></p>' },
    AlertDialogFooter: { template: '<div><slot /></div>' },
    AlertDialogHeader: { template: '<div><slot /></div>' },
    AlertDialogTitle: { template: '<h2><slot /></h2>' },
    AlertDialogTrigger: { template: '<div><slot /></div>' },
}));
vi.mock('@/components/DeleteDialog.vue', () => ({
    default: {
        props: ['isOpen'],
        emits: ['cancel', 'confirm'],
        template: `
            <div v-if="isOpen" class="delete-dialog">
                <slot name="title" />
                <slot name="description" />
                <button data-testid="delete-dialog-confirm" @click="$emit('confirm')"><slot name="confirm" /></button>
            </div>
        `,
    },
}));
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');

    return {
        Head: defineComponent({
            setup() {
                return () => null;
            },
        }),
        router: {
            get: inertiaMocks.routerGet,
            delete: inertiaMocks.routerDelete,
        },
        useForm: vi.fn((initialValues: Record<string, unknown>) => {
            const initial = clone(initialValues);
            const form = reactive({
                ...clone(initialValues),
                errors: {},
                processing: false,
                post: vi.fn((_url: string, options?: Record<string, any>) => options?.onSuccess?.()),
                put: vi.fn((_url: string, options?: Record<string, any>) => options?.onSuccess?.()),
                reset: vi.fn(() => {
                    Object.assign(form, clone(initial), { errors: {}, processing: false });
                }),
            });

            inertiaMocks.formInstances.push(form as Record<string, any>);

            return form;
        }),
    };
});
vi.mock('axios', () => ({
    default: {
        post: inertiaMocks.axiosPost,
    },
}));

describe('Projects.vue', () => {
    const mockProjects = {
        data: [
            { id: 1, name: 'Portal Refresh', isOwner: true, client_name: 'Acme Client', organisation_name: null },
            { id: 2, name: 'Shared Rollout', isOwner: false, client_name: null, organisation_name: 'Northwind' },
        ],
        current_page: 1,
        last_page: 2,
        total: 12,
        from: 1,
        to: 10,
    };

    const mockClients = [
        { id: 1, name: 'Acme Client' },
        { id: 2, name: 'Tailspin Client' },
    ];

    const mockOrganisations = [
        { id: 1, name: 'Northwind' },
        { id: 2, name: 'Tailspin' },
    ];

    function mountPage() {
        return mount(Projects, {
            props: {
                projects: mockProjects,
                clients: mockClients,
                organisations: mockOrganisations,
                filters: {
                    search: '',
                    sort_by: 'newest',
                },
            },
        });
    }

    beforeEach(() => {
        inertiaMocks.routerGet.mockReset();
        inertiaMocks.routerDelete.mockReset();
        inertiaMocks.axiosPost.mockReset();
        inertiaMocks.fetchMock.mockReset();
        inertiaMocks.formInstances.length = 0;
        inertiaMocks.axiosPost.mockResolvedValue({ data: { token: 'generated-token' } });
        inertiaMocks.fetchMock.mockResolvedValue({
            ok: true,
            json: vi.fn().mockResolvedValue([
                {
                    id: 10,
                    user_name: 'Shared User',
                    user_email: 'shared@example.com',
                    registration_status: 'registered',
                },
            ]),
        });
    });

    it('renders migrated rows and owner-only actions', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Portal Refresh');
        expect(wrapper.find('[data-testid="project-scope-1"]').text()).toContain('Acme Client');
        expect(wrapper.find('[data-testid="project-grant-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="project-grant-2"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('View and collaborate only');
    });

    it('applies filters and preserves pagination state', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="filter-search"]').setValue('Portal');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(inertiaMocks.routerGet).toHaveBeenCalledWith(
            '/projects',
            expect.objectContaining({ page: 1, search: 'Portal', sort_by: 'name' }),
            expect.objectContaining({ preserveScroll: true, preserveState: true, replace: true }),
        );

        await wrapper.get('[data-testid="shell-next-page"]').trigger('click');

        expect(inertiaMocks.routerGet).toHaveBeenLastCalledWith(
            '/projects',
            expect.objectContaining({ page: 2, search: 'Portal', sort_by: 'name' }),
            expect.objectContaining({ preserveScroll: true, preserveState: true, replace: true }),
        );
    });

    it('preserves create, edit, delete, and grant access flows', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="open-create-project"]').trigger('click');
        await wrapper.get('[data-testid="create-project-name"]').setValue('New Project');
        await wrapper.get('[data-testid="create-project-submit"]').trigger('click');

        const createProjectForm = inertiaMocks.formInstances.find((form) => 'client_id' in form && 'organisation_id' in form);
        expect(createProjectForm?.post).toHaveBeenCalledWith('/projects', expect.objectContaining({ preserveScroll: true }));

        await wrapper.get('[data-testid="project-edit-1"]').trigger('click');
        await wrapper.get('[data-testid="edit-project-name"]').setValue('Renamed Project');
        await wrapper.get('[data-testid="edit-project-submit"]').trigger('click');

        const editProjectForm = inertiaMocks.formInstances.find((form) => 'id' in form && 'name' in form && !('project_id' in form));
        expect(editProjectForm?.put).toHaveBeenCalledWith('/projects/1', expect.objectContaining({ preserveScroll: true }));

        await wrapper.get('[data-testid="project-delete-1"]').trigger('click');
        await wrapper.get('[data-testid="delete-dialog-confirm"]').trigger('click');

        expect(inertiaMocks.routerDelete).toHaveBeenCalledWith('/projects/1', expect.objectContaining({ preserveScroll: true }));

        await wrapper.get('[data-testid="project-grant-1"]').trigger('click');
        await wrapper.get('[data-testid="grant-project-email"]').setValue('new.user@example.com');
        await wrapper.get('[data-testid="grant-project-name"]').setValue('New User');
        await wrapper.get('[data-testid="grant-project-submit"]').trigger('click');

        const grantAccessForm = inertiaMocks.formInstances.find((form) => 'project_id' in form && 'email' in form && 'name' in form);
        expect(grantAccessForm?.post).toHaveBeenCalledWith('/projects/1/users', expect.objectContaining({ preserveScroll: true }));
    });

    it('preserves manage-access and api-token flows', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="project-manage-1"]').trigger('click');
        await flushPromises();

        expect(inertiaMocks.fetchMock).toHaveBeenCalledWith('/projects/1/users');
        expect(wrapper.text()).toContain('shared@example.com');

        await wrapper.get('[data-testid="project-remove-access-10"]').trigger('click');

        expect(inertiaMocks.routerDelete).toHaveBeenCalledWith('/projects/1/users/10', expect.objectContaining({ preserveScroll: true }));

        await wrapper.get('[data-testid="project-token-1"]').trigger('click');
        await wrapper.get('[data-testid="generate-project-token"]').trigger('click');
        await flushPromises();

        expect(inertiaMocks.axiosPost).toHaveBeenCalledWith(
            '/projects/1/api-token',
            {},
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(wrapper.text()).toContain('generated-token');
    });
});
