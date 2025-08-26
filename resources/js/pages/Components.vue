<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import MilkdownEditorWrapper from '@/components/ui/markdown-editor/MilkdownEditorWrapper.vue';
import TiptapChatEditor from '@/components/ui/markdown-editor/TiptapChatEditor.vue';
import { Button } from '@/components/ui/button';
import Icon from '@/components/Icon.vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">

        <!-- TipTap Editor with similar footer -->
        <div class="p-4">
            <div class="rounded-md border-2 border-blue-500 bg-background">
                <div class="p-2">
                    <TiptapChatEditor ref="tiptapRef" />
                </div>
                <div class="flex items-center justify-between gap-2 border-t px-2 py-1.5">
                    <div class="flex items-center gap-1.5">
                        <Button variant="ghost" size="sm" aria-label="Emoji">
                            <Icon name="smile" :size="18" />
                        </Button>
                        <Button variant="ghost" size="sm" aria-label="Attach file" @click="$refs.tiptapRef?.openFileDialog?.()">
                            <Icon name="paperclip" :size="18" />
                        </Button>
                        <Button variant="ghost" size="sm" aria-label="More">
                            <Icon name="plus" :size="18" />
                        </Button>
                    </div>
                    <div class="flex items-center">
                        <Button size="sm" aria-label="Send">
                            <Icon name="send" :size="18" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
