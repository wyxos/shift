<script setup lang="ts">
import { decodeHtmlToText, renderRichContent } from '@shared/tasks/rich-content';
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ description?: string | null }>();
const content = computed(() => renderRichContent(props.description));
const preview = computed(() =>
    decodeHtmlToText(content.value.replace(/<\/(p|div|li|h[1-6]|blockquote)>/gi, '$& '))
        .replace(/\s+/g, ' ')
        .trim(),
);
const hasContent = computed(() => Boolean(preview.value || /<img\b/i.test(content.value)));
</script>

<template>
    <details
        v-if="hasContent"
        class="group border-muted-foreground/10 bg-background sticky -top-4 z-10 mb-4 border-b pb-3 lg:hidden"
        data-testid="task-report-context"
    >
        <summary
            class="focus-visible:ring-ring min-h-11 cursor-pointer list-none rounded-sm py-2 focus-visible:ring-2 focus-visible:outline-none [&::-webkit-details-marker]:hidden"
        >
            <span class="text-foreground flex items-center justify-between gap-2 text-sm font-medium">
                Description
                <ChevronDown class="size-4 shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
            </span>
            <span v-if="preview" class="text-muted-foreground mt-1 line-clamp-2 text-sm leading-5 group-open:hidden">{{ preview }}</span>
        </summary>
        <div
            class="shift-rich text-foreground focus-visible:ring-ring max-h-40 min-w-0 overflow-auto rounded-sm py-2 text-sm leading-5 break-words focus-visible:ring-2 focus-visible:outline-none"
            data-testid="task-report-context-body"
            role="region"
            aria-label="Task description"
            tabindex="0"
            v-html="content"
        ></div>
    </details>
</template>
