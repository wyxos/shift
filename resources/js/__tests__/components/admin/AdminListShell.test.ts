import AdminListShell from '@/components/admin/AdminListShell.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        render() {
            return h('span', { class: 'badge-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['disabled', 'variant', 'size'],
        emits: ['click'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    class: `button-stub ${this.variant ?? ''} ${this.size ?? ''}`.trim(),
                    disabled: this.disabled,
                    onClick: (event: MouseEvent) => this.$emit('click', event),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/card', () => ({
    Card: {
        render() {
            return h('div', { class: 'card-stub' }, this.$slots.default?.());
        },
    },
    CardHeader: {
        render() {
            return h('div', { class: 'card-header-stub' }, this.$slots.default?.());
        },
    },
    CardTitle: {
        render() {
            return h('div', { class: 'card-title-stub' }, this.$slots.default?.());
        },
    },
    CardContent: {
        render() {
            return h('div', { class: 'card-content-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        props: ['open'],
        emits: ['update:open'],
        render() {
            return h('div', { class: 'sheet-stub', 'data-open': this.open }, this.$slots.default?.());
        },
    },
    SheetTrigger: {
        render() {
            return h('div', { class: 'sheet-trigger-stub' }, this.$slots.default?.());
        },
    },
    SheetContent: {
        render() {
            return h('div', { class: 'sheet-content-stub' }, this.$slots.default?.());
        },
    },
    SheetHeader: {
        render() {
            return h('div', { class: 'sheet-header-stub' }, this.$slots.default?.());
        },
    },
    SheetTitle: {
        render() {
            return h('div', { class: 'sheet-title-stub' }, this.$slots.default?.());
        },
    },
    SheetDescription: {
        render() {
            return h('div', { class: 'sheet-description-stub' }, this.$slots.default?.());
        },
    },
    SheetFooter: {
        render() {
            return h('div', { class: 'sheet-footer-stub' }, this.$slots.default?.());
        },
    },
}));

vi.mock('lucide-vue-next', () => ({
    Filter: {
        render() {
            return h('span', { class: 'filter-icon-stub' });
        },
    },
}));

describe('AdminListShell', () => {
    it('renders list summary and active filter count', () => {
        const wrapper = mount(AdminListShell, {
            props: {
                title: 'Users',
                description: 'Manage platform users.',
                activeFilterCount: 2,
                itemsLabel: 'users',
                page: {
                    current_page: 2,
                    last_page: 3,
                    from: 11,
                    to: 20,
                    total: 26,
                },
            },
            slots: {
                default: '<div data-testid="table-slot">table</div>',
                filters: '<div data-testid="filters-slot">filters</div>',
                'filter-actions': '<button type="button">Apply</button>',
                actions: '<button type="button">Create</button>',
            },
        });

        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('Manage platform users.');
        expect(wrapper.text()).toContain('Showing 11 to 20 of 26 users');
        expect(wrapper.text()).toContain('2 filters active');
        expect(wrapper.find('[data-testid="table-slot"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filters-slot"]').exists()).toBe(true);
    });

    it('emits page changes for previous and next buttons', async () => {
        const wrapper = mount(AdminListShell, {
            props: {
                title: 'Users',
                itemsLabel: 'users',
                page: {
                    current_page: 2,
                    last_page: 3,
                    from: 11,
                    to: 20,
                    total: 26,
                },
            },
        });

        const buttons = wrapper.findAll('button');
        const previous = buttons.find((button) => button.text().includes('Previous'));
        const next = buttons.find((button) => button.text().includes('Next'));

        expect(previous).toBeDefined();
        expect(next).toBeDefined();

        await previous!.trigger('click');
        await next!.trigger('click');

        expect(wrapper.emitted('page-change')).toEqual([[1], [3]]);
    });

    it('disables pagination buttons at the boundaries', () => {
        const wrapper = mount(AdminListShell, {
            props: {
                title: 'Users',
                itemsLabel: 'users',
                page: {
                    current_page: 1,
                    last_page: 1,
                    from: 0,
                    to: 0,
                    total: 0,
                },
            },
        });

        const buttons = wrapper.findAll('button');
        const previous = buttons.find((button) => button.text().includes('Previous'));
        const next = buttons.find((button) => button.text().includes('Next'));

        expect(previous?.attributes('disabled')).toBeDefined();
        expect(next?.attributes('disabled')).toBeDefined();
    });
});
