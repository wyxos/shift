<script lang="ts" setup>
import { Crepe } from "@milkdown/crepe";
import "@milkdown/crepe/theme/common/style.css";
import "@milkdown/crepe/theme/frame.css";
// This is the must have css for prosemirror
import "@milkdown/kit/prose/view/style/prosemirror.css";
import { nord } from "@milkdown/theme-nord";
import "@milkdown/theme-nord/style.css";
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['update:modelValue', 'enter']);

const editorRef = ref<HTMLElement | null>(null);
let crepe: Crepe | null = null;
let pollInterval: NodeJS.Timeout | null = null;
let handleInput: (() => void) | null = null;

// Handle keydown events for Enter key
const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        emit('enter', event);
    }
};

onMounted(async () => {
    if (editorRef.value) {
        crepe = new Crepe({
            root: editorRef.value,
            defaultValue: props.modelValue || ''
        });

        crepe.editor.config(nord);

        await crepe.create();

        // Simple change detection using input events
        let lastContent = props.modelValue || '';

        // Listen for input events on the editor
        handleInput = () => {
            const currentContent = crepe?.getMarkdown() || '';
            if (currentContent !== lastContent) {
                lastContent = currentContent;
                emit('update:modelValue', currentContent);
            }
        };

        // Add input event listeners
        editorRef.value.addEventListener('input', handleInput);
        editorRef.value.addEventListener('keyup', handleInput);
        editorRef.value.addEventListener('paste', () => {
            setTimeout(handleInput, 0); // Delay to let paste complete
        });

        // Also poll for changes as a fallback
        pollInterval = setInterval(() => {
            handleInput?.();
        }, 500);

        // Add keydown listener to the editor
        if (editorRef.value) {
            editorRef.value.addEventListener('keydown', handleKeydown);
        }
    }
});

// Note: We intentionally do not sync external modelValue into the editor
// to keep this component simple and avoid relying on Crepe APIs that may not exist.
// The editor emits updates out; if external resets are required later,
// we can recreate the editor instance or detect a clear signal to reset content.

// Clean up on component unmount
onBeforeUnmount(() => {
    // Clear polling interval
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }

    // Remove event listeners
    if (editorRef.value && handleInput) {
        editorRef.value.removeEventListener('input', handleInput);
        editorRef.value.removeEventListener('keyup', handleInput);
        editorRef.value.removeEventListener('keydown', handleKeydown);
    }

    // Destroy editor
    if (crepe) {
        crepe.destroy();
        crepe = null;
    }

    handleInput = null;
});
</script>

<template>
    <div class="milkdown-editor">
        <div ref="editorRef"></div>
    </div>
</template>

<style>
.milkdown-editor {
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
}

.milkdown-editor .milkdown {
    border: none;
}

.ProseMirror{
    max-height: 600px;
    overflow-y: auto;
}
</style>
