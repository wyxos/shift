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
        expect(wrapper.text()).toContain('issue reporting');
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

        expect(wrapper.text()).toContain('Hosted portal');
        expect(wrapper.text()).toContain('Self-hosted portal');
        expect(wrapper.text()).toContain('shift.wyxos.com');
        expect(wrapper.text()).toContain('without using the hosted service');
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
        expect(images.some((image) => image.attributes('src') === '/marketing/shift-app-tasks.png')).toBe(true);
        expect(images.some((image) => image.attributes('src') === '/marketing/shift-portal-queue.png')).toBe(true);
        expect(wrapper.text()).toContain('1Install package');
        expect(wrapper.text()).toContain('2Client reports issue');
        expect(wrapper.text()).toContain('3Team reviews report');
        expect(wrapper.text()).toContain('app.northwind.com/shift/tasks');
        expect(wrapper.text()).toContain('shift.wyxos.com/tasks');
        expect(wrapper.text()).not.toContain(
            'The menus and URLs are different because the work starts in the client portal and finishes in the SHIFT platform.',
        );
    });

    it('shows the real installer flow, install fit, and split buying paths', () => {
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
        expect(wrapper.text()).toContain('Open this URL in your browser to approve the installation:');
        expect(wrapper.text()).toContain('https://shift.wyxos.com/sdk/install');
        expect(wrapper.text()).toContain('Short code: A1B2-C3');
        expect(wrapper.text()).toContain('Waiting for approval...');
        expect(wrapper.text()).toContain('Select which project to link to this application');
        expect(wrapper.text()).toContain('Authorization approved.');
        expect(wrapper.text()).toContain('Installation complete.');
        expect(wrapper.text()).not.toContain('PROMPT');
        expect(wrapper.text()).not.toContain('ANSWER');
        expect(wrapper.text()).not.toContain('Which SHIFT URL should this project use?');
        expect(wrapper.text()).not.toContain('Choose the project to connect');
        expect(wrapper.text()).toContain('Client app access');
        expect(wrapper.text()).toContain('Hosted or self-hosted');
        expect(wrapper.text()).toContain('Report details');
        expect(wrapper.text()).toContain('Where it helps');
        expect(wrapper.text()).toContain('Before you install.');
        expect(wrapper.text()).not.toContain('Do clients need a SHIFT login?');
        expect(wrapper.text()).not.toContain('Can we self-host it?');
        expect(wrapper.text()).not.toContain('What data leaves the client app?');
        expect(wrapper.text()).not.toContain('FAQ');
        expect(wrapper.text()).not.toContain('Answers before you install.');
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
        expect(terminal.find('[data-testid="install-terminal-screen"]').exists()).toBe(true);
        expect(terminal.text()).toContain('$ php artisan install:shift');
        expect(terminal.text()).toContain('runcloud@my-server:~/webapps/app-northwind$');
        expect(terminal.text()).toContain('Open this URL in your browser to approve the installation:');
        expect(terminal.text()).toContain('https://shift.wyxos.com/sdk/install');
        expect(terminal.text()).toContain('Authorization approved.');
        expect(terminal.text()).toContain('Installation complete.');
        expect(terminal.text()).not.toMatch(/\b(INFO|ACTION|CODE|WAIT|SUCCESS)\b/);
        expect(terminal.text()).toContain('Short code: A1B2-C3');
        expect(terminal.find('ol').exists()).toBe(false);
        expect(terminal.findAll('[data-testid="install-terminal-line"]').length).toBeGreaterThan(6);
    });
});
