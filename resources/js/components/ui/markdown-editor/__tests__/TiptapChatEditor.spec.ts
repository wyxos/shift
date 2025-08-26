import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import TiptapChatEditor from '@/components/ui/markdown-editor/TiptapChatEditor.vue'

// Mock route() helper from Ziggy to avoid undefined in any incidental usage
// @ts-expect-error - inject global route mock
globalThis.route = (name: string) => {
  if (name === 'attachments.upload') return '/attachments/upload'
  return '/'
}

// Mock TipTap heavy deps to keep tests lightweight
vi.mock('@tiptap/vue-3', async () => {
  const vue = await import('vue')
  return {
    EditorContent: {
      name: 'EditorContent',
      template: '<div class="ProseMirror" />',
    },
    useEditor: () => vue.ref({
      view: { state: { doc: { descendants: () => {} }, schema: { nodes: { image: {} } } }, tr: {} },
      chain: () => ({ focus: () => ({ insertContent: () => ({ run: () => {} }) }) }),
      commands: { clearContent: vi.fn() },
      destroy: vi.fn(),
    }),
  }
})
vi.mock('@tiptap/starter-kit', () => ({ default: { configure: () => ({}) } }))
vi.mock('@tiptap/extension-image', () => ({ default: { configure: () => ({}) } }))
vi.mock('@tiptap/extension-placeholder', () => ({ default: { configure: () => ({}) } }))

function setViewport(width: number, height: number) {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: width })
  Object.defineProperty(window, 'innerHeight', { configurable: true, value: height })
  window.dispatchEvent(new Event('resize'))
}

function createImageAttachment(url = '/img.png') {
  return {
    id: 'a1',
    isImage: true,
    filename: 'img.png',
    sizeLabel: '1 MB',
    previewUrl: url,
    url,
    progress: 100,
    status: 'done',
  }
}

async function openWithOneImage(wrapper: any) {
  wrapper.vm.attachments = [createImageAttachment()]
  await nextTick()
  wrapper.vm.openAttachmentModalAt(0)
  await nextTick()
}

describe('TiptapChatEditor attachments modal sizing', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    if (wrapper) {
      wrapper.unmount()
      wrapper = null
    }
  })

  it('starts at minimum size before image load and then resizes to fit image capped by viewport', async () => {
    // Viewport large enough for md breakpoint
    setViewport(1200, 900) // maxW=1080, maxH=810

    wrapper = mount(TiptapChatEditor)
    await openWithOneImage(wrapper)

    // Before image load: should be min (md => 480x480)
    const modal = wrapper!.element.querySelector('div.bg-background\\/95.rounded.shadow-lg') as HTMLDivElement
    expect(modal).toBeTruthy()
    expect(modal.style.width).toBe('480px')
    expect(modal.style.height).toBe('480px')

    // Simulate image load with natural size 1600x1200
    await wrapper!.vm.onModalImageLoad({ target: { naturalWidth: 1600, naturalHeight: 1200 } } as any)
    await nextTick()
    await flushPromises()

    // Expected calculations:
    // maxW=1080, maxH=810, PAD_X=16, PAD_Y=16, navH≈0
    // availW=1064, availH=794, scale=min(1,1064/1600,794/1200)=794/1200=0.661666..
    // imgW=floor(1600*scale)=1058, imgH=floor(1200*scale)=794
    // targetW=imgW+16=1074, targetH=imgH+16+0=810
    expect(modal.style.width).toBe('1074px')
    expect(modal.style.height).toBe('810px')

    // Now resize viewport smaller and ensure it recomputes
    setViewport(600, 500) // maxW=540, maxH=450
    await nextTick()
    await flushPromises()

    // Recomputed expected:
    // availW=524, availH=434, scale=min(1,524/1600,434/1200)=0.3275
    // imgW=floor(524)=524, imgH=floor(393)=393
    // targetW=524+16=540, targetH=393+16=409
    expect(modal.style.width).toBe('540px')
    expect(modal.style.height).toBe('409px')
  })

  it('resets to minimum on next/prev until image loads, then grows to natural size within caps', async () => {
    setViewport(1200, 900)

    wrapper = mount(TiptapChatEditor)

    // Two images
    wrapper.vm.attachments = [createImageAttachment('/imgA.png'), createImageAttachment('/imgB.png')]
    await nextTick()
    wrapper.vm.openAttachmentModalAt(0)
    await nextTick()

    // Load first image (1600x1200) -> expect 1074x810 as computed above
    await wrapper!.vm.onModalImageLoad({ target: { naturalWidth: 1600, naturalHeight: 1200 } } as any)
    await nextTick()
    let modal = wrapper!.element.querySelector('div.bg-background\\/95.rounded.shadow-lg') as HTMLDivElement
    expect(modal.style.width).toBe('1074px')
    expect(modal.style.height).toBe('810px')

  // Go next: should reset to min size until new image loads
    await wrapper!.vm.nextAttachment()
    await nextTick()
    await flushPromises()
    await nextTick()
    modal = wrapper!.element.querySelector('div.bg-background\\/95.rounded.shadow-lg') as HTMLDivElement
    expect(modal.style.width).toBe('480px')
    expect(modal.style.height).toBe('480px')

    // Load second image (800x600) -> compute scale=1, targetW=800+16=816, targetH=600+16=616
    await wrapper!.vm.onModalImageLoad({ target: { naturalWidth: 800, naturalHeight: 600 } } as any)
    await nextTick()
    await flushPromises()
    await nextTick()
    expect(['480px','816px']).toContain(modal.style.width)
    // After load completes it should reach final size
    expect(modal.style.width).toBe('816px')
    expect(modal.style.height).toBe('616px')
  })

  it('does not open modal when clicking remove on attachment tile', async () => {
    wrapper = mount(TiptapChatEditor)

    // Seed tray with one image
    wrapper.vm.attachments = [createImageAttachment('/will-remove.png')]
    await nextTick()

    // Click remove button
    const removeBtn = wrapper.element.querySelector('[data-attachments-tray] button[aria-label="Remove"]') as HTMLButtonElement
    expect(removeBtn).toBeTruthy()
    removeBtn.click()
    await nextTick()

    // Ensure item removed and modal not opened
    expect(wrapper.vm.attachments.length).toBe(0)
    const overlayModal = document.querySelector('div.fixed.inset-0.z-50') as HTMLElement | null
    expect(overlayModal).toBeNull()
  })

  it('ignores clicks inside the attachments tray for the legacy image modal', async () => {
    wrapper = mount(TiptapChatEditor)

    // Seed tray with one image (no uploads triggered)
    wrapper.vm.attachments = [createImageAttachment('/tray.png')]
    await nextTick()

    // Click the preview inside the tray; this should NOT open the legacy image modal
    const img = wrapper.element.querySelector('[data-attachments-tray] img') as HTMLImageElement
    expect(img).toBeTruthy()
    img.click()
    await nextTick()

    const legacyImg = wrapper.element.querySelector('img[alt="full-size"]')
    expect(legacyImg).toBeNull()
  })
})
