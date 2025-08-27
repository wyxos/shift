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

describe('Components.vue TipTap image drop/paste (insert local image and respect line breaks)', () => {
  const originalCreateObjectURL = URL.createObjectURL

  beforeEach(() => {
    // Mock blob URL for local preview
    URL.createObjectURL = vi.fn(() => 'blob:mock-1234') as any
  })

  afterEach(() => {
    URL.createObjectURL = originalCreateObjectURL as any
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

  it('lists a non-image attachment on drop under the editor with size and remove CTA', async () => {
    // mock canvas for placeholder tile
    const proto: any = (HTMLCanvasElement as any).prototype
    const origGetContext = proto.getContext
    const origToDataURL = proto.toDataURL
    proto.getContext = vi.fn(() => ({
      fillStyle: '', strokeStyle: '', font: '', textAlign: '',
      fillRect: vi.fn(), strokeRect: vi.fn(), fillText: vi.fn(),
    }))
    proto.toDataURL = vi.fn(() => 'data:image/png;base64,AAAA')

    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
origGetContext = proto.getContext
    const origToDataURL = proto.toDataURL
    proto.getContext = vi.fn(() => ({
      fillStyle: '', strokeStyle: '', font: '', textAlign: '',
      fillRect: vi.fn(), strokeRect: vi.fn(), fillText: vi.fn(),
    }))
    proto.toDataURL = vi.fn(() => 'data:image/png;base64,AAAA')

    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'doc.txt', { type: 'text/plain' })

    const ed: any = (wrapper.vm as any).editor
    ed.options.editorProps.handleDrop(ed.view, { dataTransfer: { files: [file] }, preventDefault: () => {} } as any)

    await nextTick()

    // Should not insert an img; instead, list the attachment
    expect(editorEl.findAll('img').length).toBe(0)
    const list = wrapper.find('[data-testid="attachments-list"]')
    expect(list.exists()).toBe(true)
    const items = wrapper.findAll('[data-testid="attachment-item"]')
    const item = items.find(i => i.text().includes('doc.txt'))!
    expect(item).toBeTruthy()
    expect(item.text()).toContain('3 B')
    // remove
    await item.find('[data-testid="attachment-remove"]').trigger('click')
    await nextTick()
    expect(wrapper.findAll('[data-testid="attachment-item"]').length).toBe(0)
  })

  it('lists a non-image attachment on paste under the editor with size and remove CTA', async () => {
    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'readme.md', { type: 'text/markdown' })

    const ed: any = (wrapper.vm as any).editor
    ed.options.editorProps.handlePaste(ed.view, {
      clipboardData: {
        files: [file],
        items: [{ kind: 'file', type: 'text/markdown', getAsFile: () => file }],
      },
      preventDefault: () => {},
    } as any)

    await nextTick()

    expect(editorEl.findAll('img').length).toBe(0)
    const items = wrapper.findAll('[data-testid="attachment-item"]')
    const item = items.find(i => i.text().includes('readme.md'))!
    expect(item).toBeTruthy()
    expect(item.text()).toContain('3 B')
    // remove
    await item.find('[data-testid="attachment-remove"]').trigger('click')
    await nextTick()
    expect(wrapper.findAll('[data-testid="attachment-item"]').length).toBe(0)
  })

  it('inserts image on drop next to text on its own line and typing starts on next line', async () => {
    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'dropped.png', { type: 'image/png' })

    // Put some text first at the current position
    const ed: any = (wrapper.vm as any).editor
    ed.commands.setContent('<p>Text</p>')

    // Call editor drop handler directly
    ed.options.editorProps.handleDrop(ed.view, { dataTransfer: { files: [file] }, preventDefault: () => {} } as any)

    await nextTick()

    const imgs = editorEl.findAll('img')
    expect(imgs.length).toBeGreaterThan(0)
    expect((imgs[0].element.getAttribute('src') || '')).toContain('data:image/png')
    expect((imgs[0].element.getAttribute('src') || '')).toContain('data:image/png')
    expect((imgs[0].element as HTMLImageElement).classList.contains('editor-tile')).toBe(true)
    expect((imgs[0].element as HTMLImageElement).classList.contains('editor-tile')).toBe(true)

    // The editor HTML should contain a hard break separating text and image
    const html = editorEl.html()
    expect(html).toContain('<br')

    // Simulate typing "A" immediately after the image
    ed.options.editorProps.handleTextInput(ed.view, ed.state.selection.from, ed.state.selection.to, 'A')
    await nextTick()

    // Expect typed character present
    expect(editorEl.text()).toContain('A')

    // restore canvas mocks
    proto.getContext = origGetContext
    proto.toDataURL = origToDataURL
  })

  it('inserts image on paste next to text on its own line and typing starts on next line', async () => {
    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'pasted.png', { type: 'image/png' })

    const ed: any = (wrapper.vm as any).editor
    ed.commands.setContent('<p>Abc</p>')
    ed.options.editorProps.handlePaste(ed.view, {
      clipboardData: {
        files: [file],
        items: [{ kind: 'file', type: 'image/png', getAsFile: () => file }],
      },
      preventDefault: () => {},
    } as any)

    await nextTick()

    const imgs = editorEl.findAll('img')
    expect(imgs.length).toBeGreaterThan(0)
    expect(imgs[0].element.getAttribute('src') || '').toContain('blob:')

    // There should be a line break separating existing text and the image
    const html = editorEl.html()
    expect(html).toContain('<br')

    // Simulate typing immediately after the image
    ed.options.editorProps.handleTextInput(ed.view, ed.state.selection.from, ed.state.selection.to, 'B')
    await nextTick()

    expect(editorEl.text()).toContain('B')

    // restore canvas mocks
    proto.getContext = origGetContext
    proto.toDataURL = origToDataURL
  })
})

