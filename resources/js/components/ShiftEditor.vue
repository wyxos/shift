<script setup lang="ts">
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import TiptapImage from '@tiptap/extension-image'
import ImageUpload from '@/extensions/imageUpload'
import { ref, reactive } from 'vue'
import axios from 'axios'
import Icon from '@/components/Icon.vue'

// Non-image attachments state
export type AttachmentItem = { id: string; name: string; size: number; type: string; progress: number; status: 'uploading' | 'done' | 'error'; path?: string }

const attachments = ref<AttachmentItem[]>([])
const tempIdentifier = ref<string>(Date.now().toString())

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B','KB','MB','GB','TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  if (i === 0) return `${bytes} ${sizes[i]}`
  return `${(bytes / Math.pow(k, i)).toFixed(1)} ${sizes[i]}`
}

async function removeAttachment(att: AttachmentItem) {
  try {
    if (att.path) {
      await axios.delete(route('attachments.remove-temp') as string, { params: { path: att.path } })
    }
  } catch (e) {
    // ignore
  } finally {
    attachments.value = attachments.value.filter(a => a.id !== att.id)
  }
}

function createUploadId() {
  return `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`
}

async function uploadAttachment(file: File) {
  const id = createUploadId()
  const att = reactive<AttachmentItem>({ id, name: file.name, size: file.size, type: file.type || 'application/octet-stream', progress: 0, status: 'uploading' })
  attachments.value.push(att)

  const formData = new FormData()
  formData.append('file', file)
  formData.append('temp_identifier', tempIdentifier.value)

  try {
    const res = await axios.post(route('attachments.upload') as string, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (evt: ProgressEvent) => {
        const loaded = (evt as any).loaded ?? 0
        const total = (evt as any).total ?? file.size ?? 0
        if (total > 0) {
          const next = Math.max(0, Math.min(100, Math.round((loaded / total) * 100)))
          att.progress = Math.max(att.progress, next)
        }
      },
    })
    const data = res.data || {}
    att.status = 'done'
    att.progress = 100
    att.path = data.path
  } catch (e) {
    att.status = 'error'
  }
}

const editor = useEditor({
  extensions: [
    StarterKit,
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
})

defineExpose({ editor })
</script>

<template>
  <div>
    <EditorContent data-testid="tiptap-editor" :editor="editor" />
    <div class="px-4 mb-4">
      <!-- toolbar placeholder -->
      // buttons
    </div>
    <ul v-if="attachments.length" data-testid="attachments-list" class="flex gap-4 flex-wrap">
      <li v-for="att in attachments" :key="att.id" data-testid="attachment-item" :data-temp-path="att.path" class=" bg-gray-100 p-2 rounded w-60 flex items-center justify-between gap-2 ">
        <div class="min-w-0">
          <div class="truncate" :title="att.name">{{ att.name }}</div>
          <div class="text-gray-500 text-xs">
            <template v-if="att.status === 'uploading'">Uploading {{ att.progress }}%</template>
            <template v-else>{{ formatBytes(att.size) }}</template>
          </div>
          <div v-if="att.status === 'uploading'" class="mt-1 h-1 bg-gray-200 rounded overflow-hidden">
            <div class="h-1 bg-blue-500 transition-all" :style="{ width: (Math.max(1, att.progress)) + '%' }"></div>
          </div>
        </div>
        <button type="button" class="text-red-600 text-xs hover:underline cursor-pointer" data-testid="attachment-remove" @click="removeAttachment(att)">
          <Icon name="x" class="inline-block mr-1" :size="12" />
        </button>
      </li>
    </ul>
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
.ProseMirror{
  @apply pb-20 border-2 border-blue-500 p-4 rounded-lg;
}
</style>

