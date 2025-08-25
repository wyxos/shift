<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3'
import type { NodeViewProps } from '@tiptap/vue-3'
import Icon from '@/components/Icon.vue'

const props = defineProps<NodeViewProps>()

const onClick = (event: MouseEvent) => {
  const { href } = props.node.attrs as any
  if (!href) return
  event.preventDefault()
  event.stopPropagation()
  window.open(href as string, '_blank', 'noopener')
}
</script>

<template>
  <NodeViewWrapper
    as="span"
    class="inline-block align-top mr-2 mb-2 select-none"
    contenteditable="false"
    data-attachment
    @click="onClick"
  >
    <span
      class="inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs bg-background hover:bg-muted/50 cursor-pointer"
      :class="{ 'opacity-60': node.attrs.uploading, 'border-red-300 bg-red-50 text-red-800': node.attrs.error }"
      title="Attachment"
    >
      <Icon :name="node.attrs.error ? 'alert-triangle' : 'paperclip'" :size="16" />
      <span class="max-w-48 truncate">{{ node.attrs.filename || 'Uploading…' }}</span>
      <span v-if="node.attrs.sizeLabel && !node.attrs.uploading" class="text-muted-foreground">· {{ node.attrs.sizeLabel }}</span>
      <span v-if="node.attrs.uploading" class="text-muted-foreground">{{ Math.round(node.attrs.percent || 0) }}%</span>
    </span>
    <!-- tiny progress bar under chip while uploading -->
    <div v-if="node.attrs.uploading" class="h-0.5 bg-muted rounded mt-0.5">
      <div class="h-0.5 bg-blue-500 rounded" :style="{ width: Math.max(0, Math.min(100, node.attrs.percent || 0)) + '%' }" />
    </div>
  </NodeViewWrapper>
</template>
