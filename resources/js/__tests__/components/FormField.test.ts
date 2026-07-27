import { FormField } from '@/components/ui/form-field';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';

describe('FormField', () => {
    it('links its label, control, and visible error', () => {
        const wrapper = mount(FormField, {
            props: {
                id: 'email',
                label: 'Email address',
                error: 'Enter a valid email address.',
            },
            slots: {
                default: ({ controlAttrs }: { controlAttrs: Record<string, unknown> }) => h('input', controlAttrs),
            },
        });

        const input = wrapper.get('input');
        const error = wrapper.get('#email-error');

        expect(wrapper.get('label').attributes('for')).toBe('email');
        expect(input.attributes('id')).toBe('email');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe('email-error');
        expect(error.attributes('role')).toBe('alert');
        expect(error.attributes('aria-live')).toBe('polite');
    });

    it('does not describe an error when the field is valid', () => {
        const wrapper = mount(FormField, {
            props: {
                id: 'name',
                label: 'Name',
            },
            slots: {
                default: ({ controlAttrs }: { controlAttrs: Record<string, unknown> }) => h('input', controlAttrs),
            },
        });

        const input = wrapper.get('input');

        expect(input.attributes('aria-invalid')).toBe('false');
        expect(input.attributes()).not.toHaveProperty('aria-describedby');
    });
});
