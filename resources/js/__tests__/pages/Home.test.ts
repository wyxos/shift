import Home from '@/pages/Home.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

// Mock Inertia components
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
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['asChild', 'variant', 'size'],
        render() {
            return h('button', {}, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        props: ['variant'],
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    },
}));

vi.mock('@/components/ui/card', () => ({
    Card: {
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    },
    CardHeader: {
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    },
    CardTitle: {
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    },
    CardDescription: {
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    },
    CardContent: {
        render() {
            return h('div', {}, this.$slots.default?.());
        },
    },
}));

vi.mock('lucide-vue-next', () => ({
    CheckCircle2: { render: () => h('span') },
    FolderKanban: { render: () => h('span') },
    Github: { render: () => h('span') },
    MessageSquare: { render: () => h('span') },
    Paperclip: { render: () => h('span') },
    Plug: { render: () => h('span') },
}));

describe('Home.vue', () => {
    it('mentions SHIFT only once and describes the open source Laravel-focused scope', () => {
        const wrapper = mount(Home, {
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        expect(wrapper.find('h1').text()).toBe('SHIFT');

        const matches = wrapper.text().match(/SHIFT/g) ?? [];
        expect(matches).toHaveLength(1);

        expect(wrapper.text()).toContain('Open source');
        expect(wrapper.text()).toContain('MIT');
        expect(wrapper.text()).toContain('Laravel');
        expect(wrapper.text()).toContain('other frameworks');
        expect(wrapper.text()).not.toContain('See how it works');
    });

    it('shows login link and GitHub icon when user is not authenticated', () => {
        const wrapper = mount(Home, {
            props: {
                auth: {
                    user: null,
                },
            },
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        const links = wrapper.findAll('a');
        expect(links.some((link) => link.text().includes('Log in'))).toBe(true);
        expect(links.some((link) => link.attributes('href')?.includes('github.com/wyxos/shift'))).toBe(true);
        expect(links.some((link) => link.attributes('href')?.includes('wyxos.com'))).toBe(true);
        expect(links.some((link) => link.text().includes('Go to Dashboard'))).toBe(false);
    });

    it('shows dashboard link when user is authenticated', () => {
        const wrapper = mount(Home, {
            props: {
                auth: {
                    user: { id: 1, name: 'Test User' },
                },
            },
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        const links = wrapper.findAll('a');
        expect(links.some((link) => link.text().includes('Go to Dashboard'))).toBe(true);
        expect(links.some((link) => link.text().includes('Log in'))).toBe(false);
    });
});
