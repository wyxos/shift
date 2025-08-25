<template>
    <div ref="containerRef" class="tiptap-editor relative border-2 border-blue-200 rounded p-4">
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
                    @click="removeAttachment(item.id)"
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
                @dragover.prevent
                @dragenter.prevent
                @drop.prevent="(e) => handleFiles(Array.from(e.dataTransfer?.files || []))"
            >
                <Icon name="plus" :size="24" />
            </div>
            <input ref="fileInputRef" type="file" class="hidden" multiple @change="onFileInputChange" />
        </div>
        <!-- Emoji picker popover -->
        <div v-if="showEmojiPicker" class="absolute z-50 mt-2">
            <div class="relative bg-background rounded-md shadow-lg border">
                <button class="absolute right-2 top-2" aria-label="Close emoji" @click="closeEmoji">
                    <Icon name="x" :size="14" />
                </button>
                <emoji-picker @emoji-click="onEmojiClick"></emoji-picker>
            </div>
        </div>
    </div>

    <!-- Attachments modal -->
    <div
        v-if="isAttachmentsModalOpen && activeAttachmentIndex !== null"
        class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center"
        @click="closeAttachmentsModal"
    >
        <div class="relative" @click.stop>
            <button class="absolute right-2 top-2 text-white" aria-label="Close" @click="closeAttachmentsModal">
                <Icon name="x" :size="20" />
            </button>

            <div class="bg-background/95 rounded shadow-lg transition-all duration-300 ease-in-out min-w-[320px] min-h-[320px] sm:min-w-[360px] sm:min-h-[360px] md:min-w-[480px] md:min-h-[480px] max-w-[90vw] max-h-[90vh] p-2 flex flex-col items-center" :style="{ width: modalContainerW + 'px', height: modalContainerH + 'px' }">
                <template v-if="attachments[activeAttachmentIndex].isImage">
                    <div class="relative w-full flex items-center justify-center" :style="{ height: imageAreaH + 'px' }">
                        <div v-if="modalImageLoading" class="absolute inset-0 flex items-center justify-center text-muted-foreground">
                            <Icon name="loader2" :size="24" class="animate-spin" />
                        </div>
                        <img :src="attachments[activeAttachmentIndex].url || attachments[activeAttachmentIndex].previewUrl"
                             @load="onModalImageLoad" @error="onModalImageError"
                             class="max-w-full max-h-full object-contain transition-opacity duration-200"
                             :class="{ 'opacity-0': modalImageLoading, 'opacity-100': !modalImageLoading }"
                             alt="attachment" />
                    </div>
                </template>
                <template v-else>
                    <div class="flex-1 w-full h-full min-h-[200px] flex flex-col items-center justify-center text-foreground">
                        <Icon name="file" :size="64" />
                        <div class="mt-2 text-sm">{{ attachments[activeAttachmentIndex].filename }}</div>
                        <div class="text-xs opacity-80">{{ attachments[activeAttachmentIndex].sizeLabel }}</div>
                        <div class="mt-3">
                            <a v-if="attachments[activeAttachmentIndex].url" :href="attachments[activeAttachmentIndex].url" target="_blank" rel="noopener" class="underline">Open</a>
                        </div>
                    </div>
                </template>
                <div class="mt-3 flex items-center justify-center gap-3" ref="navEl">
                    <Button size="icon" aria-label="Previous" @click="prevAttachment">
                        <Icon name="chevronLeft" :size="18" />
                    </Button>
                    <Button size="icon" aria-label="Next" @click="nextAttachment">
                        <Icon name="chevronRight" :size="18" />
                    </Button>
                </div>
            </div>
        </div>
    </div>

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
import { defineComponent, onMounted, onBeforeUnmount, ref, nextTick, computed, watch } from 'vue'
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
import 'emoji-picker-element'

export default defineComponent({
  name: 'TiptapChatEditor',
  components: { Icon, EditorContent, Button },
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
      modalImageLoading.value = true
      modalNaturalW.value = 0
      modalNaturalH.value = 0
      nextTick(() => updateModalSize())
    }
    const closeAttachmentsModal = () => {
      isAttachmentsModalOpen.value = false
      activeAttachmentIndex.value = null
    }
    const nextAttachment = () => {
      if (activeAttachmentIndex.value === null) return
      const len = attachments.value.length
      activeAttachmentIndex.value = (activeAttachmentIndex.value + 1) % len
      modalImageLoading.value = true
      modalNaturalW.value = 0
      modalNaturalH.value = 0
      nextTick(() => updateModalSize())
    }
    const prevAttachment = () => {
      if (activeAttachmentIndex.value === null) return
      const len = attachments.value.length
      activeAttachmentIndex.value = (activeAttachmentIndex.value - 1 + len) % len
      modalImageLoading.value = true
      modalNaturalW.value = 0
      modalNaturalH.value = 0
      nextTick(() => updateModalSize())
    }

    // Modal image loading state
    const modalImageLoading = ref(true)

    // Modal dynamic sizing state
    const navEl = ref<HTMLElement | null>(null)
    const navHeight = ref(0)
    const modalNaturalW = ref(0)
    const modalNaturalH = ref(0)
    const viewportW = ref(typeof window !== 'undefined' ? window.innerWidth : 1024)
    const viewportH = ref(typeof window !== 'undefined' ? window.innerHeight : 768)
    const modalContainerW = ref(360)
    const modalContainerH = ref(360)
    const PAD_X = 16
    const PAD_Y = 16
    const minW = computed(() => (viewportW.value >= 768 ? 480 : (viewportW.value >= 640 ? 360 : 320)))
    const minH = computed(() => (viewportW.value >= 768 ? 480 : (viewportW.value >= 640 ? 360 : 320)))
    const imageAreaH = computed(() => Math.max(0, modalContainerH.value - navHeight.value - PAD_Y))

    const updateModalSize = () => {
      try {
        const maxW = Math.floor(viewportW.value * 0.9)
        const maxH = Math.floor(viewportH.value * 0.9)
        const isImage = activeAttachmentIndex.value !== null && !!attachments.value[activeAttachmentIndex.value]?.isImage
        const navH = navEl.value?.offsetHeight || 0
        navHeight.value = navH

        if (!isImage || modalNaturalW.value <= 0 || modalNaturalH.value <= 0) {
          modalContainerW.value = Math.max(minW.value, Math.min(maxW, minW.value))
          modalContainerH.value = Math.max(minH.value, Math.min(maxH, minH.value))
          return
        }

        const availWForImage = Math.max(1, maxW - PAD_X)
        const availHForImage = Math.max(1, maxH - PAD_Y - navH)
        const scale = Math.min(1, availWForImage / modalNaturalW.value, availHForImage / modalNaturalH.value)
        const imgW = Math.floor(modalNaturalW.value * scale)
        const imgH = Math.floor(modalNaturalH.value * scale)

        const targetW = imgW + PAD_X
        const targetH = imgH + PAD_Y + navH

        modalContainerW.value = Math.max(minW.value, Math.min(maxW, targetW))
        modalContainerH.value = Math.max(minH.value, Math.min(maxH, targetH))
      } catch (_) { /* noop */ }
    }

    const onModalImageLoad = (e: Event) => {
      try {
        const img = e?.target as HTMLImageElement
        modalNaturalW.value = img?.naturalWidth || 0
        modalNaturalH.value = img?.naturalHeight || 0
      } catch (_) {
        modalNaturalW.value = 0
        modalNaturalH.value = 0
      } finally {
        modalImageLoading.value = false
        nextTick(() => updateModalSize())
      }
    }
    const onModalImageError = () => {
      modalNaturalW.value = 0
      modalNaturalH.value = 0
      modalImageLoading.value = false
      nextTick(() => updateModalSize())
    }

    const onResize = () => {
      viewportW.value = window.innerWidth
      viewportH.value = window.innerHeight
      updateModalSize()
    }

    // Keep modal sized correctly when it's opened or slide changes
    watch([isAttachmentsModalOpen, activeAttachmentIndex], () => {
      if (!isAttachmentsModalOpen.value) return
      // reset until new image loads
      modalNaturalW.value = 0
      modalNaturalH.value = 0
      nextTick(() => updateModalSize())
    })

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
      window.addEventListener('resize', onResize)
    })

    onBeforeUnmount(() => {
      const element = containerRef.value
      if (!element) return
      element.removeEventListener('click', onClickInEditor, true)
      window.removeEventListener('keydown', onKeydown)
      window.removeEventListener('resize', onResize)
      editor?.value?.destroy?.()
    })

    return { containerRef, editor, isImageModalOpen, modalImageSrc, closeImageModal, attachments, fileInputRef, openFileDialog, onFileInputChange, handleFiles, removeAttachment, isAttachmentsModalOpen, activeAttachmentIndex, openAttachmentModalAt, closeAttachmentsModal, nextAttachment, prevAttachment, navEl, modalContainerW, modalContainerH, imageAreaH, modalImageLoading, onModalImageLoad, onModalImageError, showEmojiPicker, openEmoji, closeEmoji, onEmojiClick }
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


