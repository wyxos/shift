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

vi.mock('lucide-vue-next', () => {
    const stub = { render: () => h('span') };
    const names = [
        'ArrowRight',
        'Bug',
        'CheckCircle2',
        'Cloud',
        'ExternalLink',
        'Github',
        'Inbox',
        'Layers',
        'ListChecks',
        'MessageSquare',
        'Package',
        'Paperclip',
        'Plug',
        'Server',
        'Sparkles',
        'Terminal',
    ];
    return Object.fromEntries(names.map((name) => [name, stub]));
});

describe('Home.vue', () => {
    it('leads with the client-app value proposition and the Laravel package path', () => {
        const wrapper = mount(Home, {
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        expect(wrapper.find('h1').text()).toContain('Tasks from your clients');

        expect(wrapper.text()).toContain('MIT');
        expect(wrapper.text()).toContain('Laravel');
        expect(wrapper.text()).toContain('wyxos/shift-php');
        expect(wrapper.text()).toContain('issue and task tracker');
        expect(wrapper.text()).toContain('For Laravel teams maintaining client apps, support portals, and internal tools.');
        expect(wrapper.text()).not.toContain('Laravel package + SHIFT portal');
        expect(wrapper.text()).not.toContain('Open source · built for Laravel');
        expect(wrapper.text()).not.toContain('Open source under MIT · Laravel 12 · Vue 3');
        expect(wrapper.text()).not.toContain('—');
    });

    it('shows sign in and sign up links when user is not authenticated', () => {
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
        expect(links.some((link) => link.text().includes('Sign in'))).toBe(true);
        expect(links.some((link) => link.text().includes('Sign up') && link.attributes('href') === '/register')).toBe(true);
        expect(links.some((link) => link.text().includes('Log in'))).toBe(false);
        expect(links.some((link) => link.text().includes('Create account'))).toBe(false);
        expect(links.some((link) => link.attributes('href')?.includes('github.com/wyxos/shift'))).toBe(true);
        expect(links.some((link) => link.attributes('href')?.includes('packagist.org/packages/wyxos/shift-php'))).toBe(true);
        expect(links.some((link) => link.attributes('href')?.includes('wyxos.com'))).toBe(true);
        expect(links.some((link) => link.text().includes('Go to Dashboard'))).toBe(false);
    });

    it('shows dashboard link when user is authenticated', () => {
        const wrapper = mount(Home, {
            props: {
                auth: {
                    user: { id: 1, name: 'Demo User' },
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
        expect(links.some((link) => link.text().includes('Sign in'))).toBe(false);
    });

    it('explains hosted versus self-hosted setup and install commands', () => {
        const wrapper = mount(Home, {
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        expect(wrapper.text()).toContain('Hosted SHIFT');
        expect(wrapper.text()).toContain('Self-hosted SHIFT');
        expect(wrapper.text()).toContain('shift.wyxos.com');
        expect(wrapper.text()).toContain('without a hosted billing layer');
        expect(wrapper.text()).toContain('composer require wyxos/shift-php');
        expect(wrapper.text()).toContain('SHIFT_URL=https://shift.wyxos.com');
        expect(wrapper.text()).toContain('php artisan install:shift');
        expect(wrapper.text()).toContain('/shift/tasks');
    });

    it('explains the workflow and shows URL-labelled product screenshots', () => {
        const wrapper = mount(Home, {
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        const images = wrapper.findAll('img');
        expect(images.some((image) => image.attributes('src') === '/marketing/shift-embedded-tasks.png')).toBe(true);
        expect(images.some((image) => image.attributes('src') === '/marketing/shift-portal-queue.png')).toBe(true);
        expect(wrapper.text()).toContain('1Install package');
        expect(wrapper.text()).toContain('2Client files request');
        expect(wrapper.text()).toContain('3Team triages in SHIFT');
        expect(wrapper.text()).toContain('app.northwind.com/shift/tasks');
        expect(wrapper.text()).toContain('shift.wyxos.com/tasks');
        expect(wrapper.text()).not.toContain(
            'The menus and URLs are different because the work starts in the client portal and finishes in the SHIFT platform.',
        );
    });

    it('shows the real installer flow, objections, and split buying paths', () => {
        const wrapper = mount(Home, {
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        expect(wrapper.text()).toContain('Run php artisan install:shift');
        expect(wrapper.text()).toContain('Detected application environment: local');
        expect(wrapper.text()).toContain('Detected application URL: https://app.northwind.com');
        expect(wrapper.text()).toContain('Verify this installation in your browser to continue.');
        expect(wrapper.text()).toContain('Verification URL: https://shift.wyxos.com/sdk/install');
        expect(wrapper.text()).toContain('Short code: A1B2-C3');
        expect(wrapper.text()).toContain('Waiting for SHIFT approval...');
        expect(wrapper.text()).toContain('Select which SHIFT project to link to this application');
        expect(wrapper.text()).toContain('SHIFT authorization approved.');
        expect(wrapper.text()).toContain('SHIFT installation complete.');
        expect(wrapper.text()).not.toContain('PROMPT');
        expect(wrapper.text()).not.toContain('ANSWER');
        expect(wrapper.text()).not.toContain('Which SHIFT URL should this project use?');
        expect(wrapper.text()).not.toContain('Choose the project to connect');
        expect(wrapper.text()).toContain('Do clients need a SHIFT login?');
        expect(wrapper.text()).toContain('Can we self-host it?');
        expect(wrapper.text()).toContain('What data leaves the client app?');
        expect(wrapper.text()).toContain('FAQ');
        expect(wrapper.text()).toContain('Answers before you install.');
        expect(wrapper.text()).not.toContain('Questions teams ask');
        expect(wrapper.text()).not.toContain('No new client portal to explain.');
        expect(wrapper.text()).toContain('Start hosted');
        expect(wrapper.text()).toContain('Install package');
    });

    it('renders the installer preview as a terminal transcript', () => {
        const wrapper = mount(Home, {
            global: {
                mocks: {
                    route: (name) => `/${name}`,
                },
            },
        });

        const terminal = wrapper.get('[data-testid="install-terminal"]');

        expect(wrapper.text()).not.toContain('Installer sequence');
        expect(wrapper.text()).not.toContain('Based on the current package command.');
        expect(terminal.classes()).toEqual(expect.arrayContaining(['bg-slate-950', 'font-mono']));
        expect(terminal.text()).toContain('$ php artisan install:shift');
        expect(terminal.text()).toContain('INFO');
        expect(terminal.text()).toContain('ACTION');
        expect(terminal.text()).toContain('SUCCESS');
        expect(terminal.text()).toContain('Short code: A1B2-C3');
    });
});
