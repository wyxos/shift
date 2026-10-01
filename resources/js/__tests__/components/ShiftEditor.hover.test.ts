import ShiftEditor from '@/components/ShiftEditor.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ShiftEditor toolbar appearance', () => {
    it('uses theme-aware hover surfaces and labels every icon action', () => {
        const wrapper = mount(ShiftEditor, { props: { cancelable: true } });

        for (const action of ['emoji', 'attachment', 'ai-improve', 'cancel', 'send']) {
            const button = wrapper.get(`[data-testid="toolbar-${action}"]`);
            expect(button.classes()).toContain('hover:bg-accent');
            expect(button.classes()).not.toContain('hover:bg-gray-100');
        }
        expect(wrapper.get('[data-testid="toolbar-attachment"]').attributes('aria-label')).toBe('Attach file');
        expect(wrapper.get('[data-testid="toolbar-send"]').attributes('aria-label')).toBe('Send message');
        wrapper.unmount();
    });
});
