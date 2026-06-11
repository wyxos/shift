import Login from '@/pages/auth/Login.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h, reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        render: () => {},
    },
    Link: {
        props: ['href'],
        render() {
            return h('a', { href: this.href || '#' }, this.$slots.default?.());
        },
    },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
            reset: vi.fn(),
        }),
}));

vi.mock('@/layouts/AuthLayout.vue', () => ({
    default: {
        props: ['title', 'description'],
        render() {
            return h('main', {}, [h('h1', {}, this.title), h('p', {}, this.description), this.$slots.default?.()]);
        },
    },
}));

vi.mock('@/components/InputError.vue', () => ({
    default: {
        props: ['message'],
        render() {
            return this.message ? h('p', {}, this.message) : null;
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        render() {
            return h('button', {}, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: {
        props: ['modelValue'],
        render() {
            return h('input', { type: 'checkbox', checked: this.modelValue });
        },
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'type', 'id', 'placeholder'],
        render() {
            return h('input', {
                id: this.id,
                placeholder: this.placeholder,
                type: this.type,
                value: this.modelValue,
            });
        },
    },
}));

vi.mock('@/components/ui/label', () => ({
    Label: {
        props: ['for'],
        render() {
            return h('label', { for: this.for }, this.$slots.default?.());
        },
    },
}));

vi.mock('lucide-vue-next', () => ({
    LoaderCircle: { render: () => h('span') },
}));

describe('Login.vue', () => {
    it('links guests to account registration', () => {
        const wrapper = mount(Login, {
            props: {
                canResetPassword: true,
            },
            global: {
                mocks: {
                    route: (name: string) => `/${name}`,
                },
            },
        });

        const links = wrapper.findAll('a');

        expect(wrapper.text()).toContain('Log in to SHIFT');
        expect(links.some((link) => link.text().includes('Create account') && link.attributes('href') === '/register')).toBe(true);
    });
});
