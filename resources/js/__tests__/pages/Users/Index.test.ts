import UsersIndex from '@/pages/Users/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const routerGetMock = vi.fn();

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
                h('div', { class: 'description' }, this.description),
                h('button', { type: 'button', 'data-testid': 'emit-page-change', onClick: () => this.$emit('page-change', 3) }, 'page 3'),
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
    },
}));

vi.mock('lucide-vue-next', () => ({
    Pencil: { render: () => h('span') },
}));

describe('Users/Index.vue', () => {
    const users = {
        data: [
            { id: 1, name: 'Alice', email: 'alice@example.com', email_verified_at: '2026-03-20T10:00:00Z', created_at: '2026-03-10T10:00:00Z' },
            { id: 2, name: 'Bob', email: 'bob@example.com', email_verified_at: null, created_at: '2026-03-11T10:00:00Z' },
        ],
        current_page: 1,
        last_page: 3,
        total: 22,
        from: 1,
        to: 2,
    };

    it('renders user rows and verification badges', () => {
        const wrapper = mount(UsersIndex, {
            props: {
                users,
                filters: { search: '', sort_by: null },
            },
        });

        expect(wrapper.find('[data-testid="user-row-1"]').text()).toContain('Alice');
        expect(wrapper.find('[data-testid="user-verification-1"]').text()).toContain('Verified');
        expect(wrapper.find('[data-testid="user-verification-2"]').text()).toContain('Unverified');
    });

    it('applies search and sort filters', async () => {
        routerGetMock.mockReset();

        const wrapper = mount(UsersIndex, {
            props: {
                users,
                filters: { search: '', sort_by: null },
            },
        });

        await wrapper.get('[data-testid="filter-search"]').setValue('alice');
        await wrapper.get('[data-testid="sort-by-name"]').trigger('click');
        await wrapper.get('[data-testid="filters-apply"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/users',
            {
                page: 1,
                search: 'alice',
                sort_by: 'name',
            },
            expect.objectContaining({ replace: true }),
        );
    });

    it('preserves active filters when changing page', async () => {
        routerGetMock.mockReset();

        const wrapper = mount(UsersIndex, {
            props: {
                users,
                filters: { search: 'alice', sort_by: 'name' },
            },
        });

        await wrapper.get('[data-testid="emit-page-change"]').trigger('click');

        expect(routerGetMock).toHaveBeenCalledWith(
            '/users',
            {
                page: 3,
                search: 'alice',
                sort_by: 'name',
            },
            expect.objectContaining({ replace: true }),
        );
    });
});
