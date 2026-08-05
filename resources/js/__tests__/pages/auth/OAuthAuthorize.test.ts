import OAuthAuthorize from '@/pages/auth/OAuth/Authorize.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
}));

vi.mock('@/layouts/AuthLayout.vue', () => ({
    default: {
        props: ['title', 'description'],
        render() {
            return h('main', {}, [h('h1', {}, this.title), h('p', {}, this.description), this.$slots.default?.()]);
        },
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['type', 'variant'],
        render() {
            return h('button', { type: this.type }, this.$slots.default?.());
        },
    },
}));

vi.mock('lucide-vue-next', () => ({
    Check: { render: () => h('span') },
    ShieldCheck: { render: () => h('span') },
}));

describe('auth/OAuth/Authorize.vue', () => {
    it('renders requested scopes and submits explicit approve or deny decisions', () => {
        const wrapper = mount(OAuthAuthorize, {
            props: {
                client: { name: 'Codex Desktop' },
                scopes: [
                    { id: 'mcp:read', description: 'Read MCP-visible SHIFT data.' },
                    { id: 'mcp:write', description: 'Create and edit MCP-visible SHIFT data.' },
                ],
                authToken: 'authorization-session-token',
                csrfToken: 'csrf-token',
                approveUrl: '/oauth/authorize',
                denyUrl: '/oauth/authorize',
            },
        });

        expect(wrapper.text()).toContain('Codex Desktop is requesting access to your SHIFT account.');
        expect(wrapper.text()).toContain('OAuth-secured connection');
        expect(wrapper.text()).toContain('mcp:read');
        expect(wrapper.text()).toContain('mcp:write');

        const forms = wrapper.findAll('form');

        expect(forms).toHaveLength(2);
        expect(forms[0].attributes('method')).toBe('post');
        expect(forms[0].get('input[name="_method"]').attributes('value')).toBe('DELETE');
        expect(forms[0].get('input[name="auth_token"]').attributes('value')).toBe('authorization-session-token');
        expect(forms[1].get('input[name="auth_token"]').attributes('value')).toBe('authorization-session-token');
    });
});
