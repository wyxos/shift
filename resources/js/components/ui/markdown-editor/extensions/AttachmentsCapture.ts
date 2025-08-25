import { Extension } from '@tiptap/core'
import { Plugin } from '@tiptap/pm/state'

export interface AttachmentsCaptureOptions {
  onFiles: (files: File[]) => void
}

export const AttachmentsCapture = Extension.create<AttachmentsCaptureOptions>({
  name: 'attachmentsCapture',
  addOptions() {
    return {
      onFiles: () => {},
    }
  },
  addProseMirrorPlugins() {
    const getFiles = (e: ClipboardEvent | DragEvent) => {
      const dt = 'clipboardData' in e ? e.clipboardData : (e as DragEvent).dataTransfer
      if (!dt?.files || dt.files.length === 0) return [] as File[]
      return Array.from(dt.files)
    }
    return [
      new Plugin({
        props: {
          handlePaste: (_view, e: ClipboardEvent) => {
            const files = getFiles(e)
            if (!files.length) return false
            this.options.onFiles(files)
            return true
          },
          handleDrop: (_view, e: DragEvent) => {
            const files = getFiles(e)
            if (!files.length) return false
            this.options.onFiles(files)
            return true
          },
        },
      }),
    ]
  },
})
