<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import { Markdown } from 'tiptap-markdown'
import { ref } from 'vue'
import Icon from '@/components/Icon.vue';

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Components', href: '/components' },
]

// Non-image attachments state
const attachments = ref<{ name: string; size: number; type: string }[]>([])

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B','KB','MB','GB','TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  if (i === 0) return `${bytes} ${sizes[i]}`
  return `${(bytes / Math.pow(k, i)).toFixed(1)} ${sizes[i]}`
}

function removeAttachment(att: { name: string; size: number; type: string }) {
  attachments.value = attachments.value.filter(a => !(a.name === att.name && a.size === att.size && a.type === att.type))
}

function insertLocalImageFromFile(editor: any, file: File) {
  const url = URL.createObjectURL(file)
  const state = editor?.state
  const $from = state?.selection?.$from
  const before = $from?.nodeBefore
  const after = $from?.nodeAfter
  const isText = (n: any) => n && n.type && n.type.name === 'text'

  const chain = editor.chain().focus()
  if (isText(before)) chain.setHardBreak()
  chain.insertContent({ type: 'image', attrs: { src: url, alt: file.name, title: '' } })
  if (isText(after)) chain.setHardBreak()
  chain.run()
}

function handleFiles(editor: any, files: FileList | File[]) {
  const arr = Array.from(files || [])
  arr.forEach(f => {
    if (f.type && f.type.startsWith('image/')) {
      insertLocalImageFromFile(editor, f)
    } else {
      attachments.value.push({ name: f.name, size: f.size, type: f.type || 'application/octet-stream' })
    }
  })
}

const editor = useEditor({
  extensions: [
    StarterKit,
    Markdown.configure({
      html: true,
      transformCopiedText: true,
      transformPastedText: true,
    }),
    Image.configure({ inline: true, allowBase64: true, HTMLAttributes: { class: 'editor-tile' } }),
  ],
  content: '<p>Hello TipTap</p>',
  editorProps: {
    handleDrop: (view, event) => {
      const dt = (event as DragEvent).dataTransfer
      if (dt?.files?.length) {
        event.preventDefault()
        handleFiles(editor.value, dt.files)
        return true
      }
      return false
    },
    handlePaste: (view, event) => {
      const e = event as ClipboardEvent
      const files: File[] = []
      const cdFiles = e.clipboardData?.files
      if (cdFiles && cdFiles.length) {
        files.push(...Array.from(cdFiles))
      } else {
        const items = e.clipboardData?.items || []
        for (const item of Array.from(items)) {
          if (item.kind === 'file' && item.type.startsWith('image/')) {
            const f = item.getAsFile()
            if (f) files.push(f)
          }
        }
      }
      if (files.length) {
        e.preventDefault()
        handleFiles(editor.value, files)
        return true
      }
      return false
    },
    handleTextInput: (_view, from, to, text) => {
      const ed: any = editor.value
      if (!ed) return false
      const state = ed.state
      const $from = state.selection.$from
      const before = $from.nodeBefore
      const after = $from.nodeAfter
      const isNextToImage = (n: any) => n && n.type && n.type.name === 'image'
      if (isNextToImage(before) || isNextToImage(after)) {
        ed.chain().focus().setHardBreak().insertContent(text).run()
        return true
      }
      return false
    },
  },
})

defineExpose({ editor })
</script>

<template>
  <Head title="Components" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="p-4">
        <div class="relative">
            <EditorContent data-testid="tiptap-editor" class="mb-4" :editor="editor" />
            <div class="absolute bottom-2 w-full px-4">
                // buttons
            </div>
        </div>
        <!-- non image attachments listed here -->
        <ul v-if="attachments.length" data-testid="attachments-list" class="flex gap-4 flex-wrap">
            <li v-for="att in attachments" :key="att.name + ':' + att.size" data-testid="attachment-item" class=" bg-gray-100 p-2 rounded w-60 flex items-center justify-between gap-2 ">
                <div class="min-w-0">
                    <div class="truncate" title="{{ att.name }}">{{ att.name }}</div>
                    <div class="text-gray-500 text-xs">{{ formatBytes(att.size) }}</div>
                </div>
                <button type="button" class="text-red-600 text-xs hover:underline cursor-pointer" data-testid="attachment-remove" @click="removeAttachment(att)">
                    <Icon name="x" class="inline-block mr-1" :size="12" />
                </button>
            </li>
        </ul>
    </div>
  </AppLayout>
</template>

<style>
@reference "tailwindcss";
/* Tile styling for images inside the editor */
.ProseMirror img.editor-tile {
  width: 200px;
  height: 200px;
  object-fit: cover;
  display: inline-block;
  margin: 4px;
}

.ProseMirror{
    @apply pb-20 border-2 border-blue-500 p-4 rounded-lg;
}
</style>
