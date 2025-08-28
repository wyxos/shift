import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { h, nextTick } from 'vue'
import Components from '@/pages/Components.vue'

// Mock AppLayout to keep template simple
vi.mock('@/layouts/AppLayout.vue', () => ({
  default: {
    props: ['breadcrumbs'],
    render() {
      return h('div', { class: 'app-layout' }, this.$slots.default?.())
    },
  },
}))

// Mock Inertia Head component
vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => {} },
}))

// Provide a global route helper like ZiggyVue plugin
beforeEach(() => {
  ;(global as any).route = (name: string, params?: any) => {
    if (name === 'attachments.upload') return '/attachments/upload'
    if (name === 'attachments.remove-temp') return '/attachments/remove-temp'
    if (name === 'attachments.temp') {
      const temp = params?.temp ?? ''
      const filename = params?.filename ?? ''
      return `/attachments/temp/${temp}/${filename}`
    }
    return '/'
  }
})

// Mock axios for upload and delete
const postMock = vi.fn()
const deleteMock = vi.fn()
vi.mock('axios', () => ({
  default: {
    post: (...args: any[]) => postMock(...args),
    delete: (...args: any[]) => deleteMock(...args),
  },
}))

describe('Components.vue temp path rendering', () => {
  const originalCreateObjectURL = URL.createObjectURL

  beforeEach(() => {
    // Mock blob URL for local preview
    URL.createObjectURL = vi.fn(() => 'blob:mock-1234') as any
  })

  afterEach(() => {
    URL.createObjectURL = originalCreateObjectURL as any
    postMock.mockReset()
    deleteMock.mockReset()
  })

  async function waitForEditor(wrapper: any) {
    const start = Date.now()
    while (Date.now() - start < 500) {
      const el = wrapper.find('.ProseMirror')
      if (el.exists()) return el
      await new Promise(r => setTimeout(r, 10))
      await nextTick()
    }
    return wrapper.find('.ProseMirror')
  }

  // Utilities for mocking canvas and Image preload
  function mockCanvas() {
    const proto: any = (HTMLCanvasElement as any).prototype
    const origGetContext = proto.getContext
    const origToDataURL = proto.toDataURL
    proto.getContext = vi.fn(() => ({
      fillStyle: '', strokeStyle: '', font: '', textAlign: '',
      fillRect: vi.fn(), strokeRect: vi.fn(), fillText: vi.fn(),
    }))
    proto.toDataURL = vi.fn(() => 'data:image/png;base64,AAAA')
    return () => { proto.getContext = origGetContext; proto.toDataURL = origToDataURL }
  }
  function mockImageAutoOnload() {
    const Original = (global as any).Image
    class MockImage {
      onload: (() => void) | null = null
      onerror: (() => void) | null = null
      set src(_v: string) {
        if (this.onload) setTimeout(() => this.onload && this.onload(), 0)
      }
    }
    ;(global as any).Image = MockImage as any
    return () => { (global as any).Image = Original }
  }

  it('renders attachments.temp URL in image src when server responds with path on drop', async () => {
    postMock.mockImplementation((_url: string, _form: FormData, cfg: any) => {
      if (cfg && cfg.onUploadProgress) {
        cfg.onUploadProgress({ loaded: 90, total: 100 })
        cfg.onUploadProgress({ loaded: 100, total: 100 })
      }
      return Promise.resolve({ data: { path: 'temp_attachments/TEMP123/dropped.png' } })
    })
    const restoreCanvas = mockCanvas()
    const restoreImage = mockImageAutoOnload()

    const wrapper = mount(Components)
    await nextTick()
    const editorEl = await waitForEditor(wrapper)

    const file = new File([new Uint8Array([1,2,3])], 'dropped.png', { type: 'image/png' })
    const ed: any = (wrapper.vm as any).editor
    await nextTick(); await new Promise(r => setTimeout(r, 0))
    ed.commands.insertFiles([file])

    await nextTick()

    const expected = '/attachments/temp/TEMP123/dropped.png'
    const start = Date.now(); let ok = false
    while (Date.now() - start < 500) {
      await new Promise(r => setTimeout(r, 10))
      await nextTick()
      const imgs = editorEl.findAll('img')
      if (imgs.some(i => (i.element.getAttribute('src') || '').includes(expected))) { ok = true; break }
    }
    expect(ok).toBe(true)

    restoreCanvas(); restoreImage()
  })

  it('renders attachments.temp URL in image src when server responds with path on paste', async () => {
    postMock.mockImplementation((_url: string, _form: FormData, cfg: any) => {
      if (cfg && cfg.onUploadProgress) cfg.onUploadProgress({ loaded: 100, total: 100 })
      return Promise.resolve({ data: { path: 'temp_attachments/TEMPABC/pasted.png' } })
    })
    const restoreCanvas = mockCanvas()
    const restoreImage = mockImageAutoOnload()

    const wrapper = mount(Components)
    await nextTick()
    const editorEl = await waitForEditor(wrapper)

    const file = new File([new Uint8Array([1,2,3])], 'pasted.png', { type: 'image/png' })
    const ed: any = (wrapper.vm as any).editor
    await nextTick(); await new Promise(r => setTimeout(r, 0))
    ed.commands.insertFiles([file])

    await nextTick()

    const expected = '/attachments/temp/TEMPABC/pasted.png'
    const start = Date.now(); let ok = false
    while (Date.now() - start < 500) {
      await new Promise(r => setTimeout(r, 10))
      await nextTick()
      const imgs = editorEl.findAll('img')
      if (imgs.some(i => (i.element.getAttribute('src') || '').includes(expected))) { ok = true; break }
    }
    expect(ok).toBe(true)

    restoreCanvas(); restoreImage()
  })

  it('renders non-image attachment with temp path attribute (drop)', async () => {
    postMock.mockResolvedValue({ data: { path: 'temp_attachments/XYZ/doc.txt' } })

    const wrapper = mount(Components)
    await nextTick()
    await waitForEditor(wrapper)

    const file = new File([new Uint8Array([1,2,3])], 'doc.txt', { type: 'text/plain' })
    const ed: any = (wrapper.vm as any).editor
    ed.commands.insertFiles([file])

    await nextTick()

    const start = Date.now(); let found = false
    while (Date.now() - start < 400) {
      await new Promise(r => setTimeout(r, 10))
      await nextTick()
      const item = wrapper.findAll('[data-testid="attachment-item"]').find(i => i.text().includes('doc.txt'))
      if (item) {
        expect(item.attributes('data-temp-path')).toBe('temp_attachments/XYZ/doc.txt')
        found = true; break
      }
    }
    expect(found).toBe(true)
  })

  it('renders non-image attachment with temp path attribute (paste)', async () => {
    postMock.mockResolvedValue({ data: { path: 'temp_attachments/PASTE1/readme.md' } })

    const wrapper = mount(Components)
    await nextTick()
    await waitForEditor(wrapper)

    const file = new File([new Uint8Array([1,2,3])], 'readme.md', { type: 'text/markdown' })
    const ed: any = (wrapper.vm as any).editor
    ed.commands.insertFiles([file])

    await nextTick()

    const start = Date.now(); let found = false
    while (Date.now() - start < 400) {
      await new Promise(r => setTimeout(r, 10))
      await nextTick()
      const item = wrapper.findAll('[data-testid="attachment-item"]').find(i => i.text().includes('readme.md'))
      if (item) {
        expect(item.attributes('data-temp-path')).toBe('temp_attachments/PASTE1/readme.md')
        found = true; break
      }
    }
    expect(found).toBe(true)
  })
})

