<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3'
import { X } from 'lucide-vue-next'

const props = defineProps<{
  node: { attrs: Record<string, any> }
  deleteNode: () => void
}>()

const remove = () => {
  props.deleteNode()
}
</script>

<template>
  <NodeViewWrapper as="span" class="relative inline-block align-middle group">
    <img
      class="editor-tile"
      :src="props.node.attrs.src"
      :alt="props.node.attrs.alt || ''"
      :title="props.node.attrs.title || ''"
    />
    <button
      type="button"
      contenteditable="false"
      class="absolute right-2 top-2 inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-md bg-red-50 text-red-600 shadow-sm ring-1 ring-red-200 transition hover:bg-red-100 hover:text-red-700 hover:ring-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
      @click="remove"
    >
      <X :size="14" />
    </button>
  </NodeViewWrapper>
</template>
