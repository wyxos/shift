import AccessUserPicker from '@/components/admin/AccessUserPicker.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const candidates = [
    { id: 1, name: 'Casey Founder', email: 'casey.founder@example.com' },
    { id: 2, name: 'Jordan Viewer', email: 'jordan.viewer@example.com' },
];

function mountPicker(props: Record<string, unknown> = {}) {
    return mount(AccessUserPicker, {
        props: {
            candidates,
            email: '',
            errors: {},
            name: '',
            testIdPrefix: 'access-user',
            ...props,
        },
    });
}

describe('AccessUserPicker', () => {
    it('does not show suggestions on initial focus', async () => {
        const wrapper = mountPicker();

        await wrapper.get('[data-testid="access-user-email"]').trigger('focus');

        expect(wrapper.find('[data-testid="access-user-candidate-1"]').exists()).toBe(false);
    });

    it('shows suggestions after typing', async () => {
        const wrapper = mountPicker();
        const input = wrapper.get('[data-testid="access-user-email"]');

        await input.setValue('casey');

        expect(wrapper.find('[data-testid="access-user-candidate-1"]').exists()).toBe(true);
    });

    it('shows suggestions after manually clicking the field', async () => {
        const wrapper = mountPicker();
        const input = wrapper.get('[data-testid="access-user-email"]');

        await input.trigger('click');

        expect(wrapper.find('[data-testid="access-user-candidate-2"]').exists()).toBe(true);
    });

    it('exposes combobox, listbox, and option semantics', async () => {
        const wrapper = mountPicker();
        const input = wrapper.get('[data-testid="access-user-email"]');

        await input.trigger('click');

        expect(input.attributes('role')).toBe('combobox');
        expect(input.attributes('aria-autocomplete')).toBe('list');
        expect(input.attributes('aria-expanded')).toBe('true');
        expect(input.attributes('aria-controls')).toBe('access-user-listbox');
        expect(wrapper.get('[role="listbox"]').attributes('id')).toBe('access-user-listbox');
        expect(wrapper.findAll('[role="option"]')).toHaveLength(2);
    });

    it('selects a candidate through a click event', async () => {
        const wrapper = mountPicker();

        await wrapper.get('[data-testid="access-user-email"]').setValue('casey');
        await wrapper.get('[data-testid="access-user-candidate-1"]').trigger('click');

        expect(wrapper.emitted('update:email')?.at(-1)).toEqual(['casey.founder@example.com']);
        expect(wrapper.emitted('update:name')?.at(-1)).toEqual(['Casey Founder']);
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('moves through options and selects with Arrow and Enter keys', async () => {
        const wrapper = mountPicker();
        const input = wrapper.get('[data-testid="access-user-email"]');

        await input.trigger('keydown', { key: 'ArrowDown' });

        expect(input.attributes('aria-activedescendant')).toBe('access-user-candidate-option-1');
        expect(wrapper.get('[data-testid="access-user-candidate-1"]').attributes('aria-selected')).toBe('true');

        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(input.attributes('aria-activedescendant')).toBe('access-user-candidate-option-2');

        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:email')?.at(-1)).toEqual(['jordan.viewer@example.com']);
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('closes suggestions with Escape and links errors to the input', async () => {
        const wrapper = mountPicker({
            errors: {
                email: 'Enter an email address.',
                name: 'Enter a name.',
            },
        });
        const input = wrapper.get('[data-testid="access-user-email"]');

        await input.trigger('click');
        await input.trigger('keydown', { key: 'Escape' });

        expect(input.attributes('aria-expanded')).toBe('false');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe('access-user-error-email access-user-error-name');
        expect(wrapper.findAll('[role="alert"]')).toHaveLength(2);
    });
});
