<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Components', href: '/components' },
]

function insertLocalImageFromFile(editor: any, file: File) {
  const url = URL.createObjectURL(file)
  editor.chain().focus().insertContent({ type: 'image', attrs: { src: url, alt: file.name, title: '' } }).run()
}

function handleFiles(editor: any, files: FileList | File[]) {
  const arr = Array.from(files || [])
  arr.filter(f => f.type && f.type.startsWith('image/')).forEach(f => insertLocalImageFromFile(editor, f))
}

const editor = useEditor({
  extensions: [
    StarterKit,
    Image.configure({ inline: true, allowBase64: true }),
  ],
  content: '<p>Hello TipTap</p>',
  editorProps: {
    handleDrop: (view, event) => {
      const dt = (event as DragEvent).dataTransfer
      if (dt?.files?.length) {
        event.preventDefault()
        handleFiles(editor.value, dt.files)
        return true
      }
      return false
    },
    handlePaste: (view, event) => {
      const e = event as ClipboardEvent
      const files: File[] = []
      const cdFiles = e.clipboardData?.files
      if (cdFiles && cdFiles.length) {
        files.push(...Array.from(cdFiles))
      } else {
        const items = e.clipboardData?.items || []
        for (const item of Array.from(items)) {
          if (item.kind === 'file' && item.type.startsWith('image/')) {
            const f = item.getAsFile()
            if (f) files.push(f)
          }
        }
      }
      if (files.length) {
        e.preventDefault()
        handleFiles(editor.value, files)
        return true
      }
      return false
    },
  },
})

defineExpose({ editor })
</script>

<template>
  <Head title="Components" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="p-4">
      <div class="rounded border p-3">
        <EditorContent data-testid="tiptap-editor" :editor="editor" />
      </div>
    </div>
  </AppLayout>
</template>
