<script lang="ts" setup>
import { Editor } from '@toast-ui/editor';
import '@toast-ui/editor/dist/toastui-editor.css';
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    height: {
        type: String,
        default: '300px'
    },
    placeholder: {
        type: String,
        default: 'Write your content here...'
    }
});

const emit = defineEmits(['update:modelValue', 'enter']);

const editorRef = ref<HTMLElement | null>(null);
let editor: Editor | null = null;
let keydownHandler: ((event: KeyboardEvent) => void) | null = null;

onMounted(() => {
    if (editorRef.value) {
        // Initialize the editor with markdown only mode
        editor = new Editor({
            el: editorRef.value,
            height: props.height,
            initialEditType: 'markdown', // Set to markdown mode
            hideModeSwitch: true, // Hide the mode switch to enforce markdown only
            placeholder: props.placeholder,
            initialValue: props.modelValue || ''
        });

        // Listen for changes and emit them to the parent
        editor.on('change', () => {
            if (editor) {
                emit('update:modelValue', editor.getMarkdown());
            }
        });

        // // Get the editor's DOM element
        // const editorEl = editor.getUI().getEl();
        //
        // // Create keydown handler function
        // keydownHandler = (event: KeyboardEvent) => {
        //     if (event.key === 'Enter') {
        //         if (!event.shiftKey) {
        //             // Enter without Shift: emit enter event and prevent default
        //             event.preventDefault();
        //             emit('enter');
        //         }
        //         // Shift+Enter: allow default behavior (new line)
        //     }
        // };
        //
        // // Add keydown event listener for Enter and Shift+Enter
        // editorEl.addEventListener('keydown', keydownHandler);

        editor.on('keydown', (editor: Editor, event: KeyboardEvent) => {
            console.log('Key pressed:', event);
            if (event.key === 'Enter') {
                if (!event.shiftKey) {
                    // Enter without Shift: emit enter event and prevent default
                    event.preventDefault();
                    emit('enter');
                }
                // Shift+Enter: allow default behavior (new line)
            }
        });
    }
});

// Watch for external changes to modelValue
watch(() => props.modelValue, (newValue) => {
    if (editor && newValue !== editor.getMarkdown()) {
        editor.setMarkdown(newValue);
    }
});

// Clean up on component unmount
onBeforeUnmount(() => {
    if (editor) {
        // Remove the keydown event listener
        if (keydownHandler) {
            const editorEl = editor.getUI().getEl();
            editorEl.removeEventListener('keydown', keydownHandler);
            keydownHandler = null;
        }

        editor.off('change');
        editor.destroy();
        editor = null;
    }
});
</script>

<template>
    <div class="markdown-editor">
        <div ref="editorRef"></div>
    </div>
</template>

<style>
.markdown-editor {
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
}
</style>
