import SheetContent from '@/components/ui/sheet/SheetContent.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

vi.mock('@/components/ui/sheet/SheetOverlay.vue', () => ({
    default: {
        render() {
            return h('div', { class: 'sheet-overlay-stub' });
        },
    },
}));

vi.mock('lucide-vue-next', () => ({
    X: {
        render() {
            return h('span', { class: 'close-icon-stub' });
        },
    },
}));

vi.mock('reka-ui', () => ({
    DialogPortal: {
        render() {
            return h('div', { class: 'dialog-portal-stub' }, this.$slots.default?.());
        },
    },
    DialogClose: {
        render() {
            return h('button', { class: 'dialog-close-stub' }, this.$slots.default?.());
        },
    },
    DialogContent: {
        inheritAttrs: false,
        props: ['class', 'style'],
        render() {
            return h(
                'div',
                {
                    ...this.$attrs,
                    class: this.class,
                    style: this.style,
                },
                this.$slots.default?.(),
            );
        },
    },
    useForwardPropsEmits: (props: Record<string, unknown>) => props,
}));

describe('SheetContent', () => {
    it('uses the default desktop fit-content preset for horizontal sheets', () => {
        const wrapper = mount(SheetContent, {
            props: {
                side: 'right',
            },
        });

        const content = wrapper.get('[data-slot="sheet-content"]');

        expect(content.classes()).toContain('xl:w-fit');
        expect(content.classes()).toContain('xl:min-w-[var(--sheet-width-desktop-min)]');
        expect(content.attributes('style')).toContain('--sheet-width-desktop-min: 800px;');
        expect(content.attributes()).not.toHaveProperty('widthpreset');
        expect(content.attributes()).not.toHaveProperty('width-preset');
    });

    it('uses fixed viewport widths for task sheets instead of content-sized widths', () => {
        const wrapper = mount(SheetContent, {
            props: {
                side: 'right',
                widthPreset: 'task',
            },
        });

        const content = wrapper.get('[data-slot="sheet-content"]');

        expect(content.classes()).toContain('xl:w-[var(--sheet-width-desktop)]');
        expect(content.classes()).toContain('min-[1921px]:w-[var(--sheet-width-wide)]');
        expect(content.classes()).not.toContain('min-[1441px]:w-fit');
        expect(content.classes()).not.toContain('min-[1441px]:max-w-fit');
        expect(content.classes()).not.toContain('md:w-[var(--sheet-width-tablet)]');
        expect(content.attributes('style')).toContain('--sheet-width-mobile: 100vw;');
        expect(content.attributes('style')).toContain('--sheet-width-desktop: 75vw;');
        expect(content.attributes('style')).toContain('--sheet-width-wide: 50vw;');
        expect(content.attributes()).not.toHaveProperty('widthpreset');
        expect(content.attributes()).not.toHaveProperty('width-preset');
    });
});
