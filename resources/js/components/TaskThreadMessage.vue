<script setup lang="ts">
import { defineProps, defineEmits } from 'vue';

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
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                        ></path>
                    </svg>
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
                            <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                <path
                                    clip-rule="evenodd"
                                    d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                    fill-rule="evenodd"
                                />
                            </svg>
                            {{ truncateFilename(attachment.original_filename) }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
