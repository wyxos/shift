import Clients from '@/pages/Clients.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertiaMocks = vi.hoisted(() => ({
    routerGet: vi.fn(),
    routerDelete: vi.fn(),
    formInstances: [] as Array<Record<string, any>>,
}));

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

describe('Clients.vue', () => {
    const mockClients = {
        data: [
            { id: 1, name: 'Acme', organisation_name: 'Northwind' },
            { id: 2, name: 'Solo Client', organisation_name: null },
        ],
        current_page: 1,
        last_page: 3,
        total: 25,
        from: 1,
        to: 10,
    };

    const mockOrganisations = [
        { id: 1, name: 'Northwind' },
        { id: 2, name: 'Tailspin' },
    ];

    function mountPage() {
        return mount(Clients, {
            props: {
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
        inertiaMocks.formInstances.length = 0;
    });

    it('renders migrated table rows and actions', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Acme');
        expect(wrapper.text()).toContain('Northwind');
        expect(wrapper.find('[data-testid="client-row-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="client-edit-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="client-organisation-2"]').text()).toContain('No organisation assigned');
    });

    it('applies filters and preserves pagination state', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="filter-search"]').setValue('Acme');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(inertiaMocks.routerGet).toHaveBeenCalledWith(
            '/clients',
            expect.objectContaining({ page: 1, search: 'Acme', sort_by: 'name' }),
            expect.objectContaining({ preserveScroll: true, preserveState: true, replace: true }),
        );

        await wrapper.get('[data-testid="shell-next-page"]').trigger('click');

        expect(inertiaMocks.routerGet).toHaveBeenLastCalledWith(
            '/clients',
            expect.objectContaining({ page: 2, search: 'Acme', sort_by: 'name' }),
            expect.objectContaining({ preserveScroll: true, preserveState: true, replace: true }),
        );

        await wrapper.get('[data-testid="filters-reset"]').trigger('click');

        expect(inertiaMocks.routerGet).toHaveBeenLastCalledWith(
            '/clients',
            expect.objectContaining({ page: 1 }),
            expect.objectContaining({ preserveScroll: true, preserveState: true, replace: true }),
        );
    });

    it('preserves create, edit, and delete flows', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="open-create-client"]').trigger('click');
        await wrapper.get('[data-testid="create-client-name"]').setValue('New Client');
        await wrapper.get('[data-testid="create-client-organisation"]').setValue('1');
        await wrapper.get('[data-testid="create-client-submit"]').trigger('click');

        expect(inertiaMocks.formInstances[0].post).toHaveBeenCalledWith('/clients', expect.objectContaining({ preserveScroll: true }));

        await wrapper.get('[data-testid="client-edit-1"]').trigger('click');
        await wrapper.get('[data-testid="edit-client-name"]').setValue('Renamed Client');
        await wrapper.get('[data-testid="edit-client-submit"]').trigger('click');

        expect(inertiaMocks.formInstances[1].put).toHaveBeenCalledWith('/clients/1', expect.objectContaining({ preserveScroll: true }));

        await wrapper.get('[data-testid="client-delete-1"]').trigger('click');
        await wrapper.get('[data-testid="delete-dialog-confirm"]').trigger('click');

        expect(inertiaMocks.routerDelete).toHaveBeenCalledWith('/clients/1', expect.objectContaining({ preserveScroll: true }));
    });
});
