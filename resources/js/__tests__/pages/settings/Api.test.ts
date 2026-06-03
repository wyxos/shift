import Api from '@/pages/settings/Api.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiMocks = vi.hoisted(() => ({
    axiosPost: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        post: apiMocks.axiosPost,
    },
}));

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        template: '<div class="app-layout"><slot /></div>',
    },
}));

vi.mock('@/layouts/settings/Layout.vue', () => ({
    default: {
        template: '<div class="settings-layout"><slot /></div>',
    },
}));

vi.mock('@/components/HeadingSmall.vue', () => ({
    default: {
        props: ['title', 'description'],
        template: '<div><h2>{{ title }}</h2><p>{{ description }}</p></div>',
    },
}));

vi.mock('@/components/InputError.vue', () => ({
    default: {
        props: ['message'],
        template: '<p v-if="message">{{ message }}</p>',
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'type'],
        inheritAttrs: false,
        template: '<button v-bind="$attrs" :disabled="disabled" :type="type || `button`"><slot /></button>',
    },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'type'],
        emits: ['update:modelValue'],
        inheritAttrs: false,
        template: '<input v-bind="$attrs" :type="type" :value="modelValue" @input="$emit(`update:modelValue`, $event.target.value)" />',
    },
}));

vi.mock('@/components/ui/label', () => ({
    Label: {
        template: '<label><slot /></label>',
    },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');

    return {
        Head: defineComponent({
            setup() {
                return () => null;
            },
        }),
        useForm: vi.fn((initialValues: Record<string, unknown>) => {
            const form = reactive({
                ...initialValues,
                errors: {},
                processing: false,
                recentlySuccessful: false,
                put: vi.fn((_url: string, options?: Record<string, any>) => options?.onSuccess?.()),
                reset: vi.fn(),
            });

            return form;
        }),
    };
});

describe('settings/Api.vue', () => {
    beforeEach(() => {
        apiMocks.axiosPost.mockReset();
        apiMocks.axiosPost.mockResolvedValue({
            data: {
                token: 'new-visible-token',
                record: {
                    id: 99,
                    name: 'shift-mcp',
                    created_at: '2026-06-03T10:00:00.000000Z',
                    last_used_at: null,
                },
            },
        });
    });

    function mountPage() {
        return mount(Api, {
            props: {
                token: '',
                mcpTokens: [
                    {
                        id: 1,
                        name: 'old-mcp',
                        created_at: '2026-06-01T10:00:00.000000Z',
                        last_used_at: null,
                    },
                ],
                sdkTokens: [
                    {
                        id: 2,
                        name: 'shift-sdk-install:12:20260601010101',
                        project: {
                            id: 12,
                            name: 'Portal Integration',
                        },
                        created_at: '2026-06-01T10:00:00.000000Z',
                        last_used_at: null,
                    },
                ],
            },
        });
    }

    it('renders and resets personal MCP and SDK tokens', async () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('MCP token');
        expect(wrapper.text()).toContain('old-mcp');
        expect(wrapper.text()).toContain('SHIFT SDK tokens');
        expect(wrapper.text()).toContain('Portal Integration');

        await wrapper.get('[data-testid="reset-mcp-token"]').trigger('click');
        await flushPromises();

        expect(apiMocks.axiosPost).toHaveBeenCalledWith(
            '/settings/api/tokens/mcp/reset',
            {},
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(wrapper.text()).toContain('new-visible-token');

        await wrapper.get('[data-testid="reset-sdk-token-2"]').trigger('click');
        await flushPromises();

        expect(apiMocks.axiosPost).toHaveBeenLastCalledWith(
            '/settings/api/tokens/sdk/2/reset',
            {},
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
    });
});
