import AccessUserPicker from '@/components/admin/AccessUserPicker.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const candidates = [
    { id: 1, name: 'Casey Founder', email: 'casey.founder@example.com' },
    { id: 2, name: 'Jordan Viewer', email: 'jordan.viewer@example.com' },
];

function mountPicker() {
    return mount(AccessUserPicker, {
        props: {
            candidates,
            email: '',
            errors: {},
            name: '',
            testIdPrefix: 'access-user',
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
});
