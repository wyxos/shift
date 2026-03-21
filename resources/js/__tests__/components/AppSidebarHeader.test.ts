import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

vi.mock('@/components/Breadcrumbs.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('nav', { 'data-testid': 'breadcrumbs' }, this.breadcrumbs?.length ? 'breadcrumbs' : '');
        },
    },
}));

vi.mock('@/components/NotificationBadge.vue', () => ({
    default: {
        render() {
            return h('div', { 'data-testid': 'notification-badge' });
        },
    },
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarTrigger: {
        render() {
            return h('button', { 'data-testid': 'sidebar-trigger' }, 'toggle');
        },
    },
}));

function createMediaQueryList(matches: boolean): MediaQueryList {
    return {
        matches,
        media: '(prefers-color-scheme: dark)',
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    } as unknown as MediaQueryList;
}

const storage = new Map<string, string>();

describe('AppSidebarHeader', () => {
    beforeEach(() => {
        storage.clear();
        document.cookie = 'appearance=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        document.documentElement.classList.remove('dark');

        const localStorageMock = {
            getItem: vi.fn((key: string) => storage.get(key) ?? null),
            setItem: vi.fn((key: string, value: string) => {
                storage.set(key, String(value));
            }),
            removeItem: vi.fn((key: string) => {
                storage.delete(key);
            }),
            clear: vi.fn(() => {
                storage.clear();
            }),
        };

        vi.stubGlobal('localStorage', localStorageMock);
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            value: localStorageMock,
        });
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation(() => createMediaQueryList(false)),
        });
    });

    afterEach(() => {
        storage.clear();
        document.cookie = 'appearance=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        document.documentElement.classList.remove('dark');
        vi.unstubAllGlobals();
    });

    it('renders one appearance cycle button before the notification badge', () => {
        const wrapper = mount(AppSidebarHeader, {
            props: {
                breadcrumbs: [{ title: 'Tasks', href: '/tasks' }] as any,
                showAppearanceToggle: true,
            },
            slots: {
                actions: '<button data-testid="custom-action">Action</button>',
            },
        });

        expect(wrapper.find('[data-testid="sidebar-trigger"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="breadcrumbs"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="custom-action"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="notification-badge"]').exists()).toBe(true);

        const buttons = wrapper.findAll('[data-appearance-toggle]');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].attributes('data-appearance')).toBe('system');

        const html = wrapper.html();
        expect(html.indexOf('data-appearance-toggle')).toBeLessThan(html.indexOf('data-testid="notification-badge"'));
    });
});
