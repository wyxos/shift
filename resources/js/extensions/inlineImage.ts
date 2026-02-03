import TiptapImage from '@tiptap/extension-image'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import InlineImageNodeView from '@/components/InlineImageNodeView.vue'

const InlineImage = TiptapImage.extend({
  addNodeView() {
    return VueNodeViewRenderer(InlineImageNodeView)
  },
})

export default InlineImage
