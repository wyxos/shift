<script setup lang="ts">
import ImageUpload from '@/extensions/imageUpload';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import InlineImage from '@/extensions/inlineImage';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import axios from 'axios';
import 'emoji-picker-element';
import cssLang from 'highlight.js/lib/languages/css';
import jsLang from 'highlight.js/lib/languages/javascript';
import jsonLang from 'highlight.js/lib/languages/json';
import phpLang from 'highlight.js/lib/languages/php';
import pythonLang from 'highlight.js/lib/languages/python';
import tsLang from 'highlight.js/lib/languages/typescript';
import htmlLang from 'highlight.js/lib/languages/xml';
import { createLowlight } from 'lowlight';
import { FileImage, FileText, Paperclip, Send, Smile, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { uploadChunkedFile, MAX_UPLOAD_BYTES } from '@/lib/chunkedUpload';
// Optional: import a highlight.js theme for lowlight token colors
import 'highlight.js/styles/github.css';

// Emits
// Include attachments in the payload so consumers can persist them alongside the content
export type SentAttachment = Pick<AttachmentItem, 'name' | 'size' | 'type' | 'path' | 'status' | 'progress'>;
const emit = defineEmits<{ (e: 'send', payload: { html: string; attachments: SentAttachment[] }): void }>();

// Props
const props = defineProps<{ tempIdentifier?: string; modelValue?: string; placeholder?: string }>();

// Non-image attachments state
export type AttachmentItem = {
    id: string;
    name: string;
    size: number;
    type: string;
    progress: number;
    status: 'uploading' | 'done' | 'error';
    path?: string;
    uploadId?: string;
};

const attachments = ref<AttachmentItem[]>([]);
const tempIdentifier = ref<string>(props.tempIdentifier ?? Date.now().toString());
const showEmoji = ref(false);
const hasUploadPlaceholder = ref(false);

// Configure lowlight with a few common languages
const lowlight = createLowlight();
lowlight.register({ javascript: jsLang, js: jsLang });
lowlight.register({ typescript: tsLang, ts: tsLang });
lowlight.register({ json: jsonLang });
lowlight.register({ css: cssLang });
lowlight.register({ php: phpLang });
lowlight.register({ html: htmlLang, xml: htmlLang });
lowlight.register({ python: pythonLang, py: pythonLang });

// Keep tempIdentifier in sync with prop if provided
watch(
    () => props.tempIdentifier,
    (val) => {
        if (val) tempIdentifier.value = String(val);
    },
);

// Keep editor content in sync with external modelValue
watch(
  () => props.modelValue,
  (val) => {
    const current = editor.value?.getHTML() ?? ''
    const next = val ?? ''
    if (next !== current) {
      editor.value?.commands.setContent(next, false)
    }
  }
)

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    if (i === 0) return `${bytes} ${sizes[i]}`;
    return `${(bytes / Math.pow(k, i)).toFixed(1)} ${sizes[i]}`;
}

async function removeAttachment(att: AttachmentItem) {
    try {
        if (att.path) {
            await axios.delete(route('attachments.remove-temp') as string, { params: { path: att.path } });
        }
    } catch (e) {
        // ignore
    } finally {
        attachments.value = attachments.value.filter((a) => a.id !== att.id);
    }
}

function createUploadId() {
    return `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`;
}

async function uploadAttachment(file: File) {
    const id = createUploadId();
    const att = reactive<AttachmentItem>({
        id,
        name: file.name,
        size: file.size,
        type: file.type || 'application/octet-stream',
        progress: 0,
        status: 'uploading',
    });
    attachments.value.push(att);

    try {
        if (file.size > MAX_UPLOAD_BYTES) {
            throw new Error('File exceeds 40MB limit');
        }
        const data = await uploadChunkedFile({
            file,
            tempIdentifier: tempIdentifier.value,
            onProgress: (percent) => {
                att.progress = Math.max(att.progress, percent);
            },
            axiosInstance: axios,
        });
        att.status = 'done';
        att.progress = 100;
        att.path = data.path;
    } catch (e) {
        att.status = 'error';
    }
}

// Attachment picker helpers
const fileInput = ref<HTMLInputElement | null>(null);
function openFilePicker() {
    fileInput.value?.click();
}
function onFileChosen(e: Event) {
    const files = (e.target as HTMLInputElement).files;
    if (!files?.length) return;
    // Requirement: anything attached via the attachment icon (image or non image)
    // is listed below as attachments. So we treat all files as attachments here.
    Array.from(files).forEach(uploadAttachment);
    (e.target as HTMLInputElement).value = '';
}

const editor = useEditor({
    extensions: [
        StarterKit.configure({ codeBlock: false }),
        Placeholder.configure({
            placeholder: props.placeholder ?? 'Type a message...',
            includeChildren: true,
        }),
        CodeBlockLowlight.configure({
            lowlight,
            HTMLAttributes: {
                spellcheck: 'false',
                autocorrect: 'off',
                autocapitalize: 'off',
                'data-gramm': 'false', // helps disable Grammarly
            },
        }),
        InlineImage.configure({ inline: true, allowBase64: true, HTMLAttributes: { class: 'editor-tile' } }),
        ImageUpload.configure({
            getTempIdentifier: () => tempIdentifier.value,
            onNonImageFile: (file: File) => uploadAttachment(file),
            axios,
        }),
    ],
    content: props.modelValue ?? '',
    onUpdate: () => {
      const html = editor.value?.getHTML() ?? ''
      emit('update:modelValue', html)
      hasUploadPlaceholder.value = hasImageUploadPlaceholders()
    },
  editorProps: {
    handleDrop: (_view, event) => {
      const dt = (event as DragEvent).dataTransfer
      if (dt?.files?.length) {
        event.preventDefault()
        editor.value?.commands.insertFiles(Array.from(dt.files))
        return true
      }
      return false
    },
    handlePaste: (_view, event) => {
      const e = event as ClipboardEvent
      const files: File[] = []
      const cdFiles = e.clipboardData?.files
      if (cdFiles && cdFiles.length) {
        files.push(...Array.from(cdFiles))
      } else {
        const items = e.clipboardData?.items || []
        for (const item of Array.from(items)) {
          const it = item as any
          if (it.kind === 'file' && it.type?.startsWith('image/')) {
            const f = it.getAsFile?.()
            if (f) files.push(f)
          }
        }
      }
      if (files.length) {
        e.preventDefault()
        editor.value?.commands.insertFiles(files)
        return true
      }
      return false
    },
    handleTextInput: (_view, _from, _to, text) => {
      return editor.value?.commands.typeText(text) ?? false
    },
  },
});

function hasImageUploadPlaceholders(): boolean {
  const ed = editor.value
  if (!ed) return false
  let found = false
  ed.state.doc.descendants((node: any) => {
    if (node?.type?.name === 'image' && typeof node?.attrs?.title === 'string' && node.attrs.title.startsWith('upload-')) {
      found = true
      return false
    }
    return true
  })
  return found
}

const isUploading = computed(() => {
  if (attachments.value.some((a) => a.status === 'uploading')) return true
  return hasUploadPlaceholder.value
})

function onEmojiClick(ev: Event) {
    const unicode = (ev as CustomEvent).detail?.unicode || (ev as any).detail?.emoji?.unicode;
    if (!unicode || !editor.value) return;
    editor.value.chain().focus().insertContent(unicode).run();
    showEmoji.value = false;
}

function onSend() {
    if (isUploading.value) return;
    const html = editor.value?.getHTML() ?? '';
    const toSend: SentAttachment[] = attachments.value.map((a) => ({
        name: a.name,
        size: a.size,
        type: a.type,
        path: a.path,
        status: a.status,
        progress: a.progress,
    }));
    emit('send', { html, attachments: toSend });
    // Reset editor value and clear attachments list
    editor.value?.commands.clearContent();
    emit('update:modelValue', '');
    attachments.value = [];
}

function iconForAttachment(type: string) {
    if (type?.startsWith('image/')) return FileImage;
    return FileText;
}

defineExpose({ editor });
</script>

<template>
    <div>
        <EditorContent class="tiptap" data-testid="tiptap-editor" :editor="editor" />

        <div class="flex flex-col gap-2">
            <ul
                v-if="attachments.length"
                data-testid="attachments-list"
                class="divide-y divide-slate-200 rounded-md border border-slate-200 bg-slate-50/60"
            >
                <li
                    v-for="att in attachments"
                    :key="att.id"
                    data-testid="attachment-item"
                    :data-temp-path="att.path"
                    class="flex items-center justify-between gap-3 px-3 py-2"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                            <component :is="iconForAttachment(att.type)" :size="18" class="text-slate-600" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-slate-900" :title="att.name">{{ att.name }}</div>
                            <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                <template v-if="att.status === 'uploading'">
                                    <span>Uploading</span>
                                    <span class="rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">
                                        {{ Math.max(1, att.progress) }}%
                                    </span>
                                </template>
                                <template v-else-if="att.status === 'error'">
                                    <span class="font-semibold text-red-600">Upload failed</span>
                                </template>
                                <template v-else>
                                    <span>{{ formatBytes(att.size) }}</span>
                                </template>
                            </div>
                            <div v-if="att.status === 'uploading'" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-1.5 bg-gradient-to-r from-sky-500 via-blue-500 to-indigo-500 transition-all"
                                    :style="{ width: Math.max(1, att.progress) + '%' }"
                                ></div>
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-md bg-red-50 text-red-600 shadow-sm ring-1 ring-red-200 transition hover:bg-red-100 hover:text-red-700 hover:ring-red-300"
                        data-testid="attachment-remove"
                        aria-label="Remove attachment"
                        title="Remove"
                        @click="removeAttachment(att)"
                    >
                        <X :size="14" />
                    </button>
                </li>
            </ul>
            <div class="flex items-center justify-end gap-2 p-2 px-1">
                <button type="button" data-testid="toolbar-emoji" class="rounded p-1 hover:bg-gray-100" @click="showEmoji = !showEmoji">
                    <Smile :size="18" />
                </button>
                <button type="button" data-testid="toolbar-attachment" class="rounded p-1 hover:bg-gray-100" @click="openFilePicker">
                    <Paperclip :size="18" />
                </button>
                <button
                    type="button"
                    data-testid="toolbar-send"
                    class="ml-auto rounded p-1 text-blue-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="isUploading"
                    @click="onSend"
                >
                    <Send :size="18" />
                </button>
                <input ref="fileInput" data-testid="file-input" type="file" class="hidden" multiple @change="onFileChosen" />
            </div>
        </div>

        <div v-if="showEmoji" class="mb-2 px-4">
            <emoji-picker data-testid="emoji-picker" @emoji-click="onEmojiClick"></emoji-picker>
        </div>
    </div>
</template>

<style>
@reference "tailwindcss";
.ProseMirror img.editor-tile, .tiptap img.editor-tile {
    width: 200px;
    height: 200px;
    object-fit: cover;
    display: inline-block;
    margin: 4px;
}
.ProseMirror {
    @apply rounded-lg border-2 border-blue-500 p-4;
    max-height: 600px;
    overflow-y: auto;
}
/* Placeholder styling */
.ProseMirror p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: #9ca3af; /* tailwind gray-400 */
    pointer-events: none;
    height: 0;
}
/* Code block base styling to make blocks stand out */
.tiptap pre {
    @apply bg-gray-200;
    /*  background: #0b1021; !* dark background to contrast token colors; override with theme if desired *!
  color: #e6e6e6;*/
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    margin: 1rem 0;
    overflow-x: auto;
}
.tiptap pre code {
    background: none;
    color: inherit;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
    font-size: 0.875rem;
    line-height: 1.5;
    padding: 0;
}
</style>
