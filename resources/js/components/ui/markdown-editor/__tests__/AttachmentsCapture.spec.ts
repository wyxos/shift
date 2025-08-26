import { describe, it, expect, vi } from 'vitest'
import { Editor } from '@tiptap/core'
import Document from '@tiptap/extension-document'
import Paragraph from '@tiptap/extension-paragraph'
import Text from '@tiptap/extension-text'
import { AttachmentsCapture } from '@/components/ui/markdown-editor/extensions/AttachmentsCapture'

function fakeClipboardEvent(files: File[]): ClipboardEvent {
  // Minimal clipboardData stub with files list for jsdom
  // @ts-expect-error constructing minimal event for test
  const evt: ClipboardEvent = { clipboardData: { files }, preventDefault: vi.fn() }
  return evt
}

function fakeDragEvent(files: File[]): DragEvent {
  // Minimal dataTransfer stub with files list for jsdom
  // @ts-expect-error constructing minimal event for test
  const evt: DragEvent = { dataTransfer: { files }, preventDefault: vi.fn() }
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

    // ProseMirror calls handlePaste/handleDrop via plugins; locate our plugin
    const plugins = (view?.state?.plugins || view?.props?.plugins || []) as any[]
    const plugin = plugins.find((p: any) => typeof p?.props?.handlePaste === 'function' && typeof p?.props?.handleDrop === 'function') as any

    expect(plugin).toBeTruthy()
    const handledPaste = plugin.props.handlePaste(view, paste)
    const handledDrop = plugin.props.handleDrop(view, drop)
    expect(handledPaste).toBe(true)
    expect(handledDrop).toBe(true)

    expect(onFiles).toHaveBeenCalled()
    expect(onFiles.mock.calls[0][0][0].name).toBe('hi.txt')
  })
})

