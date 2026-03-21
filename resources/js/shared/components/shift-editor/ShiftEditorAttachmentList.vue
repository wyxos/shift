<script setup lang="ts">
import { FileImage, FileText, X } from 'lucide-vue-next';
import type { AttachmentItem } from './types';

defineProps<{
    attachments: AttachmentItem[];
    formatBytes: (bytes: number) => string;
}>();

const emit = defineEmits<{
    (e: 'remove', attachment: AttachmentItem): void;
}>();

function iconForAttachment(type: string) {
    if (type?.startsWith('image/')) return FileImage;
    return FileText;
}
</script>

<template>
    <ul
        v-if="attachments.length"
        data-testid="attachments-list"
        class="divide-y divide-slate-200 rounded-md border border-slate-200 bg-slate-50/60"
    >
        <li
            v-for="att in attachments"
            :key="att.id"
            data-testid="attachment-item"
            :data-temp-path="att.path"
            class="flex items-center justify-between gap-3 px-3 py-2"
        >
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                    <component :is="iconForAttachment(att.type)" :size="18" class="text-slate-600" />
                </div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-slate-900" :title="att.name">{{ att.name }}</div>
                    <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                        <template v-if="att.status === 'uploading'">
                            <span>Uploading</span>
                            <span class="rounded-md bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">
                                {{ Math.max(1, att.progress) }}%
                            </span>
                        </template>
                        <template v-else-if="att.status === 'error'">
                            <span class="font-semibold text-red-600">Upload failed</span>
                        </template>
                        <template v-else>
                            <span>{{ formatBytes(att.size) }}</span>
                        </template>
                    </div>
                    <div v-if="att.status === 'uploading'" class="mt-2 h-1.5 overflow-hidden rounded-md bg-slate-200">
                        <div
                            class="h-1.5 bg-gradient-to-r from-sky-500 via-blue-500 to-indigo-500 transition-all"
                            :style="{ width: Math.max(1, att.progress) + '%' }"
                        ></div>
                    </div>
                </div>
            </div>
            <button
                type="button"
                class="inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-md bg-red-50 text-red-600 shadow-sm ring-1 ring-red-200 transition hover:bg-red-100 hover:text-red-700 hover:ring-red-300"
                data-testid="attachment-remove"
                aria-label="Remove attachment"
                title="Remove"
                @click="emit('remove', att)"
            >
                <X :size="14" />
            </button>
        </li>
    </ul>
</template>
