import { Node, mergeAttributes } from '@tiptap/core'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import AttachmentView from './AttachmentView.vue'

export interface AttachmentAttrs {
  uid: string | null
  href: string | null
  filename: string | null
  sizeLabel: string | null
  uploading: boolean
  percent: number
  error: boolean
}

export const Attachment = Node.create({
  name: 'attachment',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,

  addAttributes() {
    return {
      uid: { default: null },
      href: { default: null },
      filename: { default: null },
      sizeLabel: { default: null },
      uploading: { default: false },
      percent: { default: 0 },
      error: { default: false },
    }
  },

  parseHTML() {
    return [
      { tag: 'span[data-attachment]' },
    ]
  },

  renderHTML({ HTMLAttributes }) {
    return ['span', mergeAttributes(HTMLAttributes, { 'data-attachment': 'true' })]
  },

  addNodeView() {
    return VueNodeViewRenderer(AttachmentView)
  },
})
