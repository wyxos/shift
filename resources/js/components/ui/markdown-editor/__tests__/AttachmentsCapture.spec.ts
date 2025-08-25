import { describe, it, expect, vi } from 'vitest'
import { Editor } from '@tiptap/core'
import Document from '@tiptap/extension-document'
import Paragraph from '@tiptap/extension-paragraph'
import Text from '@tiptap/extension-text'
import { AttachmentsCapture } from '@/components/ui/markdown-editor/extensions/AttachmentsCapture'

function fakeClipboardEvent(files: File[]): ClipboardEvent {
  const data = new DataTransfer()
  files.forEach(f => data.items.add(f))
  // @ts-expect-error constructing minimal event for test
  const evt: ClipboardEvent = { clipboardData: data, preventDefault: vi.fn() }
  return evt
}

function fakeDragEvent(files: File[]): DragEvent {
  const data = new DataTransfer()
  files.forEach(f => data.items.add(f))
  // @ts-expect-error constructing minimal event for test
  const evt: DragEvent = { dataTransfer: data, preventDefault: vi.fn() }
  return evt
}

describe('AttachmentsCapture', () => {
  it('calls onFiles on paste/drop and prevents default insertion', () => {
    const onFiles = vi.fn()
    const editor = new Editor({
      extensions: [Document, Paragraph, Text, AttachmentsCapture.configure({ onFiles })],
      content: '<p></p>',
    })

    const file = new File(['hi'], 'hi.txt', { type: 'text/plain' })
    const paste = fakeClipboardEvent([file])
    const drop = fakeDragEvent([file])

    // @ts-expect-error access view
    const view = editor.view

    // ProseMirror calls handlePaste/handleDrop internally; simulate via props
    const plugins = view.props.plugins || []
    const plugin = plugins.find(p => p.props.handlePaste || p.props.handleDrop)
    expect(plugin).toBeTruthy()

    // @ts-expect-error invoke
    const handledPaste = plugin.props.handlePaste(view, paste)
    // @ts-expect-error invoke
    const handledDrop = plugin.props.handleDrop(view, drop)

    expect(handledPaste).toBe(true)
    expect(handledDrop).toBe(true)
    expect(onFiles).toHaveBeenCalledTimes(2)
    expect(onFiles.mock.calls[0][0][0].name).toBe('hi.txt')
  })
})

