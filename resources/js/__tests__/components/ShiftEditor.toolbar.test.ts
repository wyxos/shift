import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import ShiftEditor from '@/components/ShiftEditor.vue'

// Basic route helper
beforeEach(() => {
  ;(global as any).route = (name: string) => {
    if (name === 'attachments.upload') return '/attachments/upload'
    if (name === 'attachments.remove-temp') return '/attachments/remove-temp'
    return '/'
  }
})

// Mock axios
const postMock = vi.fn()
const deleteMock = vi.fn()
vi.mock('axios', () => ({
  default: { post: (...a: any[]) => postMock(...a), delete: (...a: any[]) => deleteMock(...a) },
}))

// Ensure emoji-picker web component is available
import 'emoji-picker-element'

describe('ShiftEditor toolbar', () => {
  it('inserts emoji into the editor via emoji picker', async () => {
    const wrapper = mount(ShiftEditor)
    await nextTick()
    // open emoji
    await wrapper.get('[data-testid="toolbar-emoji"]').trigger('click')
    await nextTick()
    const picker = wrapper.get('[data-testid="emoji-picker"]').element
    // dispatch custom event
    picker.dispatchEvent(new CustomEvent('emoji-click', { detail: { unicode: '😀' } }))
    await nextTick()
    // Expect emoji in editor
    const text = wrapper.find('.ProseMirror').text()
    expect(text).toContain('😀')
  })

  it('adds files via attachment icon to attachments list (image and non-image)', async () => {
    postMock.mockResolvedValue({ data: { path: 'temp_attachments/ATT/test.bin' } })
    const wrapper = mount(ShiftEditor)
    await nextTick()

    const fileInput = wrapper.get('[data-testid="file-input"]').element as HTMLInputElement
    // add an image and a non-image
    const img = new File([new Uint8Array([1])], 'photo.png', { type: 'image/png' })
    const doc = new File([new Uint8Array([2,3])], 'doc.txt', { type: 'text/plain' })

    Object.defineProperty(fileInput, 'files', { value: [img, doc] })
    await wrapper.get('[data-testid="toolbar-attachment"]').trigger('click')
    // directly fire change event since we mocked files
    fileInput.dispatchEvent(new Event('change'))
    await nextTick()

    const items = wrapper.findAll('[data-testid="attachment-item"]')
    // two items present (ordering not guaranteed)
    expect(items.length).toBe(2)
    const names = items.map(i => i.text())
    expect(names.some(t => t.includes('photo.png'))).toBe(true)
    expect(names.some(t => t.includes('doc.txt'))).toBe(true)
  })

  it('emits send with HTML and resets editor content', async () => {
    const wrapper = mount(ShiftEditor)
    await nextTick()
    const ed: any = (wrapper.vm as any).editor
    ed?.commands.setContent('<p>Hello world</p>')
    await nextTick()

    await wrapper.get('[data-testid="toolbar-send"]').trigger('click')
    await nextTick()

    const emitted = wrapper.emitted('send')
    expect(emitted && emitted[0] && emitted[0][0].html).toContain('Hello world')

    // content is cleared
    const text = wrapper.find('.ProseMirror').text().trim()
    expect(text).toBe('')
  })
})

