import Welcome from '@/pages/Welcome.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

// Welcome.vue is a thin wrapper around Home.vue; test that it forwards $page.props.auth.
vi.mock('@/pages/Home.vue', () => ({
    default: {
        props: ['auth'],
        render() {
            return h('div', { 'data-test': 'home' }, this.auth?.user ? 'authed' : 'anon');
        },
    },
}));

describe('Welcome.vue', () => {
    it('forwards $page.props.auth into Home', () => {
        const wrapper = mount(Welcome, {
            global: {
                mocks: {
                    $page: {
                        props: {
                            auth: { user: null },
                        },
                    },
                },
            },
        });

        expect(wrapper.find('[data-test="home"]').text()).toBe('anon');
    });

    it('forwards authenticated user into Home', () => {
        const wrapper = mount(Welcome, {
            global: {
                mocks: {
                    $page: {
                        props: {
                            auth: { user: { id: 1 } },
                        },
                    },
                },
            },
        });

        expect(wrapper.find('[data-test="home"]').text()).toBe('authed');
    });
});
