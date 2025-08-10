<script setup lang="ts">
import { defineProps, defineEmits } from 'vue';
import { Trash2, Paperclip } from 'lucide-vue-next';

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
                    <span class="font-semibold">{{ message.sender }} - </span>
                    <span class="mt-1 opacity-75">{{ message.timestamp }}</span>
                </p>
            </div>
        </div>
        <div :class="['flex', message.isCurrentUser ? 'justify-end' : 'justify-start']">
            <div
                :class="
                    message.isCurrentUser
                        ? 'rounded-br-none bg-blue-500 text-white'
                        : 'rounded-bl-none bg-gray-200 text-gray-800'
                "
                class="inline-block max-w-3/4 min-w-[200px] rounded-lg p-3 text-left"
            >
                <div class="markdown-content" v-html="renderMarkdown(message.content)"></div>
                <!-- Display message attachments if any -->
                <div v-if="message.attachments && message.attachments.length > 0" class="mt-2">
                    <p class="text-xs font-semibold">Attachments:</p>
                    <div v-for="attachment in message.attachments" :key="attachment.id" class="mt-1">
                        <a :href="attachment.url" class="flex items-center text-xs underline" target="_blank">
                            <Paperclip :size="12" class="mr-1" />
                            {{ truncateFilename(attachment.original_filename) }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
