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
    },
    autoGrow: {
        type: Boolean,
        default: false
    },
    minHeight: {
        type: String,
        default: '150px'
    },
    maxHeight: {
        type: String,
        default: '600px'
    }
});

const emit = defineEmits(['update:modelValue', 'enter']);

const editorRef = ref<HTMLElement | null>(null);
let editor: Editor | null = null;
let keydownHandler: ((event: KeyboardEvent) => void) | null = null;

function toPxNumber(value: string): number {
    if (!value) return 0;
    if (value.endsWith('px')) return parseInt(value, 10);
    const n = parseInt(value, 10);
    return isNaN(n) ? 0 : n;
}

function clamp(n: number, min: number, max: number): number {
    return Math.max(min, Math.min(max, n));
}

function autoResize() {
    if (!editor || !props.autoGrow) return;
    try {
        const content = editor.getMarkdown() || '';
        const lines = content.split('\n').length || 1;
        const lineHeight = 22; // approx line height in px
        const chrome = 140; // toolbar + paddings approximation
        const desired = chrome + lines * lineHeight;
        const minH = toPxNumber(props.minHeight || props.height);
        const maxH = toPxNumber(props.maxHeight);
        const newH = clamp(desired, minH, maxH);
        editor.setHeight(`${newH}px`);
    } catch (e) {
        // fail-safe: ignore sizing errors
    }
}

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
                if (props.autoGrow) {
                    autoResize();
                }
            }
        });

        editor.on('keydown', (editor: Editor, event: KeyboardEvent) => {
            if (event.key === 'Enter') {
                if (!event.shiftKey) {
                    // Enter without Shift: emit enter event and prevent default
                    event.preventDefault();
                    emit('enter');
                }
                // Shift+Enter: allow default behavior (new line)
            }
        });

        // Initial sizing
        if (props.autoGrow) {
            setTimeout(() => autoResize(), 0);
        }
    }
});

// Watch for external changes to modelValue
watch(() => props.modelValue, (newValue) => {
    if (editor && newValue !== editor.getMarkdown()) {
        editor.setMarkdown(newValue);
        if (props.autoGrow) {
            autoResize();
        }
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
