import Welcome from '@/pages/Welcome.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const { usePageMock } = vi.hoisted(() => ({
    usePageMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: usePageMock,
}));

// Welcome.vue is a thin wrapper around Home.vue; test that it forwards the shared auth prop.
vi.mock('@/pages/Home.vue', () => ({
    default: {
        props: ['auth'],
        render() {
            return h('div', { 'data-test': 'home' }, this.auth?.user ? 'authed' : 'anon');
        },
    },
}));

describe('Welcome.vue', () => {
    it('forwards anonymous auth into Home', () => {
        usePageMock.mockReturnValue({
            props: {
                auth: { user: null },
            },
        });
        const wrapper = mount(Welcome);

        expect(wrapper.find('[data-test="home"]').text()).toBe('anon');
    });

    it('forwards authenticated user into Home', () => {
        usePageMock.mockReturnValue({
            props: {
                auth: { user: { id: 1 } },
            },
        });
        const wrapper = mount(Welcome);

        expect(wrapper.find('[data-test="home"]').text()).toBe('authed');
    });
});
