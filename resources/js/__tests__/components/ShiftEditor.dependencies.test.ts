import ShiftEditor from '@/shared/components/ShiftEditor.vue';
import { Editor, mergeAttributes } from '@tiptap/core';
import { DOMSerializer, Schema } from '@tiptap/pm/model';
import StarterKit from '@tiptap/starter-kit';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

describe('ShiftEditor dependency contracts', () => {
    it('replaces content in one silent transaction and keeps subsequent edits and undo working', () => {
        const onUpdate = vi.fn();
        const onTransaction = vi.fn();
        const editor = new Editor({
            extensions: [StarterKit],
            content: '<p>Original draft</p>',
            onUpdate,
            onTransaction,
        });

        editor.chain().setContent('<p>Restored draft</p>').setMeta('preventUpdate', true).run();

        expect(editor.getHTML()).toBe('<p>Restored draft</p>');
        expect(onUpdate).not.toHaveBeenCalled();
        expect(onTransaction).toHaveBeenCalledOnce();
        expect(editor.state.selection.from).toBeGreaterThanOrEqual(1);
        expect(editor.state.selection.to).toBeLessThanOrEqual(editor.state.doc.content.size);

        editor.commands.insertContent('!');
        expect(editor.getText()).toContain('!');
        expect(onUpdate).toHaveBeenCalledOnce();
        const edited = editor.getHTML();
        expect(editor.commands.undo()).toBe(true);
        expect(editor.getHTML()).toBe('<p>Original draft</p>');
        expect(editor.commands.redo()).toBe(true);
        expect(editor.getHTML()).toBe(edited);
        editor.destroy();
    });

    it('does not inherit executable DOM attributes from JSON prototype keys', () => {
        const input = JSON.parse('{"__proto__":{"data-inherited-canary":"present","src":"invalid://canary","onerror":"alert(1)"}}');
        const attributes = mergeAttributes(input);
        const schema = new Schema({
            nodes: {
                doc: { content: 'image' },
                image: { toDOM: () => ['img', attributes] },
                text: {},
            },
        });
        const documentNode = schema.node('doc', null, [schema.node('image')]);
        const fragment = DOMSerializer.fromSchema(schema).serializeFragment(documentNode.content);
        const image = fragment.querySelector('img');

        expect(Object.getPrototypeOf(attributes)).toBe(Object.prototype);
        expect(attributes.src).toBeUndefined();
        expect(attributes.onerror).toBeUndefined();
        expect(image).not.toBeNull();
        expect(image?.hasAttribute('data-inherited-canary')).toBe(false);
        expect(image?.hasAttribute('src')).toBe(false);
        expect(image?.hasAttribute('onerror')).toBe(false);
    });

    it('replaces externally supplied content without echoing a model update', async () => {
        const wrapper = mount(ShiftEditor, { props: { modelValue: '<p>Original draft</p>' } });
        await nextTick();
        await nextTick();
        await wrapper.setProps({ modelValue: '<p>Restored draft</p>' });

        expect(wrapper.get('.ProseMirror').text()).toBe('Restored draft');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        wrapper.unmount();
    });

    it('clears content with exactly one empty model update', async () => {
        const wrapper = mount(ShiftEditor, { props: { modelValue: '<p>Comment to send</p>' } });
        await nextTick();
        await nextTick();
        wrapper.vm.reset();
        await nextTick();
        await nextTick();

        expect(wrapper.get('.ProseMirror').text()).toBe('');
        expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
        wrapper.unmount();
    });

    it('keeps the existing editor schema and document behavior', async () => {
        const wrapper = mount(ShiftEditor, { props: { modelValue: '<p>https://example.test</p>' } });
        await nextTick();
        await nextTick();

        const names = wrapper.vm.editor?.extensionManager.extensions.map((extension) => extension.name);
        expect(names).not.toContain('link');
        expect(names).not.toContain('underline');
        expect(names).not.toContain('listKeymap');
        expect(names).not.toContain('trailingNode');
        expect(wrapper.get('.ProseMirror').find('a').exists()).toBe(false);
        wrapper.unmount();
    });
});
