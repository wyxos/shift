<script setup lang="ts">
import { ImageLightbox } from '@/components/ui/image-lightbox';
import TaskCommentContent from '@/shared/components/tasks/TaskCommentContent.vue';
import TaskMessageAttachments from '@/shared/components/tasks/TaskMessageAttachments.vue';
import { Trash2 } from 'lucide-vue-next';
import { reactive } from 'vue';

interface Message {
    id: number;
    sender: string;
    content: string;
    timestamp: string;
    isCurrentUser: boolean;
    attachments: any[];
    created_at?: string;
}

interface Props {
    message: Message;
    messageType: 'internal' | 'external';
    isMessageDeletable: (createdAt?: string) => boolean;
    renderMarkdown: (content: string) => string;
    truncateFilename: (filename: string, maxLength?: number) => string;
}

interface Emits {
    (e: 'deleteMessage', messageId: number, messageType: 'internal' | 'external'): void;
}

defineProps<Props>();
defineEmits<Emits>();
const preview = reactive({ open: false, src: '', alt: '' });
function openImage(event: MouseEvent): void {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const image = target.closest('img') ?? target.closest('[data-message-image]')?.querySelector('img');
    if (!(image instanceof HTMLImageElement)) return;
    event.preventDefault();
    preview.src = image.currentSrc || image.src;
    preview.alt = image.alt || 'Image';
    preview.open = true;
}
</script>

<template>
    <div class="mb-3">
        <div :class="['flex items-center', message.isCurrentUser ? 'justify-end' : 'justify-between']">
            <div v-if="!message.isCurrentUser" class="flex items-center">
                <p class="text-sm">
                    <span class="font-semibold">{{ message.sender }} - </span>
                    <span class="mt-1 opacity-75">{{ message.timestamp }}</span>
                </p>
            </div>
            <div v-else class="flex items-center gap-2">
                <button
                    v-if="isMessageDeletable(message.created_at)"
                    class="text-xs text-red-500 hover:text-red-700"
                    title="Delete message"
                    @click="$emit('deleteMessage', message.id, messageType)"
                >
                    <Trash2 :size="16" />
                </button>
                <p class="text-sm">
                    <span class="mt-1 opacity-75">{{ message.timestamp }}</span>
                </p>
            </div>
        </div>
        <div :class="['flex', message.isCurrentUser ? 'justify-end' : 'justify-start']">
            <div class="flex max-w-3/4 min-w-[200px] flex-col gap-2">
                <div
                    :class="message.isCurrentUser ? 'rounded-br-none bg-blue-200' : 'rounded-bl-none bg-gray-200 text-gray-800'"
                    class="tiptap ProseMirror !h-auto !max-h-none rounded-lg p-3 text-left"
                >
                    <TaskCommentContent :content="message.content" @click="openImage" />
                </div>
                <TaskMessageAttachments :attachments="message.attachments || []" :own="message.isCurrentUser" @image-click="openImage" />
            </div>
        </div>
        <ImageLightbox v-model:open="preview.open" :src="preview.src" :alt="preview.alt" />
    </div>
</template>
