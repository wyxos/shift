<script setup lang="ts">
import { Collapsible, CollapsibleContent } from '../../../components/ui/collapsible';

defineProps<{
    open: boolean;
    html: string;
}>();

const emit = defineEmits<{
    (e: 'accept'): void;
    (e: 'reject'): void;
}>();
</script>

<template>
    <Collapsible :open="open">
        <CollapsibleContent
            data-testid="ai-improve-drawer"
            class="ai-improve-drawer mt-2 flex max-h-[600px] flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg"
        >
            <div class="border-b border-slate-200 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">AI Suggested Improvement</h3>
                <p class="mt-1 text-xs text-slate-500">Review and accept to replace the editor content.</p>
            </div>
            <div class="min-h-0 flex-1 overflow-auto p-4" data-testid="ai-improve-preview-scroll">
                <div class="tiptap shift-rich text-sm leading-6 text-slate-800" data-testid="ai-improve-preview" v-html="html"></div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button
                    type="button"
                    data-testid="ai-improve-reject"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100"
                    @click="emit('reject')"
                >
                    Keep Original
                </button>
                <button
                    type="button"
                    data-testid="ai-improve-accept"
                    class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
                    @click="emit('accept')"
                >
                    Use Improvement
                </button>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>

<style>
.ai-improve-drawer[data-state='open'] {
    animation: ai-improve-drawer-open 220ms cubic-bezier(0.16, 1, 0.3, 1);
}

.ai-improve-drawer[data-state='closed'] {
    animation: ai-improve-drawer-close 180ms cubic-bezier(0.4, 0, 1, 1);
}

@keyframes ai-improve-drawer-open {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes ai-improve-drawer-close {
    from {
        opacity: 1;
        transform: translateY(0);
    }
    to {
        opacity: 0;
        transform: translateY(8px);
    }
}
</style>
