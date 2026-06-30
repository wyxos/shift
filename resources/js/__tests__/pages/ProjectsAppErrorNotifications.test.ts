import Projects from '@/pages/Projects.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, reactive } from 'vue';

const pageMocks = vi.hoisted(() => ({
    axiosGet: vi.fn(),
    axiosPut: vi.fn(),
    routerReload: vi.fn(),
    pageUrl: '/projects',
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
        template: '<div class="admin-list-shell"><slot /></div>',
    },
}));

vi.mock('@/components/admin/projects/ProjectFilterControls.vue', () => ({
    default: {
        template: '<div />',
    },
}));

vi.mock('@/components/DeleteDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));
vi.mock('@/components/admin/projects/ProjectApiTokenDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));
vi.mock('@/components/admin/projects/ProjectCreateDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));
vi.mock('@/components/admin/projects/ProjectEditDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));
vi.mock('@/components/admin/projects/ProjectManageUsersDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));
vi.mock('@/components/admin/projects/ProjectMcpSettingsDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));
vi.mock('@/components/admin/projects/ProjectWidgetSettingsDialog.vue', () => ({
    default: { props: ['open', 'isOpen'], template: '<div v-if="open || isOpen" />' },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'size', 'type'],
        inheritAttrs: false,
        template: '<button v-bind="$attrs" :disabled="disabled" :type="type || `button`"><slot /></button>',
    },
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: {
        props: ['modelValue', 'disabled'],
        emits: ['update:modelValue'],
        inheritAttrs: false,
        template:
            '<input v-bind="$attrs" type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(`update:modelValue`, $event.target.checked)" />',
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        template: '<span v-bind="$attrs"><slot /></span>',
    },
}));

vi.mock('@/components/ui/table', () => ({
    Table: { template: '<table><slot /></table>' },
    TableBody: { template: '<tbody><slot /></tbody>' },
    TableCell: { template: '<td><slot /></td>' },
    TableEmpty: { template: '<div v-bind="$attrs"><slot /></div>' },
    TableHead: { template: '<th><slot /></th>' },
    TableHeader: { template: '<thead><slot /></thead>' },
    TableRow: { template: '<tr v-bind="$attrs"><slot /></tr>' },
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: {
        props: ['open'],
        emits: ['update:open'],
        template: '<div v-if="open" class="dialog"><slot /></div>',
    },
    DialogContent: { template: '<div><slot /></div>' },
    DialogDescription: { template: '<p><slot /></p>' },
    DialogFooter: { template: '<div><slot /></div>' },
    DialogHeader: { template: '<div><slot /></div>' },
    DialogTitle: { template: '<h2><slot /></h2>' },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent } = await import('vue');

    return {
        Head: defineComponent({
            setup() {
                return () => null;
            },
        }),
        router: {
            get: vi.fn(),
            delete: vi.fn(),
            reload: pageMocks.routerReload,
        },
        usePage: () => ({
            url: pageMocks.pageUrl,
            props: {},
        }),
        useForm: vi.fn((initialValues: Record<string, unknown>) => {
            const initial = clone(initialValues);
            const form = reactive({
                ...clone(initialValues),
                errors: {},
                processing: false,
                post: vi.fn(),
                put: vi.fn(),
                reset: vi.fn(() => Object.assign(form, clone(initial), { errors: {}, processing: false })),
            });

            return form;
        }),
    };
});

vi.mock('axios', () => ({
    default: {
        get: pageMocks.axiosGet,
        put: pageMocks.axiosPut,
    },
}));

// prettier-ignore
vi.mock('lucide-vue-next', () => Object.fromEntries(['BellRing', 'Bot', 'KeyRound', 'ListTodo', 'LoaderCircle', 'MessageSquare', 'Pencil', 'Plus', 'Search', 'Trash2', 'Users', 'UserSearch'].map((name) => [name, { render: () => h('span') }])));

describe('Projects app error notifications', () => {
    function mountPage() {
        return mount(Projects, {
            props: {
                projects: {
                    data: [
                        {
                            id: 1,
                            name: 'Portal Refresh',
                            isOwner: true,
                            client_name: 'Acme Client',
                            organisation_name: 'Acme Org',
                        },
                        { id: 2, name: 'Shared Rollout', isOwner: false, organisation_name: 'Northwind' },
                    ],
                    current_page: 1,
                    last_page: 1,
                    total: 2,
                    from: 1,
                    to: 2,
                },
                accessUsers: [],
                clients: [],
                organisations: [],
                filters: {
                    search: '',
                    sort_by: 'newest',
                },
            },
        });
    }

    beforeEach(() => {
        pageMocks.axiosGet.mockReset();
        pageMocks.axiosPut.mockReset();
        pageMocks.routerReload.mockReset();
        pageMocks.axiosGet.mockResolvedValue({
            data: {
                project_id: 1,
                selected_user_ids: [7],
                users: [
                    { id: 7, name: 'Existing User', email: 'existing@example.com' },
                    { id: 8, name: 'Second User', email: 'second@example.com' },
                ],
            },
        });
        pageMocks.axiosPut.mockResolvedValue({ data: {} });
    });

    it('loads and saves app error notification recipients', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="project-app-error-notifications-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="project-app-error-notifications-2"]').exists()).toBe(false);

        await wrapper.get('[data-testid="project-app-error-notifications-1"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="project-app-error-notifications-count"]').text()).toContain('1 selected');

        await wrapper.get('[data-testid="project-app-error-notification-user-8-checkbox"]').setValue(true);
        await wrapper.get('[data-testid="save-app-error-notifications"]').trigger('click');
        await flushPromises();

        expect(pageMocks.axiosGet).toHaveBeenCalledWith(
            '/projects/1/app-error-notifications',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(pageMocks.axiosPut).toHaveBeenCalledWith(
            '/projects/1/app-error-notifications',
            {
                user_ids: [7, 8],
            },
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(pageMocks.routerReload).toHaveBeenCalledWith(expect.objectContaining({ only: ['projects'], preserveScroll: true }));
    });
});
