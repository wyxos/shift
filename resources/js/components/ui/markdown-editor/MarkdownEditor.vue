<script setup lang="ts">
import { Editor } from '@toast-ui/editor';
import '@toast-ui/editor/dist/toastui-editor.css';
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  height: {
    type: String,
    default: '300px',
  },
  placeholder: {
    type: String,
    default: 'Write your content here...',
  },
});

const emit = defineEmits(['update:modelValue']);

const editorRef = ref<HTMLElement | null>(null);
let editor: Editor | null = null;

onMounted(() => {
  if (editorRef.value) {
    // Initialize the editor with markdown only mode
    editor = new Editor({
      el: editorRef.value,
      height: props.height,
      initialValue: props.modelValue,
      initialEditType: 'markdown', // Set to markdown mode
      previewStyle: 'tab', // Use tab style for preview
      hideModeSwitch: true, // Hide the mode switch to enforce markdown only
      placeholder: props.placeholder,
      toolbarItems: [
        ['heading', 'bold', 'italic', 'strike'],
        ['hr', 'quote'],
        ['ul', 'ol', 'task', 'indent', 'outdent'],
        ['table', 'link'],
        ['code', 'codeblock']
      ],
    });

    // Listen for changes and emit them to the parent
    editor.on('change', () => {
      if (editor) {
        emit('update:modelValue', editor.getMarkdown());
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
