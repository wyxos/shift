import LinkedAccountsManager from '@/pages/ExternalUsers/LinkedAccountsManager.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

const axiosPostMock = vi.fn();
const axiosDeleteMock = vi.fn();
const toastSuccessMock = vi.fn();

vi.mock('@/components/ui/button', () => ({
    Button: {
        props: ['disabled'],
        emits: ['click'],
        render() {
            return h(
                'button',
                {
                    ...this.$attrs,
                    disabled: this.disabled,
                    onClick: (event: MouseEvent) => this.$emit('click', event),
                },
                this.$slots.default?.(),
            );
        },
    },
}));

vi.mock('@/components/ui/select', () => ({
    Select: {
        props: ['modelValue', 'options', 'placeholder', 'testId', 'disabled'],
        emits: ['update:modelValue'],
        render() {
            const options = Array.isArray((this as any).options) ? (this as any).options : [];

            return h(
                'select',
                {
                    'data-testid': (this as any).testId,
                    disabled: (this as any).disabled,
                    value: (this as any).modelValue ?? '',
                    onChange: (event: Event) => (this as any).$emit('update:modelValue', (event.target as HTMLSelectElement).value),
                },
                [
                    h('option', { value: '' }, (this as any).placeholder),
                    ...options.map((option: any) => h('option', { value: option.value }, option.label)),
                ],
            );
        },
    },
}));

vi.mock('axios', () => ({
    default: {
        delete: (...args: unknown[]) => axiosDeleteMock(...args),
        post: (...args: unknown[]) => axiosPostMock(...args),
    },
}));

vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccessMock(...args),
    },
}));

describe('ExternalUsers/LinkedAccountsManager.vue', () => {
    const linkedAccount = {
        id: 12,
        label: 'Portal SSO',
        email: 'linked@example.com',
        environment: 'Development',
        url: 'https://portal-v3.example.com',
        unlink_url: '/external-users/7/linked-accounts/12',
        can_unlink: true,
    };
    const productionAccount = {
        id: 13,
        label: 'Production Login',
        email: 'prod@example.com',
        environment: 'Production',
        url: 'https://portal.example.com',
    };
    const externalUser = {
        id: 7,
        name: 'Client QA',
        email: 'qa@example.com',
        environment: 'Staging',
        role: 'owner',
        role_label: 'Client Owner',
        project: { id: 2, name: 'Portal' },
        linked_accounts: [linkedAccount],
        linkable_accounts: [productionAccount],
        links: {
            link_accounts: '/external-users/7/linked-accounts',
        },
    };

    it('emits refreshed state and confirms successful link and unlink requests', async () => {
        axiosPostMock.mockReset();
        axiosDeleteMock.mockReset();
        toastSuccessMock.mockReset();

        const linkedState = {
            ...externalUser,
            linked_accounts: [
                linkedAccount,
                {
                    ...productionAccount,
                    unlink_url: '/external-users/7/linked-accounts/13',
                    can_unlink: true,
                },
            ],
            linkable_accounts: [],
        };
        const unlinkedState = {
            ...externalUser,
            linked_accounts: linkedState.linked_accounts.slice(1),
            linkable_accounts: [linkedAccount],
        };
        axiosPostMock.mockResolvedValue({ data: { external_user: linkedState } });
        axiosDeleteMock.mockResolvedValue({ data: { external_user: unlinkedState } });

        const wrapper = mount(LinkedAccountsManager, {
            props: {
                canManageLinkedAccounts: true,
                externalUser,
            },
        });

        expect(wrapper.get('[data-testid="external-user-link-account-select"]').text()).toContain('portal.example.com');
        expect(wrapper.get('[data-testid="external-user-linked-account-12"]').text()).toContain('portal-v3.example.com');

        await wrapper.get('[data-testid="external-user-link-account-select"]').setValue('13');
        await wrapper.get('[data-testid="external-user-link-account-submit"]').trigger('click');
        await flushPromises();

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/external-users/7/linked-accounts',
            { linked_external_user_id: '13' },
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(wrapper.emitted('changed')?.[0]).toEqual([linkedState]);
        expect(toastSuccessMock).toHaveBeenCalledWith('Account linked');

        await wrapper.setProps({ externalUser: linkedState });
        await wrapper.get('[data-testid="external-user-linked-account-unlink-12"]').trigger('click');
        await flushPromises();

        expect(axiosDeleteMock).toHaveBeenCalledWith(
            '/external-users/7/linked-accounts/12',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(wrapper.emitted('changed')?.[1]).toEqual([unlinkedState]);
        expect(toastSuccessMock).toHaveBeenCalledWith('Account unlinked');
    });

    it('excludes the current account and shows specific server failure feedback', async () => {
        axiosPostMock.mockReset();
        toastSuccessMock.mockReset();
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        axiosPostMock.mockRejectedValue({
            response: {
                data: {
                    message: 'These accounts cannot be linked.',
                },
            },
        });

        const wrapper = mount(LinkedAccountsManager, {
            props: {
                canManageLinkedAccounts: true,
                externalUser: {
                    ...externalUser,
                    linkable_accounts: [
                        {
                            id: externalUser.id,
                            label: 'Current account',
                            email: externalUser.email,
                            environment: externalUser.environment,
                            url: 'https://staging.example.com',
                        },
                        productionAccount,
                    ],
                },
            },
        });

        const linkOptions = wrapper
            .get('[data-testid="external-user-link-account-select"]')
            .findAll('option')
            .map((option) => option.attributes('value'));
        expect(linkOptions).not.toContain(String(externalUser.id));

        await wrapper.get('[data-testid="external-user-link-account-select"]').setValue('13');
        await wrapper.get('[data-testid="external-user-link-account-submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('These accounts cannot be linked.');
        expect(toastSuccessMock).not.toHaveBeenCalled();

        consoleError.mockRestore();
    });
});
