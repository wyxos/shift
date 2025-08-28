<script setup lang="ts">
import ImageUpload from '@/extensions/imageUpload';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import TiptapImage from '@tiptap/extension-image';
import StarterKit from '@tiptap/starter-kit';
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
import { Paperclip, Send, Smile } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
// Optional: import a highlight.js theme for lowlight token colors
import 'highlight.js/styles/github.css';

// Emits
// Include attachments in the payload so consumers can persist them alongside the content
export type SentAttachment = Pick<AttachmentItem, 'name' | 'size' | 'type' | 'path' | 'status' | 'progress'>;
const emit = defineEmits<{ (e: 'send', payload: { html: string; attachments: SentAttachment[] }): void }>();

// Props
const props = defineProps<{ tempIdentifier?: string }>();

// Non-image attachments state
export type AttachmentItem = {
    id: string;
    name: string;
    size: number;
    type: string;
    progress: number;
    status: 'uploading' | 'done' | 'error';
    path?: string;
};

const attachments = ref<AttachmentItem[]>([]);
const tempIdentifier = ref<string>(props.tempIdentifier ?? Date.now().toString());
const showEmoji = ref(false);

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

    const formData = new FormData();
    formData.append('file', file);
    formData.append('temp_identifier', tempIdentifier.value);

    try {
        const res = await axios.post(route('attachments.upload') as string, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress: (evt: ProgressEvent) => {
                const loaded = (evt as any).loaded ?? 0;
                const total = (evt as any).total ?? file.size ?? 0;
                if (total > 0) {
                    const next = Math.max(0, Math.min(100, Math.round((loaded / total) * 100)));
                    att.progress = Math.max(att.progress, next);
                }
            },
        });
        const data = res.data || {};
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
        CodeBlockLowlight.configure({
            lowlight,
            HTMLAttributes: {
                spellcheck: 'false',
                autocorrect: 'off',
                autocapitalize: 'off',
                'data-gramm': 'false', // helps disable Grammarly
            },
        }),
        TiptapImage.configure({ inline: true, allowBase64: true, HTMLAttributes: { class: 'editor-tile' } }),
        ImageUpload.configure({
            getTempIdentifier: () => tempIdentifier.value,
            onNonImageFile: (file: File) => uploadAttachment(file),
            axios,
        }),
    ],
    content: '<p>Hello TipTap</p>',
    editorProps: {
        handleDrop: (_view, event) => {
            const dt = (event as DragEvent).dataTransfer;
            if (dt?.files?.length) {
                event.preventDefault();
                editor.value?.commands.insertFiles(Array.from(dt.files));
                return true;
            }
            return false;
        },
        handlePaste: (_view, event) => {
            const e = event as ClipboardEvent;
            const files: File[] = [];
            const cdFiles = e.clipboardData?.files;
            if (cdFiles && cdFiles.length) {
                files.push(...Array.from(cdFiles));
            } else {
                const items = e.clipboardData?.items || [];
                for (const item of Array.from(items)) {
                    const it = item as any;
                    if (it.kind === 'file' && it.type?.startsWith('image/')) {
                        const f = it.getAsFile?.();
                        if (f) files.push(f);
                    }
                }
            }
            if (files.length) {
                e.preventDefault();
                editor.value?.commands.insertFiles(files);
                return true;
            }
            return false;
        },
        handleTextInput: (_view, _from, _to, text) => {
            return editor.value?.commands.typeText(text) ?? false;
        },
    },
});

function onEmojiClick(ev: Event) {
    const unicode = (ev as CustomEvent).detail?.unicode || (ev as any).detail?.emoji?.unicode;
    if (!unicode || !editor.value) return;
    editor.value.chain().focus().insertContent(unicode).run();
    showEmoji.value = false;
}

function onSend() {
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
    attachments.value = [];
}

defineExpose({ editor });
</script>

<template>
    <div>
        <EditorContent class="tiptap" data-testid="tiptap-editor" :editor="editor" />

        <div class="mb-4 flex justify-end gap-4">
            <ul v-if="attachments.length" data-testid="attachments-list" class="flex flex-1 flex-wrap gap-4 p-2 px-4">
                <li
                    v-for="att in attachments"
                    :key="att.id"
                    data-testid="attachment-item"
                    :data-temp-path="att.path"
                    class="flex w-60 items-center justify-between gap-2 rounded bg-gray-100 p-2"
                >
                    <div class="min-w-0">
                        <div class="truncate" :title="att.name">{{ att.name }}</div>
                        <div class="text-xs text-gray-500">
                            <template v-if="att.status === 'uploading'">Uploading {{ att.progress }}%</template>
                            <template v-else>{{ formatBytes(att.size) }}</template>
                        </div>
                        <div v-if="att.status === 'uploading'" class="mt-1 h-1 overflow-hidden rounded bg-gray-200">
                            <div class="h-1 bg-blue-500 transition-all" :style="{ width: Math.max(1, att.progress) + '%' }"></div>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="cursor-pointer text-xs text-red-600 hover:underline"
                        data-testid="attachment-remove"
                        @click="removeAttachment(att)"
                    >
                        ✕
                    </button>
                </li>
            </ul>
            <div class="flex items-center justify-end gap-2 p-2 px-4">
                <button type="button" data-testid="toolbar-emoji" class="rounded p-1 hover:bg-gray-100" @click="showEmoji = !showEmoji">
                    <Smile :size="18" />
                </button>
                <button type="button" data-testid="toolbar-attachment" class="rounded p-1 hover:bg-gray-100" @click="openFilePicker">
                    <Paperclip :size="18" />
                </button>
                <button type="button" data-testid="toolbar-send" class="ml-auto rounded p-1 text-blue-600 hover:bg-gray-100" @click="onSend">
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
.ProseMirror img.editor-tile {
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
