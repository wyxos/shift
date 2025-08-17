<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

import { Editor, rootCtx, defaultValueCtx } from '@milkdown/kit/core';
import { commonmark } from '@milkdown/kit/preset/commonmark';
import { nord } from '@milkdown/theme-nord';
// This is the must have css for prosemirror
import '@milkdown/kit/prose/view/style/prosemirror.css';
import { onMounted, ref } from 'vue';

const editorField = ref<HTMLDivElement | null>(null);

const markdown = `# Milkdown Vanilla Commonmark`;

onMounted(() => {
    if (editorField.value) {
        Editor.make()
            .config((ctx) => {
                ctx.set(rootCtx, editorField.value);
                ctx.set(defaultValueCtx, markdown);
            })
            .config(nord)
            .use(commonmark)
            .create()
            .then((editor) => {
                // You can use the editor instance here if needed
                console.log('Editor initialized:', editor);
            })
            .catch((error) => {
                console.error('Error initializing editor:', error);
            });
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4">
            <div ref="editorField"></div>
        </div>
    </AppLayout>
</template>
