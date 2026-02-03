import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { h, nextTick } from 'vue'
import ShiftEditor from '@/components/ShiftEditor.vue'

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

// Route helper
beforeEach(() => {
  ;(global as any).route = (name: string) => {
    if (name === 'attachments.upload-init') return '/attachments/upload-init'
    if (name === 'attachments.upload-status') return '/attachments/upload-status'
    if (name === 'attachments.upload-chunk') return '/attachments/upload-chunk'
    if (name === 'attachments.upload-complete') return '/attachments/upload-complete'
    if (name === 'attachments.remove-temp') return '/attachments/remove-temp'
    return '/'
  }
})

// Mock axios for upload and delete
const postMock = vi.fn()
const getMock = vi.fn()
const deleteMock = vi.fn()
vi.mock('axios', () => ({
  default: {
    post: (...args: any[]) => postMock(...args),
    get: (...args: any[]) => getMock(...args),
    delete: (...args: any[]) => deleteMock(...args),
  },
}))

describe('ShiftEditor TipTap behaviours via commands', () => {
  const originalCreateObjectURL = URL.createObjectURL

  beforeEach(() => {
    URL.createObjectURL = vi.fn(() => 'blob:mock-1234') as any
    postMock.mockReset(); getMock.mockReset(); deleteMock.mockReset()
  })

  afterEach(() => {
    URL.createObjectURL = originalCreateObjectURL as any
  })

  async function waitForEditor(wrapper: any) {
    const start = Date.now()
    while (Date.now() - start < 800) {
      const el = wrapper.find('.ProseMirror')
      if (el.exists()) return el
      await new Promise(r => setTimeout(r, 10))
      await nextTick()
    }
    return wrapper.find('.ProseMirror')
  }

  it('lists a non-image attachment when inserting files (size + remove)', async () => {
    postMock.mockImplementation((url: string) => {
      if (url === '/attachments/upload-init') {
        return Promise.resolve({ data: { upload_id: 'u1', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } })
      }
      if (url === '/attachments/upload-chunk') {
        return Promise.resolve({ data: { ok: true } })
      }
      if (url === '/attachments/upload-complete') {
        return Promise.resolve({ data: { path: 'tmp/doc.txt' } })
      }
      return Promise.resolve({ data: {} })
    })
    getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } })
    deleteMock.mockResolvedValue({})

    const wrapper = mount(ShiftEditor)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'doc.txt', { type: 'text/plain' })

    const ed: any = (wrapper.vm as any).editor
    ed.commands.insertFiles([file])

    await nextTick()

    expect(editorEl.findAll('img').length).toBe(0)
    const list = wrapper.find('[data-testid="attachments-list"]')
    expect(list.exists()).toBe(true)
    let items = wrapper.findAll('[data-testid="attachment-item"]')
    let item = items.find(i => i.text().includes('doc.txt'))!
    expect(item).toBeTruthy()
    const start0 = Date.now(); let ok0 = false
    while (Date.now() - start0 < 300) {
      items = wrapper.findAll('[data-testid="attachment-item"]')
      item = items.find(i => i.text().includes('doc.txt'))!
      if (item && item.text().includes('3 B')) { ok0 = true; break }
      await new Promise(r => setTimeout(r, 10)); await nextTick()
    }
    expect(ok0).toBe(true)

    await item.find('[data-testid="attachment-remove"]').trigger('click')
    await nextTick()
    expect(wrapper.findAll('[data-testid="attachment-item"]').length).toBe(0)
  })

  it('lists a non-image attachment with size on insertFiles from paste scenario', async () => {
    postMock.mockImplementation((url: string) => {
      if (url === '/attachments/upload-init') {
        return Promise.resolve({ data: { upload_id: 'u2', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } })
      }
      if (url === '/attachments/upload-chunk') {
        return Promise.resolve({ data: { ok: true } })
      }
      if (url === '/attachments/upload-complete') {
        return Promise.resolve({ data: { path: 'tmp/readme.md' } })
      }
      return Promise.resolve({ data: {} })
    })
    getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } })
    deleteMock.mockResolvedValue({})

    const wrapper = mount(ShiftEditor)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'readme.md', { type: 'text/markdown' })

    const ed: any = (wrapper.vm as any).editor
    ed.commands.insertFiles([file])

    await nextTick()

    expect(editorEl.findAll('img').length).toBe(0)
    let items = wrapper.findAll('[data-testid="attachment-item"]')
    let item = items.find(i => i.text().includes('readme.md'))!
    expect(item).toBeTruthy()
    // wait up to 300ms for status to become done
    const start = Date.now(); let ok = false
    while (Date.now() - start < 300) {
      items = wrapper.findAll('[data-testid="attachment-item"]')
      item = items.find(i => i.text().includes('readme.md'))!
      if (item && item.text().includes('3 B')) { ok = true; break }
      await new Promise(r => setTimeout(r, 10)); await nextTick()
    }
    expect(ok).toBe(true)

    await item.find('[data-testid="attachment-remove"]').trigger('click')
    await nextTick()
    expect(wrapper.findAll('[data-testid="attachment-item"]').length).toBe(0)
  })

  it('inserts image on its own line and typing starts on next line using commands', async () => {
    postMock.mockImplementation((url: string) => {
      if (url === '/attachments/upload-init') {
        return Promise.resolve({ data: { upload_id: 'u3', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } })
      }
      if (url === '/attachments/upload-chunk') {
        return new Promise(() => {})
      }
      return Promise.resolve({ data: {} })
    })
    getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } })

    // mock canvas for placeholder tile
    const proto: any = (HTMLCanvasElement as any).prototype
    const origGetContext = proto.getContext
    const origToDataURL = proto.toDataURL
    proto.getContext = vi.fn(() => ({
      fillStyle: '', strokeStyle: '', font: '', textAlign: '', lineWidth: 1, lineCap: '',
      fillRect: vi.fn(), strokeRect: vi.fn(), fillText: vi.fn(),
      beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), closePath: vi.fn(),
      stroke: vi.fn(), arc: vi.fn(), fill: vi.fn(),
    }))
    proto.toDataURL = vi.fn(() => 'data:image/png;base64,AAAA')

    const wrapper = mount(ShiftEditor)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'dropped.png', { type: 'image/png' })

    const ed: any = (wrapper.vm as any).editor
    ed.commands.setContent('<p>Text</p>')
    await nextTick()
    await new Promise(r => setTimeout(r, 0))
    ed.commands.insertFiles([file])

    await nextTick(); await new Promise(r => setTimeout(r, 0))

    const imgs = editorEl.findAll('img')
    expect(imgs.length).toBeGreaterThan(0)
    expect((imgs[0].element.getAttribute('src') || '')).toContain('data:image/png')
    expect((imgs[0].element as HTMLImageElement).classList.contains('editor-tile')).toBe(true)

    const html = editorEl.html()
    expect(html).toContain('<br')

    await new Promise(r => setTimeout(r, 0))
    ed.commands.typeText('A')
    await nextTick(); await new Promise(r => setTimeout(r, 0))
    expect(editorEl.text()).toContain('A')

    proto.getContext = origGetContext
    proto.toDataURL = origToDataURL
  })

  it('inserts image then typing behaves correctly with paste-like scenario', async () => {
    postMock.mockImplementation((url: string) => {
      if (url === '/attachments/upload-init') {
        return Promise.resolve({ data: { upload_id: 'u4', chunk_size: 5, total_chunks: 1, max_bytes: 41943040 } })
      }
      if (url === '/attachments/upload-chunk') {
        return new Promise(() => {})
      }
      return Promise.resolve({ data: {} })
    })
    getMock.mockResolvedValue({ data: { uploaded_chunks: [], total_chunks: 1, chunk_size: 5 } })

    const proto: any = (HTMLCanvasElement as any).prototype
    const origGetContext = proto.getContext
    const origToDataURL = proto.toDataURL
    proto.getContext = vi.fn(() => ({
      fillStyle: '', strokeStyle: '', font: '', textAlign: '', lineWidth: 1, lineCap: '',
      fillRect: vi.fn(), strokeRect: vi.fn(), fillText: vi.fn(),
      beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), closePath: vi.fn(),
      stroke: vi.fn(), arc: vi.fn(), fill: vi.fn(),
    }))
    proto.toDataURL = vi.fn(() => 'data:image/png;base64,AAAA')

    const wrapper = mount(ShiftEditor)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const ed: any = (wrapper.vm as any).editor
    ed.commands.setContent('<p>Abc</p>')
    await nextTick()
    await new Promise(r => setTimeout(r, 0))

    const file = new File([new Uint8Array([1,2,3])], 'pasted.png', { type: 'image/png' })
    ed.commands.insertFiles([file])

    await nextTick(); await new Promise(r => setTimeout(r, 0))

    const imgs = editorEl.findAll('img')
    expect(imgs.length).toBeGreaterThan(0)
    expect((imgs[0].element.getAttribute('src') || '')).toContain('data:image/png')

    const html = editorEl.html()
    expect(html).toContain('<br')

    await new Promise(r => setTimeout(r, 0))
    ed.commands.typeText('B')
    await nextTick(); await new Promise(r => setTimeout(r, 0))
    expect(editorEl.text()).toContain('B')

    proto.getContext = origGetContext
    proto.toDataURL = origToDataURL
  })
})
