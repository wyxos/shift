import TaskErrorComponentFilter from '@/components/tasks/index/TaskErrorComponentFilter.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('SDK error filtering', () => {
    it('offers application and SDK filters with keyboard-accessible choices', async () => {
        const wrapper = mount(TaskErrorComponentFilter, { props: { modelValue: '' } });
        await wrapper.get('[data-testid="error-component-sdk"]').trigger('click');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['shift_sdk']);
        await wrapper.setProps({ modelValue: 'shift_sdk' });
        expect(wrapper.get('[data-testid="error-component-sdk"]').attributes('aria-checked')).toBe('true');
        await wrapper.get('[data-testid="error-component-application"]').trigger('click');
        expect(wrapper.emitted('update:modelValue')?.[1]).toEqual(['application']);
    });
});
