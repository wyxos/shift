<script setup lang="ts">
import ShiftEditor from '@/components/ShiftEditor.vue';
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
    threadTempIdentifier?: string;
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

const props = defineProps<Props>();
defineEmits<Emits>();

// Assign messages container element to the passed-in ref
import type { Ref as VueRef } from 'vue'
const setMessagesContainer = (el: Element | null) => {
  const target: any = (props as any).messagesContainer
  if (target && typeof target === 'object' && 'value' in target) {
    target.value = (el as HTMLElement | null)
  }
}
</script>

<template>
    <div
        :class="['rounded-md border p-4', activeTab === tabType ? 'border-blue-500 bg-blue-50' : '']"
        class="flex h-full flex-col overflow-hidden"
        @click="$emit('update:activeTab', tabType)"
    >
        <h4>{{ tabType === 'internal' ? 'Internal' : 'External' }}</h4>
        <!-- Messages container with fixed height and scrolling -->
<div :ref="setMessagesContainer" class="mb-4 flex-1 overflow-y-auto rounded bg-gray-50 p-2">
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

        <!-- Message input (ShiftEditor for both tabs) -->
        <div class="flex flex-col">
            <div class="">
<ShiftEditor :model-value="newMessage" @update:model-value="$emit('update:newMessage', $event)" :temp-identifier="threadTempIdentifier" @send="$emit('sendMessage')" />
            </div>
        </div>
    </div>
</template>
