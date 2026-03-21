import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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

describe('AppearanceTabs', () => {
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
            value: vi.fn().mockImplementation(() => createMediaQueryList(true)),
        });
    });

    afterEach(() => {
        storage.clear();
        document.cookie = 'appearance=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        document.documentElement.classList.remove('dark');
        vi.unstubAllGlobals();
    });

    it('renders a compact icon-only toggle set for header usage', () => {
        const wrapper = mount(AppearanceTabs, {
            props: {
                compact: true,
            },
        });

        const buttons = wrapper.findAll('button');

        expect(buttons).toHaveLength(3);
        expect(buttons.map((button) => button.attributes('title'))).toEqual(['Use light theme', 'Use dark theme', 'Use system theme']);
        expect(wrapper.findAll('.sr-only')).toHaveLength(3);
    });

    it('persists and applies the selected appearance', async () => {
        const wrapper = mount(AppearanceTabs, {
            props: {
                compact: true,
            },
        });

        await wrapper.get('button[title="Use dark theme"]').trigger('click');
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        await wrapper.get('button[title="Use light theme"]').trigger('click');
        expect(localStorage.getItem('appearance')).toBe('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);

        await wrapper.get('button[title="Use system theme"]').trigger('click');
        expect(localStorage.getItem('appearance')).toBe('system');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });
});
