import ShiftEditor from '@/components/ShiftEditor.vue';
import { mount } from '@vue/test-utils';
import { expect, it } from 'vitest';
import { nextTick } from 'vue';

it('keeps the quote read-only and removes it without discarding reply text', async () => {
    const wrapper = mount(ShiftEditor, {
        props: {
            modelValue: '<blockquote class="shift-reply" data-reply-to="42"><p>Original message</p></blockquote><p>My reply</p>',
        },
    });
    await nextTick();

    await nextTick();

    const quote = wrapper.get('blockquote.shift-reply');
    expect(quote.attributes('contenteditable')).toBe('false');
    const editor: any = (wrapper.vm as any).editor;
    expect(editor.getHTML()).not.toContain('Remove quote');

    await quote.get('button[aria-label="Remove quote"]').trigger('click');

    expect(wrapper.find('blockquote.shift-reply').exists()).toBe(false);
    expect(editor.getHTML()).toBe('<p>My reply</p>');
    expect(wrapper.emitted('update:modelValue')).toContainEqual(['<p>My reply</p>']);
    wrapper.unmount();
});
