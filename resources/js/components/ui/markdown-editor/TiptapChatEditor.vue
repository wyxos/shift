<script>
import { defineComponent, onMounted, onBeforeUnmount, ref } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import axios from 'axios'
import { renderTileToDataUrl } from './tiles/render'
import ImageProgressTile from './tiles/ImageProgressTile.vue'
import AttachmentProgressTile from './tiles/AttachmentProgressTile.vue'
import AttachmentFinalTile from './tiles/AttachmentFinalTile.vue'
import ImageErrorTile from './tiles/ImageErrorTile.vue'
import AttachmentErrorTile from './tiles/AttachmentErrorTile.vue'

export default defineComponent({
  name: 'TiptapChatEditor',
  components: { EditorContent },
  setup: () => {
    const containerRef = ref(null)
    const isImageModalOpen = ref(false)
    const modalImageSrc = ref('')
    const tempIdentifier = ref(Date.now().toString())

    const editor = useEditor({
      extensions: [
        StarterKit.configure({ codeBlock: false, blockquote: false, heading: false, horizontalRule: false }),
        Image.configure({ allowBase64: true }),
        Placeholder.configure({ placeholder: 'Message…' }),
      ],
      content: '',
    })

    const createUploadId = () => `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`

    const imageProgressTile = async (percent = 0, label = 'Uploading...') => {
      return renderTileToDataUrl(ImageProgressTile, { percent, label })
    }
    const attachmentProgressTile = async (percent = 0, filename = 'Uploading...') => {
      return renderTileToDataUrl(AttachmentProgressTile, { percent, filename })
    }
    const attachmentFinalTile = async (filename = 'file', sizeLabel = '') => {
      return renderTileToDataUrl(AttachmentFinalTile, { filename, sizeLabel })
    }

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
      if (!editor?.value) return
      const view = editor.value.view
      const { state, dispatch } = view
      const imageType = state.schema.nodes.image
      const node = imageType.create({ src: '', alt: file?.name || 'image', title: uploadId })
      const tr = state.tr.replaceSelectionWith(node)
      dispatch(tr.scrollIntoView())
      imageProgressTile(0, 'Uploading...').then((dataUrl) => {
        const posFound = findImagePosByTitle(view, uploadId)
        if (!posFound) return
        const tr2 = view.state.tr.setNodeMarkup(posFound.pos, imageType, { ...posFound.node.attrs, src: dataUrl }, posFound.node.marks)
        view.dispatch(tr2)
      })
      view.focus()
    }

    const insertAttachmentPlaceholder = (file, uploadId) => {
      if (!editor?.value) return
      const view = editor.value.view
      const { state, dispatch } = view
      const imageType = state.schema.nodes.image
      const node = imageType.create({ src: '', alt: file?.name || 'file', title: `attachment:${uploadId}` })
      const tr = state.tr.replaceSelectionWith(node)
      dispatch(tr.scrollIntoView())
      attachmentProgressTile(0, file?.name || 'file').then((dataUrl) => {
        const posFound = findImagePosByTitle(view, `attachment:${uploadId}`)
        if (!posFound) return
        const tr2 = view.state.tr.setNodeMarkup(posFound.pos, imageType, { ...posFound.node.attrs, src: dataUrl }, posFound.node.marks)
        view.dispatch(tr2)
      })
      view.focus()
    }

    const updateUploadProgress = (uploadId, percent) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findImagePosByTitle(view, uploadId)
      if (!found) return
      const imageType = view.state.schema.nodes.image
      imageProgressTile(percent, 'Uploading...').then((dataUrl) => {
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks)
        view.dispatch(tr)
      })
    }

    const updateAttachmentUploadProgress = (uploadId, percent, filename) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findImagePosByTitle(view, `attachment:${uploadId}`)
      if (!found) return
      const imageType = view.state.schema.nodes.image
      attachmentProgressTile(percent, filename).then((dataUrl) => {
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks)
        view.dispatch(tr)
      })
    }

    const finalizeUpload = (uploadId, finalUrl, finalTitle) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findImagePosByTitle(view, uploadId)
      if (!found) return
      const imageType = view.state.schema.nodes.image
      const newAttrs = { ...found.node.attrs, src: finalUrl, title: finalTitle || found.node.attrs.title }
      const tr = view.state.tr.setNodeMarkup(found.pos, imageType, newAttrs, found.node.marks)
      view.dispatch(tr)
    }

    const finalizeAttachment = (uploadId, url, filename, sizeLabel) => {
      if (!editor?.value) return
      const view = editor.value.view
      const found = findImagePosByTitle(view, `attachment:${uploadId}`)
      if (!found) return
      const imageType = view.state.schema.nodes.image
      const tilePromise = attachmentFinalTile(filename, sizeLabel)
      const title = `attachment|${encodeURIComponent(filename || '')}|${encodeURIComponent(sizeLabel || '')}|${url}`
      tilePromise.then((tile) => {
        const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: tile, title }, found.node.marks)
        view.dispatch(tr)
      })
    }

    const formatBytes = (bytes = 0) => {
      if (!bytes || isNaN(bytes)) return '0 B'
      const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
      const unitIndex = Math.floor(Math.log(bytes) / Math.log(1024))
      const value = bytes / Math.pow(1024, unitIndex)
      return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${sizes[unitIndex]}`
    }

    const uploadImage = async (file, uploadId) => {
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
        finalizeUpload(uploadId, url, title)
      } catch (error) {
        console.error('Upload failed', error)
        try {
          if (!editor?.value) return
          const view = editor.value.view
          const found = findImagePosByTitle(view, uploadId)
          if (!found) return
          const imageType = view.state.schema.nodes.image
          renderTileToDataUrl(ImageErrorTile, { message: 'Upload failed' }).then((dataUrl) => {
            const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks)
            view.dispatch(tr)
          })
        } catch (_) { /* noop */ }
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
          const found = findImagePosByTitle(view, `attachment:${uploadId}`)
          if (!found) return
          const imageType = view.state.schema.nodes.image
          renderTileToDataUrl(AttachmentErrorTile, { message: 'Upload failed' }).then((dataUrl) => {
            const tr = view.state.tr.setNodeMarkup(found.pos, imageType, { ...found.node.attrs, src: dataUrl }, found.node.marks)
            view.dispatch(tr)
          })
        } catch (_) { /* noop */ }
      }
    }

    const handlePaste = (event) => {
      if (!event || !event.clipboardData) return
      const items = event.clipboardData.items || []
      const files = []
      for (let index = 0; index < items.length; index++) {
        const item = items[index]
        if (item.kind === 'file') {
          const file = item.getAsFile()
          if (file) files.push(file)
        }
      }
      if (files.length > 0) {
        event.preventDefault()
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation()
        event.stopPropagation()
        files.forEach((file) => {
          const id = createUploadId()
          if (file.type && file.type.startsWith('image/')) {
            insertUploadPlaceholder(file, id)
            uploadImage(file, id)
          } else {
            insertAttachmentPlaceholder(file, id)
            uploadAttachment(file, id)
          }
        })
      }
    }

    const handleDrop = (event) => {
      if (!event) return
      event.preventDefault()
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation()
      event.stopPropagation()
      const dataTransfer = event.dataTransfer
      if (!dataTransfer) return
      const files = Array.from(dataTransfer.files || [])
      if (files.length > 0) {
        files.forEach((file) => {
          const id = createUploadId()
          if (file.type && file.type.startsWith('image/')) {
            insertUploadPlaceholder(file, id)
            uploadImage(file, id)
          } else {
            insertAttachmentPlaceholder(file, id)
            uploadAttachment(file, id)
          }
        })
      }
    }

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
      if (event.key === 'Escape') closeImageModal()
    }
    const onClickInEditor = (event) => {
      const target = event.target
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
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation()
        event.stopPropagation()
        openImageModal(target.src)
      }
    }

    onMounted(() => {
      const element = containerRef.value
      if (!element) return
      element.addEventListener('paste', handlePaste, true)
      element.addEventListener('dragover', preventDefault, true)
      element.addEventListener('dragenter', preventDefault, true)
      element.addEventListener('drop', handleDrop, true)
      element.addEventListener('click', onClickInEditor, true)
      window.addEventListener('keydown', onKeydown)
    })

    onBeforeUnmount(() => {
      const element = containerRef.value
      if (!element) return
      element.removeEventListener('paste', handlePaste, true)
      element.removeEventListener('dragover', preventDefault, true)
      element.removeEventListener('dragenter', preventDefault, true)
      element.removeEventListener('drop', handleDrop, true)
      element.removeEventListener('click', onClickInEditor, true)
      window.removeEventListener('keydown', onKeydown)
      editor?.value?.destroy?.()
    })

    return { containerRef, editor, isImageModalOpen, modalImageSrc, closeImageModal }
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

<template>
  <div ref="containerRef" class="tiptap-editor relative border-2 border-blue-200 rounded p-4">
    <EditorContent :editor="editor" />
  </div>

  <div
    v-if="isImageModalOpen"
    class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center"
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
