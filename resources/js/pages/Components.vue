<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Components', href: '/components' },
]

const editor = useEditor({
  extensions: [StarterKit],
  content: '<p>Hello TipTap</p>',
})
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
