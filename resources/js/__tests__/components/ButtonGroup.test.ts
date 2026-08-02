import { ButtonGroup } from '@/components/ui/button-group';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ButtonGroup', () => {
    it('uses roving focus and arrow keys for single-select groups', async () => {
        const wrapper = mount(ButtonGroup, {
            props: {
                modelValue: 'all',
                ariaLabel: 'Environment',
                options: [
                    { value: 'all', label: 'All' },
                    { value: 'production', label: 'Production' },
                    { value: 'staging', label: 'Staging' },
                ],
            },
            attachTo: document.body,
        });

        const buttons = wrapper.findAll('[role="radio"]');
        expect(buttons.map((button) => button.attributes('tabindex'))).toEqual(['0', '-1', '-1']);

        await buttons[0].trigger('keydown', { key: 'ArrowRight' });

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['production']);
        expect(document.activeElement).toBe(buttons[1].element);
        await wrapper.setProps({ modelValue: 'production' });
        expect(buttons[1].attributes('aria-checked')).toBe('true');

        wrapper.unmount();
    });
});
