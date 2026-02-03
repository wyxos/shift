import { Extension } from '@tiptap/core'
import type { Editor } from '@tiptap/core'
import axiosDefault from 'axios'

export interface ImageUploadOptions {
  // Return the temp identifier used by the backend
  getTempIdentifier: () => string
  // Callback for non-image files
  onNonImageFile: (file: File) => void
  // Axios instance to use
  axios?: typeof axiosDefault
}

function createUploadId() {
  return `upload-${Math.random().toString(36).slice(2)}-${Date.now()}`
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

function findImagePosByTitle(editor: Editor, title: string): number | null {
  const state = editor?.state
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

function insertUploadPlaceholderImage(editor: Editor, uploadId: string, filename: string) {
  // Defer to next tick to avoid dispatching while other transactions are applying
  setTimeout(() => {
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
  }, 0)
}

function buildTempUrl(data: any): string {
  if (data && data.url) return data.url as string
  if (data && data.path) {
    try {
      const m = String(data.path).match(/^temp_attachments\/([^/]+)\/(.+)$/)
      if (m) {
        // @ts-ignore - Ziggy global
        return route('attachments.temp', { temp: m[1], filename: m[2] }) as string
      }
    } catch {}
  }
  return ''
}

async function uploadImage(editor: Editor, file: File, opts: Required<Pick<ImageUploadOptions, 'getTempIdentifier'>> & { axios: typeof axiosDefault }) {
  const uploadId = createUploadId()
  insertUploadPlaceholderImage(editor, uploadId, file.name)

  const formData = new FormData()
  formData.append('file', file)
  formData.append('temp_identifier', opts.getTempIdentifier())

  await (opts.axios).post((route('attachments.upload') as string), formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (evt) => {
      const total = evt.total || 0
      const loaded = evt.loaded || 0
      const percent = total > 0 ? (loaded / total) * 100 : 0
      const pos = findImagePosByTitle(editor, uploadId)
      if (pos != null) {
        const { state, dispatch } = (editor as any).view
        const imageType = state.schema.nodes.image
        const node = state.doc.nodeAt(pos)
        if (node) {
          const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: renderProgressTile(percent) }, node.marks)
          setTimeout(() => dispatch(tr), 0)
        }
      }
    },
  }).then(res => {
    const data = (res as any).data || {}
    const finalUrl: string = buildTempUrl(data)
    if (!finalUrl) return

    let done = false
    const finishSwap = () => { done = true }
    const trySwap = () => {
      if (done) return
      const pos = findImagePosByTitle(editor, uploadId)
      if (pos != null) {
        const { state, dispatch } = (editor as any).view
        const imageType = state.schema.nodes.image
        const node = state.doc.nodeAt(pos)
        if (node) {
          const tr = state.tr.setNodeMarkup(pos, imageType, { ...node.attrs, src: finalUrl, title: '' }, node.marks)
          setTimeout(() => dispatch(tr), 0)
          finishSwap()
        }
      }
    }
    const ImgCtor: any = (globalThis as any).Image
    const img = new ImgCtor()
    const timer: any = setTimeout(() => { trySwap() }, 2000)
    const wrappedSwap = () => { trySwap(); clearTimeout(timer) }
    img.onload = wrappedSwap
    img.onerror = wrappedSwap
    img.src = finalUrl
  }).catch(() => {
    const pos = findImagePosByTitle(editor, uploadId)
    if (pos != null) {
      const { state, dispatch } = (editor as any).view
      const imageType = state.schema.nodes.image
      const node = state.doc.nodeAt(pos)
      if (node) {
      const tr = state.tr.setNodeMarkup(
        pos,
        imageType,
        { ...node.attrs, src: renderProgressTile(0, 'Upload failed'), title: '' },
        node.marks
      )
      setTimeout(() => dispatch(tr), 0)
    }
  }
})
}

export const ImageUpload = Extension.create<ImageUploadOptions>({
  name: 'image-upload',

  addOptions() {
    return {
      getTempIdentifier: () => Date.now().toString(),
      onNonImageFile: () => {},
      axios: axiosDefault,
    }
  },

  addCommands() {
    return {
      insertFiles:
        (files: File[]) => ({ editor }) => {
          const arr = Array.from(files || [])
          arr.forEach(f => {
            if (f.type && f.type.startsWith('image/')) {
              uploadImage(editor, f, { getTempIdentifier: this.options.getTempIdentifier, axios: this.options.axios! })
            } else {
              this.options.onNonImageFile(f)
            }
          })
          return true
        },
      typeText:
        (text: string) => ({ editor }) => {
          const state = editor.state
          const $from = state.selection.$from
          const before = ($from as any).nodeBefore
          const after = ($from as any).nodeAfter
          const isNextToImage = (n: any) => n && n.type && n.type.name === 'image'
          if (isNextToImage(before) || isNextToImage(after)) {
            setTimeout(() => editor.chain().focus().setHardBreak().insertContent(text).run(), 0)
            return true
          }
          return false
        },
    }
  },

  addProseMirrorPlugins() {
    // We expose commands only; UI events are wired in the Vue component
    return [] as any
  },
})

export default ImageUpload
