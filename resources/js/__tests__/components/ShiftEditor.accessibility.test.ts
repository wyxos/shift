import ShiftEditor from '@/components/ShiftEditor.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

describe('ShiftEditor accessibility', () => {
    it('names the editable field and updates its purpose when entering comment edit mode', async () => {
        const wrapper = mount(ShiftEditor, { props: { accessibleLabel: 'Write a comment' } });
        await nextTick();
        await nextTick();
        const field = wrapper.get('.ProseMirror');
        expect(field.attributes('role')).toBe('textbox');
        expect(field.attributes('aria-multiline')).toBe('true');
        expect(field.attributes('aria-label')).toBe('Write a comment');
        await wrapper.setProps({ accessibleLabel: 'Edit comment' });
        expect(field.attributes('aria-label')).toBe('Edit comment');
        wrapper.unmount();
    });
});
