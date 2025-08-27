<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import TiptapImage from '@tiptap/extension-image'
import { ref, reactive } from 'vue'
import axios from 'axios'
import Icon from '@/components/Icon.vue';

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Components', href: '/components' },
]

// Non-image attachments state
type AttachmentItem = { id: string; name: string; size: number; type: string; progress: number; status: 'uploading' | 'done' | 'error'; path?: string }
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
    // ignore errors on cleanup
  } finally {
    attachments.value = attachments.value.filter(a => a.id !== att.id)
  }
}

function createUploadId() {
  return `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`
}

function buildTempUrl(data: any): string {
  if (data && data.url) return data.url as string
  if (data && data.path) {
    try {
      const m = String(data.path).match(/^temp_attachments\/([^/]+)\/(.+)$/)
      if (m) {
        return route('attachments.temp', { temp: m[1], filename: m[2] }) as string
      }
    } catch (e) {
      // ignore, fallback below
    }
  }
  return ''
}

function renderProgressTile(percent: number, label = 'Uploading...'): string {
  const w = 200, h = 100
  const canvas = document.createElement('canvas')
  canvas.width = w
  canvas.height = h
  const ctx = canvas.getContext('2d') as CanvasRenderingContext2D | null
  if (!ctx) return ''
  ctx.fillStyle = '#f3f4f6'
  ctx.fillRect(0, 0, w, h)
  ctx.strokeStyle = '#cbd5e1'
  ctx.strokeRect(0.5, 0.5, w - 1, h - 1)
  ctx.fillStyle = '#374151'
  ctx.font = '14px sans-serif'
  ctx.textAlign = 'center'
  ctx.fillText(label, w / 2, 32)
  ctx.fillStyle = '#e5e7eb'
  const pbX = 20, pbY = 56, pbW = w - 40, pbH = 16
  ctx.fillRect(pbX, pbY, pbW, pbH)
  const pw = Math.max(0, Math.min(pbW, Math.round((percent / 100) * pbW)))
  ctx.fillStyle = '#3b82f6'
  ctx.fillRect(pbX, pbY, pw, pbH)
  ctx.fillStyle = '#111827'
  ctx.font = '12px sans-serif'
  ctx.fillText(`${Math.max(0, Math.min(100, Math.round(percent)))}%`, w / 2, pbY + pbH + 18)
  return canvas.toDataURL('image/png')
}

function findImagePosByTitle(ed: any, title: string): number | null {
  const state = ed?.state
  if (!state) return null
  let found: number | null = null
  state.doc.descendants((node: any, pos: number) => {
    if (node.type?.name === 'image' && node.attrs?.title === title) {
      found = pos
      return false
    }
    return true
  })
  return found
}

function insertUploadPlaceholderImage(editor: any, uploadId: string, filename: string) {
  const state = editor?.state
  const $from = state?.selection?.$from
  const before = $from?.nodeBefore
  const after = $from?.nodeAfter
  const isText = (n: any) => n && n.type && n.type.name === 'text'

  const chain = editor.chain().focus()
  if (isText(before)) chain.setHardBreak()
  chain.insertContent({ type: 'image', attrs: { src: renderProgressTile(0), alt: filename, title: uploadId } })
  if (isText(after)) chain.setHardBreak()
  chain.run()
}

async function uploadImage(file: File) {
  const ed = editor.value
  if (!ed) return
  const uploadId = createUploadId()
  console.debug('[editor] uploadImage start', { name: file.name, uploadId })
  insertUploadPlaceholderImage(ed, uploadId, file.name)

  const formData = new FormData()
  formData.append('file', file)
  formData.append('temp_identifier', tempIdentifier.value)

  try {
    await axios.post(route('attachments.upload') as string, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (evt) => {
        const total = evt.total || 0
        const loaded = evt.loaded || 0
        const percent = total > 0 ? (loaded / total) * 100 : 0
        console.debug('[editor] upload progress', { uploadId, loaded, total, percent })
        const pos = findImagePosByTitle(ed, uploadId)
        if (pos != null) {
          const { state, dispatch } = ed.view
          const imageType = state.schema.nodes.image
          const node = state.doc.nodeAt(pos)
          if (node) {
            const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: renderProgressTile(percent) }, node.marks)
            dispatch(tr)
          }
        }
      },
    }).then(res => {
      const data = res.data || {}
      const finalUrl: string = buildTempUrl(data)
      console.debug('[editor] upload success', { uploadId, data, finalUrl })
      if (finalUrl) {
        let done = false
        const finishSwap = () => { done = true }
        const trySwap = () => {
          if (done) return
          const pos = findImagePosByTitle(ed, uploadId)
          console.debug('[editor] swapping placeholder to final image', { uploadId, pos, finalUrl })
          if (pos != null) {
            const { state, dispatch } = ed.view
            const imageType = state.schema.nodes.image
            const node = state.doc.nodeAt(pos)
            if (node) {
              const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: finalUrl, title: '' }, node.marks)
              dispatch(tr)
              finishSwap()
            }
          }
        }
        const ImgCtor: any = (globalThis as any).Image
        const img = new ImgCtor()
        // If cross-origin, still want onload to fire; we don't access pixels
        img.onload = () => {
          console.debug('[editor] image preload onload', { uploadId, finalUrl })
          trySwap()
        }
        img.onerror = () => {
          console.warn('[editor] image preload onerror', { uploadId, finalUrl })
          // Fallback: still attempt to swap so the URL is used, even if preview fails
          trySwap()
        }
        const timer: any = setTimeout(() => {
          console.debug('[editor] image preload timeout fallback', { uploadId, finalUrl })
          trySwap()
        }, 2000)
        // Ensure we mark done when swapped to avoid multiple updates
        const origTrySwap = trySwap
        const wrappedSwap = () => { origTrySwap(); clearTimeout(timer); finishSwap() }
        // Rebind handlers to wrapped version to clear timer
        img.onload = wrappedSwap
        img.onerror = wrappedSwap
        img.src = finalUrl
      } else {
        console.warn('[editor] no finalUrl returned from upload', { uploadId, data })
      }
    })
  } catch (e) {
    console.error('[editor] upload failed', { uploadId, error: e })
    const pos = findImagePosByTitle(ed, uploadId)
    if (pos != null) {
      const { state, dispatch } = ed.view
      const imageType = state.schema.nodes.image
      const node = state.doc.nodeAt(pos)
      if (node) {
        const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: renderProgressTile(0, 'Upload failed') }, node.marks)
        dispatch(tr)
      }
    }
  }
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
        // Fallback to known file.size if total is missing to avoid "0%" stuck UI
        const loaded = (evt as any).loaded ?? 0
        // When the browser doesn't set evt.total, use the file's size we already know
        const total = (evt as any).total ?? file.size ?? 0
        if (total > 0) {
          const next = Math.max(0, Math.min(100, Math.round((loaded / total) * 100)))
          // Ensure progress never goes backwards
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

function handleFiles(editor: any, files: FileList | File[]) {
  const arr = Array.from(files || [])
  arr.forEach(f => {
    if (f.type && f.type.startsWith('image/')) {
      uploadImage(f)
    } else {
      uploadAttachment(f)
    }
  })
}

const editor = useEditor({
  extensions: [
    StarterKit,
    TiptapImage.configure({ inline: true, allowBase64: true, HTMLAttributes: { class: 'editor-tile' } }),
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
