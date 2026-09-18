import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

type ShiftRuntimeWindow = Window & {
    shiftConfig?: {
        shiftUrl?: string;
    };
};

function runtimeWindow(): ShiftRuntimeWindow {
    return window as ShiftRuntimeWindow;
}

afterEach(() => {
    delete runtimeWindow().shiftConfig;
});

// The outlined wordmark assets include the symbol in place of the initial S.
describe('SHIFT branding', () => {
    it('provides accessible branding with light and dark wordmarks', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('SHIFT');
        expect(wrapper.findAll('img')).toHaveLength(2);
        expect(wrapper.findAll('img').map((image) => image.attributes('src'))).toEqual([
            '/brand/shift-wordmark-black.svg',
            '/brand/shift-wordmark-white.svg',
        ]);
        expect(wrapper.findAll('img').map((image) => image.attributes('alt'))).toEqual(['', '']);
        expect(wrapper.findAll('img').map((image) => image.classes())).toEqual([
            expect.arrayContaining(['dark:hidden']),
            expect.arrayContaining(['hidden', 'dark:block']),
        ]);
        expect(wrapper.text()).toBe('');
        expect(wrapper.get('svg').classes()).toContain('group-data-[collapsible=icon]:block');
    });

    it('loads instance brand assets from the configured SHIFT URL', () => {
        runtimeWindow().shiftConfig = {
            shiftUrl: 'https://shift.example.test/',
        };

        const wrapper = mount(AppLogo);
        const imageSources = wrapper.findAll('img').map((image) => image.attributes('src'));

        expect(imageSources).toEqual([
            'https://shift.example.test/brand/shift-logo.svg',
            'https://shift.example.test/brand/shift-wordmark-black.svg',
            'https://shift.example.test/brand/shift-wordmark-white.svg',
        ]);
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it('falls back to the hosted SHIFT URL when the SDK config omits shiftUrl', () => {
        runtimeWindow().shiftConfig = {};

        const wrapper = mount(AppLogo);
        const imageSources = wrapper.findAll('img').map((image) => image.attributes('src'));

        expect(imageSources).toEqual([
            'https://shift.wyxos.com/brand/shift-logo.svg',
            'https://shift.wyxos.com/brand/shift-wordmark-black.svg',
            'https://shift.wyxos.com/brand/shift-wordmark-white.svg',
        ]);
    });

    it('uses a solid symbol without the old low-contrast gradient and accepts sizing', () => {
        const wrapper = mount(AppLogoIcon, { attrs: { class: 'size-8' } });
        expect(wrapper.get('svg').classes()).toContain('size-8');
        expect(wrapper.get('svg').attributes('viewBox')).toBe('0 0 132 132');
        expect(wrapper.find('linearGradient').exists()).toBe(false);
        expect(wrapper.get('path').attributes('fill')).toBe('#6798FF');
    });
});
