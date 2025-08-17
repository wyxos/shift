<script setup lang="ts">
import { MarkdownEditor } from '@/components/ui/markdown-editor';
import TaskThreadMessage from './TaskThreadMessage.vue';
import { Paperclip, Send } from 'lucide-vue-next';
import type { Ref } from 'vue';

interface Message {
    id: number;
    sender: string;
    content: string;
    timestamp: string;
    isCurrentUser: boolean;
    attachments: any[];
    created_at?: string;
}

interface ThreadFile {
    path: string;
    original_filename: string;
    url?: string;
}

interface Props {
    tabType: 'internal' | 'external';
    activeTab: 'internal' | 'external';
    messages: Message[];
    newMessage: string;
    messagesContainer: Ref<HTMLElement | null>;
    threadAttachments: ThreadFile[];
    isThreadUploading: boolean;
    threadUploadError: string;
    isDragging: boolean;
    renderMarkdown: (content: string) => string;
    isMessageDeletable: (createdAt?: string) => boolean;
    truncateFilename: (filename: string, maxLength?: number) => string;
}

interface Emits {
    (e: 'update:activeTab', value: 'internal' | 'external'): void;
    (e: 'update:newMessage', value: string): void;
    (e: 'deleteMessage', messageId: number, messageType: 'internal' | 'external'): void;
    (e: 'handleDragOver', event: DragEvent, type: 'internal' | 'external'): void;
    (e: 'handleDragLeave', event: DragEvent, type: 'internal' | 'external'): void;
    (e: 'handleDrop', event: DragEvent, type: 'internal' | 'external'): void;
    (e: 'handleThreadFileUpload', event: Event): void;
    (e: 'removeThreadAttachment', file: ThreadFile): void;
    (e: 'sendMessage', event?: Event): void;
}

defineProps<Props>();
defineEmits<Emits>();
</script>

<template>
    <div
        :class="['rounded-md border p-4', activeTab === tabType ? 'border-blue-500 bg-blue-50' : '']"
        class="flex h-full flex-col overflow-hidden"
        @click="$emit('update:activeTab', tabType)"
    >
        <h4>{{ tabType === 'internal' ? 'Internal' : 'External' }}</h4>
        <!-- Messages container with fixed height and scrolling -->
        <div :ref="messagesContainer" class="mb-4 flex-1 overflow-y-auto rounded bg-gray-50 p-2">
            <TaskThreadMessage
                v-for="message in messages"
                :key="message.id"
                :message="message"
                :message-type="tabType"
                :is-message-deletable="isMessageDeletable"
                :render-markdown="renderMarkdown"
                :truncate-filename="truncateFilename"
                @delete-message="$emit('deleteMessage', $event, tabType)"
            />
        </div>

        <!-- Thread attachments display -->
        <div v-if="threadAttachments.length > 0" class="mb-3">
            <h4 class="text-sm font-medium text-gray-700">Attachments:</h4>
            <ul class="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                <li
                    v-for="file in threadAttachments"
                    :key="file.path"
                    class="flex items-center justify-between px-3 py-2 text-sm"
                >
                    <div class="flex items-center">
                        <Paperclip :size="20" class="mr-2 text-gray-400" />
                        <span>{{ truncateFilename(file.original_filename) }}</span>
                    </div>
                    <button class="text-red-600 hover:text-red-900" type="button" @click="$emit('removeThreadAttachment', file)">
                        Remove
                    </button>
                </li>
            </ul>
        </div>

        <!-- Thread upload error message -->
        <div v-if="threadUploadError" class="mb-2 text-sm text-red-500">{{ threadUploadError }}</div>

        <!-- Thread loading indicator -->
        <div v-if="isThreadUploading" class="mb-2 text-sm text-blue-500">Uploading attachment...</div>

        <!-- Message input with attachment button -->
        <div class="flex flex-col">
            <div class="mb-2">
                <MarkdownEditor
                    :model-value="newMessage"
                    :auto-grow="true"
                    :class="['flex-grow', isDragging ? 'drag-over' : '']"
                    height="200px"
                    max-height="600px"
                    placeholder="Type your message or drop files here..."
                    @update:model-value="$emit('update:newMessage', $event)"
                    @dragleave="$emit('handleDragLeave', $event, tabType)"
                    @dragover="$emit('handleDragOver', $event, tabType)"
                    @drop="$emit('handleDrop', $event, tabType)"
                    @enter="$emit('sendMessage')"
                />
                <div class="mt-2 flex justify-end gap-2">
                    <label
                        class="flex cursor-pointer items-center bg-gray-200 px-3 py-2 text-gray-700 hover:bg-gray-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                    >
                        <Paperclip :size="20" />
                        <input class="hidden" multiple type="file" @change="$emit('handleThreadFileUpload', $event)" />
                    </label>
                    <button
                        class="rounded-r-md bg-blue-500 px-4 py-2 text-white hover:bg-blue-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        type="button"
                        @click.prevent="$emit('sendMessage', $event)"
                    >
                        Send
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
