<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { SmilePlus } from 'lucide-vue-next';
import { ref } from 'vue';
import type { ThreadReaction } from '../../tasks/types';

const props = defineProps<{
    reactions: ThreadReaction[];
    reacting?: boolean;
    summary?: boolean;
}>();
const emit = defineEmits<{ select: [emoji: string] }>();
const open = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const options = [
    { emoji: '👍', label: 'Thumbs up' },
    { emoji: '✅', label: 'Check mark' },
    { emoji: '📋', label: 'Clipboard' },
    { emoji: '🤣', label: 'Laugh' },
    { emoji: '❤️', label: 'Heart' },
];

function closeOnBlur(event: FocusEvent) {
    if (event.currentTarget instanceof HTMLElement && !event.currentTarget.contains(event.relatedTarget as Node | null)) {
        open.value = false;
    }
}

function closePalette() {
    if (open.value) trigger.value?.focus({ preventScroll: true });
    open.value = false;
}

function select(emoji: string) {
    closePalette();
    emit('select', emoji);
}
</script>

<template>
    <div v-if="summary && reactions.length" class="mt-1 flex flex-wrap gap-1" aria-label="Reactions">
        <TooltipProvider :delay-duration="150">
            <Tooltip v-for="reaction in reactions" :key="reaction.emoji">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="border-border bg-background text-foreground hover:bg-accent aria-pressed:border-primary aria-pressed:bg-secondary inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs"
                        :aria-label="`${options.find((option) => option.emoji === reaction.emoji)?.label ?? reaction.emoji}, ${reaction.count}`"
                        :aria-pressed="reaction.reacted"
                        :aria-disabled="reacting || undefined"
                        @click="!reacting && select(reaction.emoji)"
                    >
                        {{ reaction.emoji }} {{ reaction.count }}
                    </button>
                </TooltipTrigger>
                <TooltipContent v-if="reaction.reactors?.length" class="max-w-64 break-words whitespace-normal motion-reduce:animate-none">
                    {{ reaction.reactors.join(', ') }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
    <div v-else-if="!summary" class="task-comment-reactions" @focusout="closeOnBlur">
        <button
            type="button"
            ref="trigger"
            class="task-comment-reaction-trigger bg-popover text-popover-foreground border-border hover:bg-accent absolute top-0 inline-grid size-9 place-items-center rounded-md border"
            aria-label="Quick reactions"
            title="Quick reactions"
            :aria-expanded="open"
            @click="open = !open"
        >
            <SmilePlus class="size-4" aria-hidden="true" />
        </button>
        <div
            class="task-comment-reaction-palette bg-popover text-popover-foreground border-border absolute z-10 flex w-max items-center rounded-md border p-0.5 shadow-sm"
            :class="{ 'is-open': open }"
            role="group"
            aria-label="Quick reactions"
            @keydown.esc.stop="closePalette"
        >
            <button
                v-for="option in options"
                :key="option.emoji"
                type="button"
                class="hover:bg-accent aria-pressed:bg-secondary inline-grid size-8 place-items-center rounded text-base"
                :title="option.label"
                :aria-label="`React with ${option.label}`"
                :aria-pressed="props.reactions.some((reaction) => reaction.emoji === option.emoji && reaction.reacted)"
                :aria-disabled="reacting || undefined"
                @click="!reacting && select(option.emoji)"
            >
                <span aria-hidden="true">{{ option.emoji }}</span>
            </button>
        </div>
    </div>
</template>
