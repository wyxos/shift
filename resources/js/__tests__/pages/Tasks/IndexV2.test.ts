import IndexV2 from '@/pages/Tasks/IndexV2.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'size'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    class: `button ${this.variant || ''} ${this.size || ''}`,
                    disabled: this.disabled,
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/card', () => ({
    Card: {
        render() {
            return h('div', { class: 'card' }, this.$slots.default?.());
        },
    },
    CardHeader: {
        render() {
            return h('div', { class: 'card-header' }, this.$slots.default?.());
        },
    },
    CardTitle: {
        render() {
            return h('div', { class: 'card-title' }, this.$slots.default?.());
        },
    },
    CardContent: {
        render() {
            return h('div', { class: 'card-content' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        props: ['variant'],
        render() {
            return h('span', { class: `badge ${this.variant || ''}` }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        render() {
            return h('input', {
                value: this.modelValue,
                placeholder: this.placeholder,
                onInput: (e) => this.$emit('update:modelValue', (e.target as HTMLInputElement).value),
            });
        },
    },
}));

vi.mock('@/components/ui/label', () => ({
    Label: {
        render() {
            return h('label', {}, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        render() {
            return h('div', { class: 'sheet' }, this.$slots.default?.());
        },
    },
    SheetTrigger: {
        render() {
            return h('div', { class: 'sheet-trigger' }, this.$slots.default?.());
        },
    },
    SheetContent: {
        render() {
            return h('div', { class: 'sheet-content' }, this.$slots.default?.());
        },
    },
    SheetHeader: {
        render() {
            return h('div', { class: 'sheet-header' }, this.$slots.default?.());
        },
    },
    SheetTitle: {
        render() {
            return h('div', { class: 'sheet-title' }, this.$slots.default?.());
        },
    },
    SheetDescription: {
        render() {
            return h('div', { class: 'sheet-description' }, this.$slots.default?.());
        },
    },
    SheetFooter: {
        render() {
            return h('div', { class: 'sheet-footer' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/select', () => ({
    Select: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        render() {
            return h(
                'select',
                {
                    value: this.modelValue,
                    onChange: (e) => this.$emit('update:modelValue', (e.target as HTMLSelectElement).value),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ShiftEditor.vue', () => ({
    default: {
        props: ['modelValue'],
        emits: ['update:modelValue', 'send', 'uploading'],
        render() {
            return h('div', { class: 'shift-editor-stub' });
        },
    },
}));

vi.mock('@/components/ui/image-lightbox', () => ({
    ImageLightbox: {
        render() {
            return h('div', { class: 'image-lightbox-stub' });
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        render: () => null,
    },
    router: {
        get: vi.fn(),
    },
}));

describe('Tasks/IndexV2.vue', () => {
    it('renders header + task rows', () => {
        const wrapper = mount(IndexV2, {
            props: {
                tasks: [
                    { id: 1, title: 'Auth issue', status: 'pending', priority: 'high' },
                    { id: 2, title: 'UI polish', status: 'in-progress', priority: 'medium' },
                ],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                },
            },
        });

        expect(wrapper.find('.app-layout').exists()).toBe(true);
        expect(wrapper.text()).toContain('Tasks V2');
        expect(wrapper.find('[data-testid="filters-trigger"]').exists()).toBe(true);

        const rows = wrapper.findAll('[data-testid="task-row"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.text()).toContain('Auth issue');
        expect(wrapper.text()).toContain('UI polish');

        for (const row of rows) {
            expect(row.find('button[title="Edit"]').exists()).toBe(true);
            expect(row.find('button[title="Delete"]').exists()).toBe(true);
        }
    });

    it('has filter controls', () => {
        const wrapper = mount(IndexV2, {
            props: {
                tasks: [{ id: 1, title: 'Auth issue', status: 'pending', priority: 'high' }],
                filters: {
                    status: ['pending', 'in-progress', 'awaiting-feedback'],
                },
            },
        });

        expect(wrapper.find('input[placeholder="Search by title"]').exists()).toBe(true);
        expect(wrapper.findAll('input[type="checkbox"]').length).toBeGreaterThanOrEqual(4);
    });
});
