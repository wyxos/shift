import Verify from '@/pages/SdkInstall/Verify.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertiaMocks = vi.hoisted(() => ({
    routerGet: vi.fn(),
    forms: [] as Array<Record<string, any>>,
}));

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value));
}

vi.mock('@/layouts/AppLayout.vue', () => ({
    default: {
        props: ['breadcrumbs'],
        template: '<div class="app-layout"><slot /></div>',
    },
}));

vi.mock('@/components/InputError.vue', () => ({
    default: {
        props: ['message'],
        template: '<p v-if="message" class="input-error">{{ message }}</p>',
    },
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: {
        props: ['variant'],
        template: '<span :data-variant="variant"><slot /></span>',
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['variant', 'disabled', 'type'],
        inheritAttrs: false,
        template: '<button v-bind="$attrs" :disabled="disabled" :type="type"><slot /></button>',
    },
}));

vi.mock('@/components/ui/card', () => ({
    Card: { template: '<div class="card"><slot /></div>' },
    CardContent: { template: '<div class="card-content"><slot /></div>' },
    CardDescription: { template: '<p class="card-description"><slot /></p>' },
    CardHeader: { template: '<div class="card-header"><slot /></div>' },
    CardTitle: { template: '<h2 class="card-title"><slot /></h2>' },
}));

vi.mock('@/components/ui/input', () => ({
    Input: {
        props: ['modelValue', 'id', 'autocomplete', 'placeholder'],
        emits: ['update:modelValue'],
        inheritAttrs: false,
        template:
            '<input v-bind="$attrs" :id="id" :value="modelValue" :autocomplete="autocomplete" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
}));

vi.mock('@/components/ui/label', () => ({
    Label: {
        props: ['for'],
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
        router: {
            get: inertiaMocks.routerGet,
        },
        useForm: vi.fn((initialValues: Record<string, unknown>) => {
            const initial = clone(initialValues);
            const form = reactive({
                ...clone(initialValues),
                errors: {},
                processing: false,
                post: vi.fn(),
                reset: vi.fn(() => {
                    Object.assign(form, clone(initial), { errors: {}, processing: false });
                }),
            });

            inertiaMocks.forms.push(form as Record<string, any>);

            return form;
        }),
    };
});

describe('SdkInstall/Verify.vue', () => {
    beforeEach(() => {
        inertiaMocks.routerGet.mockReset();
        inertiaMocks.forms.length = 0;

        vi.stubGlobal('route', (name: string) => {
            if (name === 'sdk-install.verify') {
                return '/sdk/install';
            }

            if (name === 'sdk-install.approve') {
                return '/sdk/install/approve';
            }

            return name;
        });
    });

    it('looks up an install request using the entered user code', async () => {
        const wrapper = mount(Verify, {
            props: {
                userCode: null,
                lookupError: null,
                session: null,
            },
        });

        await wrapper.get('#user_code').setValue('ABCD-EFGH');
        await wrapper.get('form').trigger('submit.prevent');

        expect(inertiaMocks.routerGet).toHaveBeenCalledWith(
            '/sdk/install',
            { user_code: 'ABCD-EFGH' },
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }),
        );
    });

    it('renders pending install details and approves the active session code', async () => {
        const wrapper = mount(Verify, {
            props: {
                userCode: 'ABCD-EFGH',
                lookupError: null,
                session: {
                    user_code: 'ABCD-EFGH',
                    state: 'pending',
                    environment: 'staging',
                    environment_label: 'Staging',
                    url: 'https://client-app.test',
                    expires_at: '2026-03-21T12:00:00Z',
                    approved: null,
                },
            },
        });

        expect(wrapper.text()).toContain('Install request ABCD-EFGH');
        expect(wrapper.text()).toContain('Staging');
        expect(wrapper.text()).toContain('https://client-app.test');
        expect(wrapper.text()).toContain('Pending');

        const approveForm = inertiaMocks.forms[1];

        await wrapper.get('div.space-y-3 button').trigger('click');

        expect(approveForm.user_code).toBe('ABCD-EFGH');
        expect(approveForm.post).toHaveBeenCalledWith(
            '/sdk/install/approve',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );
    });

    it('shows the rebound warning when another user already approved the install', () => {
        const wrapper = mount(Verify, {
            props: {
                userCode: 'ABCD-EFGH',
                lookupError: null,
                session: {
                    user_code: 'ABCD-EFGH',
                    state: 'approved',
                    environment: 'production',
                    environment_label: 'Production',
                    url: 'https://client-app.test',
                    expires_at: '2026-03-21T12:00:00Z',
                    approved: {
                        at: '2026-03-21T10:00:00Z',
                        by_current_user: false,
                    },
                },
            },
        });

        expect(wrapper.text()).toContain('Approved');
        expect(wrapper.text()).toContain('already approved by another SHIFT user');
    });
});
