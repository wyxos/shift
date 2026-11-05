<script setup lang="ts">
import { Paperclip } from 'lucide-vue-next';
import { ref } from 'vue';

type Attachment = {
    id?: number | string;
    url?: string;
    original_filename?: string;
    mime_type?: string;
};
withDefaults(defineProps<{ attachments: Attachment[]; disabled?: boolean; own?: boolean }>(), { disabled: false, own: false });
const emit = defineEmits<{ imageClick: [event: MouseEvent] }>();
const landscape = ref<Record<string, boolean>>({});
const failed = ref<Record<string, boolean>>({});
function isImage(attachment: Attachment): boolean {
    if (attachment.mime_type) return /^image\/(png|jpe?g|gif|webp|avif|bmp)$/i.test(attachment.mime_type);
    return /\.(png|jpe?g|gif|webp|avif|bmp)$/i.test(attachment.original_filename ?? '');
}
function onLoad(event: Event, url: string): void {
    const image = event.target as HTMLImageElement;
    landscape.value[url] = image.naturalWidth > image.naturalHeight;
}
</script>

<template>
    <div v-if="attachments.length" class="mt-2 flex max-w-full flex-wrap items-start gap-2" data-testid="message-attachments">
        <template v-for="(attachment, index) in attachments" :key="attachment.id ?? index">
            <button
                v-if="isImage(attachment) && attachment.url && !disabled && !failed[attachment.url]"
                type="button"
                data-message-image
                class="border-border bg-muted/30 hover:bg-muted/50 block max-w-full overflow-hidden rounded border text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2"
                :class="landscape[attachment.url] ? 'w-[400px]' : 'w-[200px]'"
                :aria-label="`Open image ${attachment.original_filename || 'attachment'}`"
                @click="emit('imageClick', $event)"
            >
                <img
                    :src="attachment.url"
                    :alt="attachment.original_filename || 'Image attachment'"
                    class="block w-full object-scale-down"
                    :class="landscape[attachment.url] ? 'aspect-[2/1]' : 'aspect-square'"
                    @load="onLoad($event, attachment.url)"
                    @error="failed[attachment.url] = true"
                />
                <span class="block truncate border-t px-2 py-1 text-xs">{{ attachment.original_filename }}</span>
            </button>
            <component
                :is="disabled || !attachment.url ? 'span' : 'a'"
                v-else
                :href="disabled ? undefined : attachment.url"
                :class="own ? 'border-white/20 bg-black/10 text-white hover:bg-black/20' : 'border-muted-foreground/20 bg-muted/20 hover:bg-muted/30'"
                class="inline-flex min-h-11 max-w-full items-center gap-1.5 rounded border px-2 py-1 text-xs transition-colors"
                target="_blank"
                rel="noopener noreferrer"
            >
                <Paperclip class="size-3 shrink-0" aria-hidden="true" />
                <span class="min-w-0 truncate">{{ attachment.original_filename || 'Attachment' }}</span>
            </component>
        </template>
    </div>
</template>
