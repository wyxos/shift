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

describe('Components.vue TipTap image drop/paste (step 1: insert local image)', () => {
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

  it('inserts image on drop and typing starts on next line', async () => {
    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'dropped.png', { type: 'image/png' })

    // Call editor drop handler directly
    const ed: any = (wrapper.vm as any).editor
    ed.options.editorProps.handleDrop(ed.view, { dataTransfer: { files: [file] }, preventDefault: () => {} } as any)

    await nextTick()

    const imgs = editorEl.findAll('img')
    expect(imgs.length).toBeGreaterThan(0)
    expect(imgs[0].element.getAttribute('src') || '').toContain('blob:')
    expect((imgs[0].element as HTMLImageElement).classList.contains('editor-tile')).toBe(true)

    // Simulate typing "A" immediately after the image
    ed.options.editorProps.handleTextInput(ed.view, ed.state.selection.from, ed.state.selection.to, 'A')
    await nextTick()

    // Expect a <br> (hard break) before the typed character
    const html = editorEl.html()
    expect(html).toContain('<br')
    expect(editorEl.text()).toContain('A')
  })

  it('inserts image on paste and typing starts on next line', async () => {
    const wrapper = mount(Components)
    await nextTick()

    const editorEl = await waitForEditor(wrapper)
    expect(editorEl.exists()).toBe(true)

    const file = new File([new Uint8Array([1,2,3])], 'pasted.png', { type: 'image/png' })

    const ed: any = (wrapper.vm as any).editor
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

    // Simulate typing immediately after the image
    ed.options.editorProps.handleTextInput(ed.view, ed.state.selection.from, ed.state.selection.to, 'B')
    await nextTick()

    const html = editorEl.html()
    expect(html).toContain('<br')
    expect(editorEl.text()).toContain('B')
  })
})

