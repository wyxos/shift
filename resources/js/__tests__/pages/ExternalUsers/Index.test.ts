import ExternalUsersIndex from '@/pages/ExternalUsers/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const routerGetMock = vi.fn();
const routerVisitMock = vi.fn();

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
        visit: (...args: unknown[]) => routerVisitMock(...args),
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
                project: { id: 2, name: 'Portal' },
            },
            {
                id: 8,
                name: 'No Project User',
                email: null,
                environment: null,
                project: null,
            },
        ],
        current_page: 1,
        last_page: 2,
        total: 12,
        from: 1,
        to: 2,
    };

    it('renders external user rows with environment and project details', () => {
        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: '', sort_by: null },
            },
        });

        expect(wrapper.find('[data-testid="external-user-row-7"]').text()).toContain('Client QA');
        expect(wrapper.find('[data-testid="external-user-environment-7"]').text()).toContain('Staging');
        expect(wrapper.text()).toContain('Portal');
        expect(wrapper.text()).toContain('No project assigned');
    });

    it('applies search and sort filters', async () => {
        routerGetMock.mockReset();

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: '', sort_by: null },
            },
        });

        await wrapper.get('[data-testid="filter-search"]').setValue('qa');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/external-users',
            {
                page: 1,
                search: 'qa',
                sort_by: 'name',
            },
            expect.objectContaining({ replace: true }),
        );
    });

    it('navigates to edit and preserves filters on page change', async () => {
        routerVisitMock.mockReset();
        routerGetMock.mockReset();

        const wrapper = mount(ExternalUsersIndex, {
            props: {
                externalUsers,
                filters: { search: 'qa', sort_by: 'name' },
            },
        });

        await wrapper.get('[data-testid="external-user-edit-7"]').trigger('click');
        expect(routerVisitMock).toHaveBeenCalledWith('/external-users/7/edit');

        await wrapper.get('[data-testid="emit-page-change"]').trigger('click');
        expect(routerGetMock).toHaveBeenCalledWith(
            '/external-users',
            {
                page: 2,
                search: 'qa',
                sort_by: 'name',
            },
            expect.objectContaining({ replace: true }),
        );
    });
});
