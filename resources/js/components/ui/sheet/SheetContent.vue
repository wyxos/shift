<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'
import { cn } from '@/lib/utils'
import { reactiveOmit } from '@vueuse/core'
import { X } from 'lucide-vue-next'
import {
  DialogClose,
  DialogContent,
  type DialogContentEmits,
  type DialogContentProps,
  DialogPortal,
  useForwardPropsEmits,
} from 'reka-ui'
import SheetOverlay from './SheetOverlay.vue'

interface SheetContentProps extends DialogContentProps {
  class?: HTMLAttributes['class']
  side?: 'top' | 'right' | 'bottom' | 'left'
  widthPreset?: 'default' | 'task'
}

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<SheetContentProps>(), {
  side: 'right',
  widthPreset: 'default',
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, 'class', 'side', 'widthPreset')

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const horizontalSheetWidthPresets = {
  default: {
    classes:
      'h-full w-[var(--sheet-width-mobile)] max-w-none md:w-[var(--sheet-width-tablet)] md:max-w-none xl:w-fit xl:min-w-[var(--sheet-width-desktop-min)] xl:max-w-fit',
    style: {
      '--sheet-width-mobile': '100vw',
      '--sheet-width-tablet': '50vw',
      '--sheet-width-desktop-min': '800px',
    },
  },
  task: {
    classes:
      'h-full w-[var(--sheet-width-mobile)] max-w-none min-[1441px]:w-fit min-[1441px]:min-w-[var(--sheet-width-desktop-min)] min-[1441px]:max-w-fit',
    style: {
      '--sheet-width-mobile': '100vw',
      '--sheet-width-desktop-min': '800px',
    },
  },
} as const

const horizontalSheetWidthPreset = computed(() => horizontalSheetWidthPresets[props.widthPreset])
</script>

<template>
  <DialogPortal>
    <SheetOverlay />
    <DialogContent
      data-slot="sheet-content"
      :style="side === 'right' || side === 'left' ? horizontalSheetWidthPreset.style : undefined"
      :class="cn(
        'bg-background data-[state=open]:animate-in data-[state=closed]:animate-out fixed z-50 flex flex-col gap-4 overflow-hidden shadow-lg transition ease-in-out data-[state=closed]:duration-300 data-[state=open]:duration-500',
        side === 'right'
          && cn('data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right inset-y-0 right-0 border-l', horizontalSheetWidthPreset.classes),
        side === 'left'
          && cn('data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left inset-y-0 left-0 border-r', horizontalSheetWidthPreset.classes),
        side === 'top'
          && 'data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top inset-x-0 top-0 h-auto border-b',
        side === 'bottom'
          && 'data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom inset-x-0 bottom-0 h-auto border-t',
        props.class)"
      v-bind="{ ...forwarded, ...$attrs }"
    >
      <slot />

      <DialogClose
        class="ring-offset-background focus:ring-ring data-[state=open]:bg-secondary absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none"
      >
        <X class="size-4" />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
