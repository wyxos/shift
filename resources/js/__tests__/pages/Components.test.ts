import ComponentsPage from '@/pages/Components.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        render() {
            return h('div', { class: 'app-layout' }, this.$slots.default?.());
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => {} },
}));

beforeEach(() => {
    (global as any).route = () => '/';
});

async function waitForEditor(wrapper: ReturnType<typeof mount>) {
    const start = Date.now();
    while (Date.now() - start < 800) {
        const el = wrapper.find('.ProseMirror');
        if (el.exists()) return el;
        await new Promise((r) => setTimeout(r, 10));
        await nextTick();
    }
    return wrapper.find('.ProseMirror');
}

describe('Components page', () => {
    it('renders the ShiftEditor text area', async () => {
        const wrapper = mount(ComponentsPage);
        await nextTick();

        const editorEl = await waitForEditor(wrapper);
        expect(editorEl.exists()).toBe(true);
    });
});
