import { render, waitFor } from '@testing-library/vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import MilkdownEditor from '@/components/ui/markdown-editor/MilkdownEditor.vue'

// Mock route() helper from Ziggy
// @ts-expect-error - inject global route mock
globalThis.route = (name: string) => {
  if (name === 'attachments.upload') return '/attachments/upload'
  return '/'
}

// Mock @milkdown and editor internals to avoid heavy DOM/editor setup
vi.mock('@milkdown/kit/core', () => ({ Editor: { make: () => ({ config: () => ({ config: () => ({ use: () => ({}) }) }) }) } }))
vi.mock('@milkdown/core', () => ({ editorViewCtx: {} }))
vi.mock('@milkdown/kit/preset/commonmark', () => ({ commonmark: {} }))
vi.mock('@milkdown/theme-nord', () => ({ nord: {} }))
vi.mock('@milkdown/vue', () => ({
  Milkdown: {
    name: 'MilkdownStub',
    template: '<div class="ProseMirror" />',
  },
  useEditor: () => ({ get: () => null }),
}))

// Mock axios
import axios from 'axios'
vi.mock('axios')
const mockedAxios = axios as unknown as { post: ReturnType<typeof vi.fn> }

function createDeferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => { resolve = res })
  return { promise, resolve }
}

describe('MilkdownEditor.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('uploads image on paste and toggles spinner', async () => {
    const { getByText, queryByText, container } = render(MilkdownEditor)

    const deferred = createDeferred({ data: { original_filename: 'test.png', url: '/attachments/temp/abc/test.png' } })
    mockedAxios.post = vi.fn().mockReturnValue(deferred.promise)

    const editorRoot = container.querySelector('.milkdown-editor') as HTMLElement
    const proseMirror = editorRoot.querySelector('.ProseMirror') as HTMLElement

    const file = new File(['data'], 'test.png', { type: 'image/png' })
    const items = [{ kind: 'file', getAsFile: () => file }]
    const pasteEvent = new Event('paste', { bubbles: true, cancelable: true }) as any
    pasteEvent.clipboardData = { items }

    proseMirror.dispatchEvent(pasteEvent)

    // Spinner visible while upload pending
    await waitFor(() => expect(getByText('Uploading image...')).toBeTruthy())

    // Resolve upload
    deferred.resolve({ data: { original_filename: 'test.png', url: '/attachments/temp/abc/test.png' } })

    // Spinner hidden after upload completes
    await waitFor(() => expect(queryByText('Uploading image...')).toBeNull())

    // axios called with FormData and correct headers
    expect(mockedAxios.post).toHaveBeenCalledTimes(1)
    const [url, formData, options] = mockedAxios.post.mock.calls[0]
    expect(url).toBe('/attachments/upload')
    expect(formData instanceof FormData).toBe(true)
    expect(options?.headers?.['Content-Type']).toBe('multipart/form-data')
  })

  it('handles drop of image files and toggles spinner', async () => {
    const { getByText, queryByText, container } = render(MilkdownEditor)

    const deferred = createDeferred({ data: { original_filename: 'drop.png', url: '/attachments/temp/abc/drop.png' } })
    mockedAxios.post = vi.fn().mockReturnValue(deferred.promise)

    const editorRoot = container.querySelector('.milkdown-editor') as HTMLElement
    const proseMirror = editorRoot.querySelector('.ProseMirror') as HTMLElement

    const file = new File(['data'], 'drop.png', { type: 'image/png' })
    const dropEvent = new Event('drop', { bubbles: true, cancelable: true }) as any
    dropEvent.dataTransfer = { files: [file] }

    proseMirror.dispatchEvent(dropEvent)

    // Spinner visible while upload pending
    await waitFor(() => expect(getByText('Uploading image...')).toBeTruthy())

    // Resolve upload
    deferred.resolve({ data: { original_filename: 'drop.png', url: '/attachments/temp/abc/drop.png' } })

    // Spinner hidden after upload completes
    await waitFor(() => expect(queryByText('Uploading image...')).toBeNull())

    expect(mockedAxios.post).toHaveBeenCalledTimes(1)
  })

  it('opens and closes image modal when clicking an image inside editor', async () => {
    const { queryByAltText, container } = render(MilkdownEditor)

    const editorRoot = container.querySelector('.milkdown-editor') as HTMLElement

    // Insert a fake image inside the editor container and click it
    const img = document.createElement('img')
    img.src = '/attachments/temp/abc/test.png'
    editorRoot.appendChild(img)

    img.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))

    // Modal image should be visible
    await waitFor(() => expect(queryByAltText('full-size')).toBeTruthy())

    // Click overlay to close
    const overlay = container.querySelector('div.fixed.inset-0') as HTMLElement
    overlay.click()

    // Modal should be closed
    await waitFor(() => expect(queryByAltText('full-size')).toBeNull())
  })
})

