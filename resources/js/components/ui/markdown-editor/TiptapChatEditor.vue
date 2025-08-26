<template>
<!-- Parent drop scope wrapper -->
<div data-drop-scope class="relative">
  <!-- Drag-and-drop overlay spanning the designated drop scope (parent blue-500 border) -->
  <div
    v-if="dragActive"
    class="pointer-events-none fixed z-40"
    :style="{ top: overlayRect.top + 'px', left: overlayRect.left + 'px', width: overlayRect.width + 'px', height: overlayRect.height + 'px' }"
  >
    <div class="w-full h-full rounded border-2 border-dashed border-blue-400/80 bg-blue-50/60 dark:bg-blue-950/30 flex items-center justify-center">
      <div class="flex items-center gap-2 text-blue-700 dark:text-blue-200">
        <Icon name="upload" :size="18" />
        <span class="text-sm font-medium">Drop files to upload</span>
      </div>
    </div>
  </div>

  <div ref="containerRef" class="tiptap-editor relative border-2 border-blue-200 rounded p-4" :class="{ 'border-blue-500 ring-2 ring-blue-300/50': dragActive }">
    <EditorContent :editor="editor" />

        <!-- Attachments tray below editor -->
        <div v-if="attachments.length" class="mt-2 flex flex-wrap gap-2" data-attachments-tray>
            <!-- visible items logic: first 3 files; if more than 5 total, show count tile as 4th -->
            <div
                v-for="(item, index) in attachments"
                v-show="attachments.length <= 5 ? true : index < 3"
                :key="item.id"
                class="relative w-[200px] h-[200px] rounded border bg-muted/20 overflow-hidden cursor-pointer"
                @click="openAttachmentModalAt(index)"
            >
                <button
                    class="absolute right-1 top-1 z-10 rounded bg-black/50 text-white p-1"
                    type="button"
                    aria-label="Remove"
                    @click.stop="removeAttachment(item.id)"
                >
                    <Icon name="x" :size="14" />
                </button>
                <div class="w-full h-full flex items-center justify-center">
                    <img v-if="item.isImage && (item.previewUrl || item.url)" :src="item.previewUrl || item.url" class="w-full h-full object-cover" alt="preview" />
                    <div v-else class="flex flex-col items-center justify-center text-muted-foreground">
                        <Icon name="file" :size="48" />
                        <span class="mt-2 text-xs px-2 text-center truncate w-full">{{ item.filename }}</span>
                        <span class="text-[10px]">{{ item.sizeLabel }}</span>
                    </div>
                </div>
                <div v-if="item.status === 'uploading'" class="absolute left-0 right-0 bottom-0">
                    <div class="h-1 bg-muted">
                        <div class="h-1 bg-blue-500" :style="{ width: Math.max(0, Math.min(100, item.progress)) + '%' }" />
                    </div>
                </div>
                <div v-if="item.status === 'error'" class="absolute inset-0 bg-red-50/80 text-red-800 text-xs flex items-center justify-center">
                    Upload failed
                </div>
            </div>
            <!-- Count tile when more than 5 -->
            <div v-if="attachments.length > 5" class="w-[200px] h-[200px] rounded border bg-muted/30 flex items-center justify-center cursor-pointer" @click="openAttachmentModalAt(3)">
                <span class="text-2xl font-semibold">{{ attachments.length - 3 }}+</span>
            </div>

            <!-- Plus tile for adding more -->
            <div
                class="w-[200px] h-[200px] rounded border border-dashed flex items-center justify-center cursor-pointer hover:bg-muted/20"
                @click="openFileDialog"
            >
                <Icon name="plus" :size="24" />
            </div>
        </div>
        <!-- Emoji picker popover (triggered from footer CTA) -->
    <div v-if="showEmojiPicker" class="absolute z-50 bottom-12 right-2">
      <div class="relative bg-background rounded-md shadow-lg border">
                <button class="absolute right-2 top-2" aria-label="Close emoji" @click="closeEmoji">
                    <Icon name="x" :size="14" />
                </button>
                <emoji-picker @emoji-click="onEmojiClick"></emoji-picker>
            </div>
        </div>
    <!-- Always-present hidden file input for footer CTA and plus-tile -->
    <input ref="fileInputRef" type="file" class="hidden" multiple @change="onFileInputChange" />
  </div>
</div>

  <!-- Attachments modal -->
  <AttachmentsModal
    ref="attachmentsModalRef"
    :open="isAttachmentsModalOpen"
    :attachments="attachments"
    :active-index="activeAttachmentIndex"
    @close="closeAttachmentsModal"
    @next="nextAttachment"
    @prev="prevAttachment"
  />

    <!-- Legacy image modal (inline editor images) -->
    <div
        v-if="isImageModalOpen"
        class="fixed inset-0 z-40 bg-black/70 flex items-center justify-center"
        @click="closeImageModal"
    >
        <img
            :src="modalImageSrc"
            class="max-w-[90vw] max-h-[90vh] object-contain"
            @click.stop
            alt="full-size"
        />
    </div>
</template>

<script lang="ts">
import { defineComponent, onMounted, onBeforeUnmount, ref } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import axios from 'axios'
import { renderTileToDataUrl } from './tiles/render'
import ImageProgressTile from './tiles/ImageProgressTile.vue'
import ImageErrorTile from './tiles/ImageErrorTile.vue'
import Icon from '@/components/Icon.vue'
import { Button } from '@/components/ui/button'
import { AttachmentsCapture } from './extensions/AttachmentsCapture'
import AttachmentsModal from './attachment/AttachmentsModal.vue'
import 'emoji-picker-element'

export default defineComponent({
  name: 'TiptapChatEditor',
  components: { Icon, EditorContent, Button, AttachmentsModal },
  setup: (props, { expose, emit }) => {
    const containerRef = ref(null)
    const isImageModalOpen = ref(false)
    const modalImageSrc = ref('')

    // Attachments modal state
    const isAttachmentsModalOpen = ref(false)
    const activeAttachmentIndex = ref<number | null>(null)
    const tempIdentifier = ref(Date.now().toString())

    const editor = useEditor({
      extensions: [
        StarterKit.configure({ codeBlock: false, blockquote: false, heading: false, horizontalRule: false }),
        Image.configure({ inline: true, allowBase64: true }),
        Placeholder.configure({ placeholder: 'Message…' }),
        AttachmentsCapture.configure({ onFiles: (files) => handleFiles(files) }),
      ],
      content: '',
      editorProps: {
        handleKeyDown: (_view, event) => {
          if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault()
            sendMessage()
            return true
          }
          return false
        },
      },
    })

    const createUploadId = () => `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`

    // External attachments tray state
    const attachments = ref([])
    const fileInputRef = ref(null)

    const openFileDialog = () => {
      const input = fileInputRef.value
      if (input) input.click()
    }

    const onFileInputChange = (event) => {
      const files = Array.from(event?.target?.files || [])
      if (files.length) handleFiles(files)
      if (event?.target) event.target.value = ''
    }

    const removeAttachment = (id) => {
      attachments.value = attachments.value.filter(a => a.id !== id)
    }

    const handleFiles = (files) => {
      files.forEach((file) => {
        const id = createUploadId()
        const isImage = !!file.type && file.type.startsWith('image/')
        const previewUrl = isImage ? URL.createObjectURL(file) : ''
        attachments.value.push({
          id,
          isImage,
          filename: file.name,
          sizeLabel: formatBytes(file.size),
          previewUrl,
          url: '',
          progress: 0,
          status: 'uploading',
        })
        uploadToTemp(file, id)
      })
    }

    // Expose to parent (footer attach button)
    const sendMessage = () => {
      const payload = {
        content: editor.value?.getJSON?.(),
        html: editor.value?.getHTML?.(),
        attachments: attachments.value.map(a => ({ id: a.id, url: a.url, filename: a.filename, isImage: a.isImage, sizeLabel: a.sizeLabel })),
      }
      // emit event
      emit('send', payload)
      // reset
      editor.value?.commands?.clearContent?.(true)
      attachments.value = []
      closeAttachmentsModal()
    }

    // Emoji picker
    const showEmojiPicker = ref(false)
    const openEmoji = () => { showEmojiPicker.value = true }
    const closeEmoji = () => { showEmojiPicker.value = false }
    const onEmojiClick = (event: any) => {
      const emoji = event?.detail?.unicode || ''
      if (!emoji) return
      editor.value?.chain()?.focus()?.insertContent(emoji)?.run()
      closeEmoji()
    }

    expose({ openFileDialog, handleFiles, send: sendMessage, openEmoji })

    // Modal helpers for attachments tray
    const openAttachmentModalAt = (index: number) => {
      if (!attachments.value.length) return
      const clamped = Math.max(0, Math.min(index, attachments.value.length - 1))
      activeAttachmentIndex.value = clamped
      isAttachmentsModalOpen.value = true
    }
    const closeAttachmentsModal = () => {
      isAttachmentsModalOpen.value = false
      activeAttachmentIndex.value = null
    }
    const nextAttachment = () => {
      if (activeAttachmentIndex.value === null) return
      const len = attachments.value.length
      activeAttachmentIndex.value = (activeAttachmentIndex.value + 1) % len
    }
    const prevAttachment = () => {
      if (activeAttachmentIndex.value === null) return
      const len = attachments.value.length
      activeAttachmentIndex.value = (activeAttachmentIndex.value - 1 + len) % len
    }

    // Attachments modal ref and proxy handlers (for tests)
    const attachmentsModalRef = ref<any | null>(null)
    const onModalImageLoad = (e: Event) => attachmentsModalRef.value?.onModalImageLoad?.(e)
    const onModalImageError = (e: Event) => attachmentsModalRef.value?.onModalImageError?.(e)

    // Drag & Drop overlay controls
    const dragActive = ref(false)
    let dragCounter = 0
    const overlayRect = ref({ top: 0, left: 0, width: 0, height: 0 })
    const updateOverlayRect = () => {
      if (!dropScopeEl) return
      const r = dropScopeEl.getBoundingClientRect()
      overlayRect.value = {
        top: r.top + window.scrollY,
        left: r.left + window.scrollX,
        width: r.width,
        height: r.height,
      }
    }
    const addOverlayWatchers = () => {
      window.addEventListener('resize', updateOverlayRect)
      window.addEventListener('scroll', updateOverlayRect, true)
    }
    const removeOverlayWatchers = () => {
      window.removeEventListener('resize', updateOverlayRect)
      window.removeEventListener('scroll', updateOverlayRect, true)
    }
    const hasFiles = (e: any) => {
      const dt = e?.dataTransfer || e?.clipboardData
      if (!dt) return false
      if (dt.files && dt.files.length > 0) return true
      if (Array.isArray(dt.types)) return dt.types.includes('Files')
      try { return dt.types?.contains?.('Files') } catch { return false }
    }
    const onDragEnter = (e: DragEvent) => {
      if (!hasFiles(e)) return
      dragCounter++
      dragActive.value = true
      updateOverlayRect()
      addOverlayWatchers()
      e.preventDefault()
    }
    const onDragOver = (e: DragEvent) => {
      if (!hasFiles(e)) return
      e.preventDefault()
      dragActive.value = true
      updateOverlayRect()
    }
    const onDragLeave = (e: DragEvent) => {
      if (!hasFiles(e)) return
      dragCounter = Math.max(0, dragCounter - 1)
      if (dragCounter === 0) {
        dragActive.value = false
        removeOverlayWatchers()
      }
    }
    const onDropOverlay = (e: DragEvent) => {
      if (!hasFiles(e)) return
      e.preventDefault()
      e.stopPropagation()
      dragCounter = 0
      dragActive.value = false
      removeOverlayWatchers()
      const files = Array.from(e.dataTransfer?.files || [])
      if (files.length) handleFiles(files)
    }

    // Scope for DnD listeners (parent blue-500 border if available)
    let dropScopeEl: HTMLElement | null = null

    const imageProgressTile = async (percent = 0, label = 'Uploading...') => {
      return renderTileToDataUrl(ImageProgressTile, { percent, label })
    }
    // Attachment chips are rendered via a custom TipTap NodeView using lucide icons.
    // Progress and final states are handled by node attributes; no SVG tiles.

    // Inline image helpers below are unused for the tray flow, but kept for parity if needed later.
    const findImagePosByTitle = (view, titleValue) => {
      const imageType = view.state.schema.nodes.image
      let found = null
      view.state.doc.descendants((node, pos) => {
        if (node.type === imageType && node.attrs && node.attrs.title === titleValue) {
          found = { pos, node }
          return false
        }
        return true
      })
      return found
    }

    const insertUploadPlaceholder = (file, uploadId) => {
      const tiptap = editor?.value
      if (!tiptap) return
      // Insert the placeholder image and a trailing space, so subsequent pastes append instead of replacing.
      tiptap
        .chain()
        .focus()
        .insertContent([
          { type: 'image', attrs: { src: '', alt: file?.name || 'image', title: uploadId } },
          { type: 'text', text: ' ' },
        ])
        .run()

      imageProgressTile(0, 'Uploading...').then((dataUrl) => {
        const view = tiptap.view
        const imageType = view.state.schema.nodes.image
        const posFound = findImagePosByTitle(view, uploadId)
        if (!posFound) return
        const tr2 = view.state.tr.setNodeMarkup(posFound.pos, imageType, { ...posFound.node.attrs, src: dataUrl }, posFound.node.marks)
        view.dispatch(tr2)
      })
    }

    const insertAttachmentPlaceholder = (file, uploadId) => {
      const tiptap = editor?.value
      if (!tiptap) return
      tiptap
        .chain()
        .focus()
        .insertContent([
          {
            type: 'attachment',
            attrs: {
              uid: uploadId,
              href: null,
              filename: file?.name || 'file',
              sizeLabel: '',
              uploading: true,
              percent: 0,
              error: false,
            },
          },
          { type: 'text', text: ' ' },
        ])
        .run()
    }

    const updateUploadProgress = (uploadId, percent) => {
      const item = attachments.value.find(a => a.id === uploadId)
      if (item) item.progress = percent
    }

    const findAttachmentPosByUid = (view, uid) => {
      let result = null
      view.state.doc.descendants((node, pos) => {
        if (node.type.name === 'attachment' && node.attrs && node.attrs.uid === uid) {
          result = { pos, node }
          return false
        }
        return true
      })
      return result
    }

    const updateAttachmentUploadProgress = (uploadId, percent, filename) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findAttachmentPosByUid(view, uploadId)
      if (!found) return
      const tr = view.state.tr.setNodeMarkup(found.pos, found.node.type, { ...found.node.attrs, uploading: true, percent, filename: filename || found.node.attrs.filename }, found.node.marks)
      view.dispatch(tr)
    }

    const finalizeUpload = (uploadId, finalUrl, finalTitle) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findImagePosByTitle(view, uploadId)
      if (!found) return
      const imageType = view.state.schema.nodes.image
      // Preload the image so the progress disappears only when the image is ready.
      const preload = new window.Image()
      preload.onload = () => {
        const newAttrs = { ...found.node.attrs, src: finalUrl, title: finalTitle || found.node.attrs.title }
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks)
        view.dispatch(tr)
      }
      preload.onerror = () => {
        // Fall back to immediate swap if preload fails
        const newAttrs = { ...found.node.attrs, src: finalUrl, title: finalTitle || found.node.attrs.title }
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks)
        view.dispatch(tr)
      }
      preload.src = finalUrl
    }

    const finalizeAttachment = (uploadId, url, filename, sizeLabel) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findAttachmentPosByUid(view, uploadId)
      if (!found) return
      const tr = view.state.tr.setNodeMarkup(found.pos, found.node.type, {
        ...found.node.attrs,
        href: url,
        filename: filename || found.node.attrs.filename,
        sizeLabel: sizeLabel || '',
        uploading: false,
        percent: 100,
        error: false,
      }, found.node.marks)
      view.dispatch(tr)
    }

    const formatBytes = (bytes = 0) => {
      if (!bytes || isNaN(bytes)) return '0 B'
      const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
      const unitIndex = Math.floor(Math.log(bytes) / Math.log(1024))
      const value = bytes / Math.pow(1024, unitIndex)
      return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${sizes[unitIndex]}`
    }

    const uploadToTemp = async (file, uploadId) => {
      if (!file) return
      try {
        const formData = new FormData()
        formData.append('file', file, file.name)
        formData.append('temp_identifier', tempIdentifier.value)

        const response = await axios.post(route('attachments.upload'), formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (progressEvent) => {
            try {
              const total = progressEvent?.total || 0
              const loaded = progressEvent?.loaded || 0
              const percent = total > 0 ? Math.round((loaded / total) * 100) : 0
              updateUploadProgress(uploadId, percent)
            } catch (_) { /* noop */ }
          },
        })

        const data = response?.data || {}
        const url = data.url
        const title = data.original_filename || file.name
        if (!url) throw new Error('No URL in response')
        const item = attachments.value.find(a => a.id === uploadId)
        if (item) {
          item.url = url
          item.filename = title || item.filename
          item.progress = 100
          item.status = 'done'
          if (item.isImage) item.previewUrl = url
        }
      } catch (error) {
        console.error('Upload failed', error)
        const item = attachments.value.find(a => a.id === uploadId)
        if (item) {
          item.status = 'error'
          item.progress = 0
        }
      }
    }

    const uploadAttachment = async (file, uploadId) => {
      if (!file) return
      try {
        const formData = new FormData()
        formData.append('file', file, file.name)
        formData.append('temp_identifier', tempIdentifier.value)

        const response = await axios.post(route('attachments.upload'), formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (progressEvent) => {
            try {
              const total = progressEvent?.total || 0
              const loaded = progressEvent?.loaded || 0
              const percent = total > 0 ? Math.round((loaded / total) * 100) : 0
              updateAttachmentUploadProgress(uploadId, percent, file.name)
            } catch (_) { /* noop */ }
          },
        })

        const data = response?.data || {}
        const url = data.url
        const filename = data.original_filename || file.name
        const sizeLabel = data.size ? formatBytes(parseInt(data.size, 10)) : formatBytes(file.size)
        if (!url) throw new Error('No URL in response')
        finalizeAttachment(uploadId, url, filename, sizeLabel)
      } catch (error) {
        console.error('Attachment upload failed', error)
        try {
          if (!editor?.value) return
          const view = editor.value.view
          const found = findAttachmentPosByUid(view, uploadId)
          if (!found) return
          const tr = view.state.tr.setNodeMarkup(found.pos, found.node.type, { ...found.node.attrs, uploading: false, error: true }, found.node.marks)
          view.dispatch(tr)
        } catch (_) { /* noop */ }
      }
    }

    // Paste/Drop are handled by AttachmentsCapture extension; keep these only for reference.
    const handlePaste = (_event: ClipboardEvent) => { /* handled by extension */ }

    const handleDrop = (_event: DragEvent) => { /* handled by extension */ }

    const preventDefault = (event) => {
      if (!event) return
      event.preventDefault()
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation()
      event.stopPropagation()
    }

    const openImageModal = (src) => {
      modalImageSrc.value = src
      isImageModalOpen.value = true
    }
    const closeImageModal = () => {
      isImageModalOpen.value = false
      modalImageSrc.value = ''
    }
    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        if (isAttachmentsModalOpen.value) return closeAttachmentsModal()
        return closeImageModal()
      }
      if (isAttachmentsModalOpen.value) {
        if (event.key === 'ArrowRight') return nextAttachment()
        if (event.key === 'ArrowLeft') return prevAttachment()
      }
    }
    const onClickInEditor = (event) => {
      const target = event.target as HTMLElement
      // Only react to clicks on images inside the editor content (ProseMirror), not inside our attachments tray/modal
      const inTray = !!target.closest('[data-attachments-tray]')
      const inEditor = !!target.closest('.ProseMirror')
      if (!inEditor || inTray) return

      if (target instanceof HTMLImageElement) {
        const title = target.getAttribute('title') || ''
        if (title.startsWith('attachment|')) {
          const parts = title.split('|')
          const url = parts[3] || ''
          if (url) window.open(url, '_blank')
          return
        }
        if (typeof target.src === 'string' && target.src.startsWith('data:image/svg+xml')) return
        event.preventDefault()
        if (typeof (event as any).stopImmediatePropagation === 'function') (event as any).stopImmediatePropagation()
        event.stopPropagation()
        openImageModal((target as HTMLImageElement).src)
      }
    }

    onMounted(() => {
      const element = containerRef.value
      if (!element) return
      // Paste/Drop are handled by the AttachmentsCapture extension.
      element.addEventListener('click', onClickInEditor, true)
      window.addEventListener('keydown', onKeydown)
      // Drag & Drop listeners on the parent blue-500 border region if possible
      // Prefer an explicit drop scope marker on a parent wrapper
      const markedScope = element.closest('[data-drop-scope]') as HTMLElement | null
      dropScopeEl = markedScope || (element.parentElement as HTMLElement) || element
      // Use capture phase so we can consume the event before ProseMirror sees it (avoid duplicates)
      dropScopeEl.addEventListener('dragenter', onDragEnter, true)
      dropScopeEl.addEventListener('dragover', onDragOver, true)
      dropScopeEl.addEventListener('dragleave', onDragLeave, true)
      dropScopeEl.addEventListener('drop', onDropOverlay, true)
    })

    onBeforeUnmount(() => {
      const element = containerRef.value
      if (!element) return
      element.removeEventListener('click', onClickInEditor, true)
      window.removeEventListener('keydown', onKeydown)
      if (dropScopeEl) {
        dropScopeEl.removeEventListener('dragenter', onDragEnter, true)
        dropScopeEl.removeEventListener('dragover', onDragOver, true)
        dropScopeEl.removeEventListener('dragleave', onDragLeave, true)
        dropScopeEl.removeEventListener('drop', onDropOverlay, true)
      }
      editor?.value?.destroy?.()
    })

    return { containerRef, editor, isImageModalOpen, modalImageSrc, closeImageModal, attachments, fileInputRef, openFileDialog, onFileInputChange, handleFiles, removeAttachment, isAttachmentsModalOpen, activeAttachmentIndex, openAttachmentModalAt, closeAttachmentsModal, nextAttachment, prevAttachment, attachmentsModalRef, onModalImageLoad, onModalImageError, showEmojiPicker, openEmoji, closeEmoji, onEmojiClick, dragActive }
  },
})
</script>

<style>
.tiptap-editor { position: relative; }
.tiptap-editor .ProseMirror {
  min-height: 300px;
  max-height: 700px;
  overflow-y: auto;
}
.tiptap-editor .ProseMirror img {
  display: inline-block;
  vertical-align: top;
  max-width: 200px;
  max-height: 200px;
  width: auto;
  height: auto;
  object-fit: contain;
  border-radius: 0.25rem;
  cursor: zoom-in;
  margin: 0 0.5rem 0.5rem 0;
}
.tiptap-editor .ProseMirror:focus,
.tiptap-editor .ProseMirror:focus-visible {
  outline: none !important;
  box-shadow: none !important;
}
.tiptap-editor:focus,
.tiptap-editor:focus-within {
  outline: none !important;
  box-shadow: none !important;
}
.tiptap-editor .ProseMirror p:has(> img:only-child) {
  display: inline-block;
  margin: 0 0.5rem 0.5rem 0;
}
</style>


