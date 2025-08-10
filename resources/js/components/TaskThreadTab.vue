<script setup lang="ts">
import { MarkdownEditor } from '@/components/ui/markdown-editor';
import TaskThreadMessage from './TaskThreadMessage.vue';
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
                        <svg class="mr-2 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                clip-rule="evenodd"
                                d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                fill-rule="evenodd"
                            />
                        </svg>
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
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                            />
                        </svg>
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
