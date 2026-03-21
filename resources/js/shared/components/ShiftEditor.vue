<script setup lang="ts">
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import Placeholder from '@tiptap/extension-placeholder';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import axios, { type AxiosInstance } from 'axios';
import 'emoji-picker-element';
import cssLang from 'highlight.js/lib/languages/css';
import jsLang from 'highlight.js/lib/languages/javascript';
import jsonLang from 'highlight.js/lib/languages/json';
import phpLang from 'highlight.js/lib/languages/php';
import pythonLang from 'highlight.js/lib/languages/python';
import tsLang from 'highlight.js/lib/languages/typescript';
import htmlLang from 'highlight.js/lib/languages/xml';
import { createLowlight } from 'lowlight';
import { Paperclip, Send, Smile, Sparkles, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import ImageUpload from '../extensions/imageUpload';
import InlineImage from '../extensions/inlineImage';
import ReplyQuote from '../extensions/replyQuote';
import type { UploadEndpoints } from '../lib/chunkedUpload';
import ShiftEditorAiPreviewDrawer from './shift-editor/ShiftEditorAiPreviewDrawer.vue';
import ShiftEditorAttachmentList from './shift-editor/ShiftEditorAttachmentList.vue';
import { useShiftEditorAiImprove } from './shift-editor/useShiftEditorAiImprove';
import { useShiftEditorAttachments } from './shift-editor/useShiftEditorAttachments';
import type { SentAttachment } from './shift-editor/types';
// Optional: import a highlight.js theme for lowlight token colors
import 'highlight.js/styles/github.css';

declare const route: undefined | ((name: string, params?: Record<string, unknown>) => string);

// Emits
// Include attachments in the payload so consumers can persist them alongside the content
const emit = defineEmits<{
    (e: 'send', payload: { html: string; attachments: SentAttachment[] }): void;
    (e: 'update:modelValue', value: string): void;
    (e: 'uploading', value: boolean): void;
    (e: 'cancel'): void;
}>();

// Props
const props = withDefaults(
    defineProps<{
        tempIdentifier?: string;
        modelValue?: string;
        placeholder?: string;
        minHeight?: number | string;
        axiosInstance?: AxiosInstance | typeof axios;
        uploadEndpoints?: UploadEndpoints;
        removeTempUrl?: string;
        resolveTempUrl?: (data: any) => string;
        clearOnSend?: boolean;
        cancelable?: boolean;
        aiImproveUrl?: string;
        aiContext?: string;
        enableAiImprove?: boolean;
    }>(),
    {
        clearOnSend: true,
        cancelable: false,
        enableAiImprove: true,
    },
);

const tempIdentifier = ref<string>(props.tempIdentifier ?? Date.now().toString());
const showEmoji = ref(false);
const hasUploadPlaceholder = ref(false);
const axiosClient = computed(() => props.axiosInstance ?? axios);
const { attachments, fileInput, formatBytes, isUploadingAttachments, onFileChosen, openFilePicker, removeAttachment, resetAttachments, uploadAttachment } =
    useShiftEditorAttachments({
        axiosClient,
        tempIdentifier,
        uploadEndpoints: props.uploadEndpoints,
        removeTempUrl: props.removeTempUrl,
    });

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
        const current = editor.value?.getHTML() ?? '';
        const next = val ?? '';
        if (next !== current) {
            editor.value?.commands.setContent(next, false);
        }
    },
);

function resolveAiImproveUrl(): string | null {
    if (props.aiImproveUrl) return props.aiImproveUrl;
    if (typeof route === 'function') {
        return route('ai.improve') as string;
    }
    return null;
}

function isInRichBlockNeedingEnter(editorInstance: any): boolean {
    const selection = editorInstance?.state?.selection;
    const from = selection?.$from;
    if (!from) return false;

    for (let depth = from.depth; depth >= 0; depth -= 1) {
        const typeName = from.node(depth)?.type?.name;
        if (typeName === 'listItem' || typeName === 'codeBlock' || typeName === 'blockquote') {
            return true;
        }
    }

    return false;
}

const editor = useEditor({
    extensions: [
        StarterKit.configure({ codeBlock: false }),
        ReplyQuote,
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
            axios: axiosClient.value,
            uploadEndpoints: props.uploadEndpoints,
            resolveTempUrl: props.resolveTempUrl,
        }),
    ],
    content: props.modelValue ?? '',
    onUpdate: () => {
        const html = editor.value?.getHTML() ?? '';
        emit('update:modelValue', html);
        hasUploadPlaceholder.value = hasImageUploadPlaceholders();
    },
    editorProps: {
        handleDrop: (_view: any, event: DragEvent) => {
            const dt = event.dataTransfer;
            if (dt?.files?.length) {
                event.preventDefault();
                editor.value?.commands.insertFiles(Array.from(dt.files));
                return true;
            }
            return false;
        },
        handlePaste: (_view: any, event: ClipboardEvent) => {
            const e = event;
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
        handleKeyDown: (_view: any, event: KeyboardEvent) => {
            if (event.key !== 'Enter') return false;
            if (event.shiftKey || event.isComposing) return false;
            if (isInRichBlockNeedingEnter(editor.value)) return false;

            event.preventDefault();
            onSend();
            return true;
        },
    },
});

function hasImageUploadPlaceholders(): boolean {
    const ed = editor.value;
    if (!ed) return false;
    let found = false;
    ed.state.doc.descendants((node: any) => {
        if (node?.type?.name === 'image' && typeof node?.attrs?.title === 'string' && node.attrs.title.startsWith('upload-')) {
            found = true;
            return false;
        }
        return true;
    });
    return found;
}

const isUploading = computed(() => {
    if (isUploadingAttachments.value) return true;
    return hasUploadPlaceholder.value;
});
const { acceptAiImprove, aiError, aiImproving, aiPreviewHtml, aiPreviewOpen, improveWithAi, rejectAiImprove } = useShiftEditorAiImprove({
    axiosClient,
    editor,
    isUploading,
    resolveAiImproveUrl,
    getAiContext: () => props.aiContext ?? '',
    onAccept: (html) => emit('update:modelValue', html),
});

watch(
    isUploading,
    (value) => {
        emit('uploading', value);
    },
    { immediate: true },
);

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

    if (props.clearOnSend === false) {
        return;
    }

    // Reset editor value and clear attachments list
    reset();
}

const editorStyle = computed(() => {
    if (!props.minHeight) return undefined;
    const value = typeof props.minHeight === 'number' ? `${props.minHeight}px` : props.minHeight;
    return { '--editor-min-height': value };
});

function reset() {
    editor.value?.commands.clearContent();
    emit('update:modelValue', '');
    resetAttachments();
}

// Expose the ref so parents (and tests) can observe / control the editor once it initializes.
defineExpose({ editor, reset });
</script>

<template>
    <div>
        <EditorContent class="tiptap" data-testid="tiptap-editor" :editor="editor" :style="editorStyle" />

        <div class="flex flex-col gap-2">
            <ShiftEditorAttachmentList :attachments="attachments" :format-bytes="formatBytes" @remove="removeAttachment" />
            <div class="flex items-center justify-end gap-2 p-2 px-1">
                <button type="button" data-testid="toolbar-emoji" class="rounded p-1 hover:bg-gray-100" @click="showEmoji = !showEmoji">
                    <Smile :size="18" />
                </button>
                <button type="button" data-testid="toolbar-attachment" class="rounded p-1 hover:bg-gray-100" @click="openFilePicker">
                    <Paperclip :size="18" />
                </button>
                <button
                    v-if="props.enableAiImprove"
                    type="button"
                    data-testid="toolbar-ai-improve"
                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs text-slate-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="isUploading || aiImproving"
                    @click="improveWithAi"
                >
                    <Sparkles :size="14" />
                    <span>{{ aiImproving ? 'Improving...' : 'Improve with AI' }}</span>
                </button>
                <button
                    v-if="props.cancelable"
                    type="button"
                    data-testid="toolbar-cancel"
                    class="rounded p-1 text-slate-500 hover:bg-gray-100 hover:text-red-600"
                    aria-label="Cancel edit"
                    title="Cancel"
                    @click="emit('cancel')"
                >
                    <X :size="18" />
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

        <div v-if="aiError" data-testid="ai-improve-error" class="mt-2 px-1 text-xs text-red-600">
            {{ aiError }}
        </div>

        <ShiftEditorAiPreviewDrawer :html="aiPreviewHtml" :open="aiPreviewOpen" @accept="acceptAiImprove" @reject="rejectAiImprove" />
    </div>
</template>

<style>
@reference "tailwindcss";
.ProseMirror img.editor-tile,
.tiptap img.editor-tile {
    width: 200px;
    height: 200px;
    object-fit: cover;
    display: inline-block;
    margin: 4px;
}
.tiptap {
    --editor-min-height: 140px;
}
.ProseMirror {
    @apply rounded-lg border-2 border-blue-500 p-4 text-sm leading-6;
    min-height: var(--editor-min-height, 140px);
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
