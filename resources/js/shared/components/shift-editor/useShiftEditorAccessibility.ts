import type { Editor } from '@tiptap/core';
import { watch, type Ref } from 'vue';

export function editorAccessibilityAttributes(label: string) {
    return { class: 'shift-scrollbar', role: 'textbox', 'aria-multiline': 'true', 'aria-label': label };
}

export function useShiftEditorAccessibility(editor: Ref<Editor | undefined>, label: () => string) {
    watch(label, (value) => {
        if (!editor.value) return;
        editor.value.setOptions({
            editorProps: { ...editor.value.options.editorProps, attributes: editorAccessibilityAttributes(value) },
        });
    });
}
