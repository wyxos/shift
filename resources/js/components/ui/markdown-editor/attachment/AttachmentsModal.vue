<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, toRefs, watch, nextTick } from 'vue'
import Icon from '@/components/Icon.vue'
import { Button } from '@/components/ui/button'

interface AttachmentItem {
  id: string
  isImage: boolean
  filename: string
  sizeLabel?: string
  previewUrl?: string
  url?: string
  progress?: number
  status?: 'uploading' | 'done' | 'error'
}

const props = defineProps<{
  open: boolean
  attachments: AttachmentItem[]
  activeIndex: number | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'next'): void
  (e: 'prev'): void
}>()

const { open, attachments, activeIndex } = toRefs(props)

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
    const isImage = activeIndex.value !== null && !!attachments.value[activeIndex.value]?.isImage
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

watch([open, activeIndex], () => {
  if (!open.value) return
  modalImageLoading.value = true
  modalNaturalW.value = 0
  modalNaturalH.value = 0
  nextTick(() => updateModalSize())
})

onMounted(() => {
  // Initialize to minimum size immediately
  modalContainerW.value = minW.value
  modalContainerH.value = minH.value
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
})

// Expose handlers so parent tests can trigger image load
defineExpose({ onModalImageLoad, onModalImageError })
</script>

<template>
  <div
    v-if="open && activeIndex !== null"
    class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center"
    @click="emit('close')"
  >
    <div class="relative" @click.stop>
      <button class="absolute right-2 top-2 text-white" aria-label="Close" @click="emit('close')">
        <Icon name="x" :size="20" />
      </button>

      <div class="bg-background/95 rounded shadow-lg transition-all duration-300 ease-in-out min-w-[320px] min-h-[320px] sm:min-w-[360px] sm:min-h-[360px] md:min-w-[480px] md:min-h-[480px] max-w-[90vw] max-h-[90vh] p-2 flex flex-col items-center" :style="{ width: modalContainerW + 'px', height: modalContainerH + 'px' }">
        <template v-if="attachments[activeIndex].isImage">
          <div class="relative w-full flex items-center justify-center" :style="{ height: imageAreaH + 'px' }">
            <div v-if="modalImageLoading" class="absolute inset-0 flex items-center justify-center text-muted-foreground">
              <Icon name="loader2" :size="24" class="animate-spin" />
            </div>
            <img :src="attachments[activeIndex].url || attachments[activeIndex].previewUrl"
                 @load="onModalImageLoad" @error="onModalImageError"
                 class="max-w-full max-h-full object-contain transition-opacity duration-200"
                 :class="{ 'opacity-0': modalImageLoading, 'opacity-100': !modalImageLoading }"
                 alt="attachment" />
          </div>
        </template>
        <template v-else>
          <div class="flex-1 w-full h-full min-h-[200px] flex flex-col items-center justify-center text-foreground cursor-pointer select-none" @click="() => { const u = attachments[activeIndex].url; if (u) window.open(u, '_blank', 'noopener') }" title="Open attachment">
            <Icon name="file" :size="64" />
            <div class="mt-2 text-sm">{{ attachments[activeIndex].filename }}</div>
            <div class="text-xs opacity-80">{{ attachments[activeIndex].sizeLabel }}</div>
          </div>
        </template>
        <div class="mt-3 flex items-center justify-center gap-3" ref="navEl">
          <Button size="icon" aria-label="Previous" @click="emit('prev')">
            <Icon name="chevronLeft" :size="18" />
          </Button>
          <Button size="icon" aria-label="Next" @click="emit('next')">
            <Icon name="chevronRight" :size="18" />
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>

