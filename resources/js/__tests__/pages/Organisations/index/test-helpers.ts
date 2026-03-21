import Index from '@/pages/Organisations/Index.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, expect, vi } from 'vitest';
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

export function makeProps(overrides: Partial<any> = {}) {
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

export { Index, fetchMock, flushPromises, getCreateForm, getEditForm, getInviteForm, mount, routerDeleteMock, routerGetMock };
