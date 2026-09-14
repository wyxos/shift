import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

// The outlined wordmark assets include the symbol in place of the initial S.
describe('SHIFT branding', () => {
    it('provides accessible branding with light and dark wordmarks', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('SHIFT');
        expect(wrapper.findAll('img')).toHaveLength(2);
        expect(wrapper.findAll('img').map((image) => image.attributes('alt'))).toEqual(['', '']);
        expect(wrapper.findAll('img').map((image) => image.classes())).toEqual([
            expect.arrayContaining(['dark:hidden']),
            expect.arrayContaining(['hidden', 'dark:block']),
        ]);
        expect(wrapper.text()).toBe('');
        expect(wrapper.get('svg').classes()).toContain('group-data-[collapsible=icon]:block');
    });

    it('uses a solid symbol without the old low-contrast gradient and accepts sizing', () => {
        const wrapper = mount(AppLogoIcon, { attrs: { class: 'size-8' } });
        expect(wrapper.get('svg').classes()).toContain('size-8');
        expect(wrapper.get('svg').attributes('viewBox')).toBe('0 0 132 132');
        expect(wrapper.find('linearGradient').exists()).toBe(false);
        expect(wrapper.get('path').attributes('fill')).toBe('#6798FF');
    });
});
