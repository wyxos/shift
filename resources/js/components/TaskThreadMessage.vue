<script setup lang="ts">
import { defineProps, defineEmits, onMounted, ref, watch, nextTick, computed } from 'vue';
import { Trash2, Paperclip } from 'lucide-vue-next';
import hljs from 'highlight.js/lib/core'
import jsLang from 'highlight.js/lib/languages/javascript'
import tsLang from 'highlight.js/lib/languages/typescript'
import jsonLang from 'highlight.js/lib/languages/json'
import cssLang from 'highlight.js/lib/languages/css'
import phpLang from 'highlight.js/lib/languages/php'
import xmlLang from 'highlight.js/lib/languages/xml'
import pythonLang from 'highlight.js/lib/languages/python'
import 'highlight.js/styles/github.css'

hljs.registerLanguage('javascript', jsLang)
hljs.registerLanguage('js', jsLang)
hljs.registerLanguage('typescript', tsLang)
hljs.registerLanguage('ts', tsLang)
hljs.registerLanguage('json', jsonLang)
hljs.registerLanguage('css', cssLang)
hljs.registerLanguage('php', phpLang)
hljs.registerLanguage('xml', xmlLang)
hljs.registerLanguage('html', xmlLang)
hljs.registerLanguage('python', pythonLang)
hljs.registerLanguage('py', pythonLang)

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

const props = defineProps<Props>();
const emit = defineEmits<Emits>();

const contentRef = ref<HTMLElement | null>(null)

// Filter out attachments already embedded in content (e.g., images inserted in the editor)
const filteredAttachments = computed(() => {
  const html = props.message?.content || ''
  const list = Array.isArray(props.message?.attachments) ? props.message.attachments : []
  return list.filter((att: any) => {
    const id = String(att?.id ?? '')
    if (!id) return true
    // If the content contains the internal download route path with this id, treat as embedded
    const marker = `/attachments/${id}/download`
    return html.indexOf(marker) === -1
  })
})

function highlight() {
  nextTick(() => {
    const root = contentRef.value
    if (!root) return
    root.querySelectorAll('pre code').forEach((el) => {
      try { hljs.highlightElement(el as HTMLElement) } catch {}
    })
  })
}

onMounted(() => highlight())
watch(() => props.message.content, () => highlight())
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
                <div ref="contentRef" class="markdown-content" v-html="renderMarkdown(message.content)"></div>
                <!-- Display message attachments if any (excluding embedded ones) -->
                <div v-if="filteredAttachments.length > 0" class="mt-2">
                    <p class="text-xs font-semibold">Attachments:</p>
                    <div v-for="attachment in filteredAttachments" :key="attachment.id" class="mt-1">
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
