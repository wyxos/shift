<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Tooltip, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { SmilePlus } from 'lucide-vue-next';
import { TooltipContent, TooltipPortal } from 'reka-ui';
import { ref, shallowRef, watch } from 'vue';
import type { ThreadReaction } from '../../tasks/types';

const props = defineProps<{
    reactions: ThreadReaction[];
    reacting?: boolean;
    summary?: boolean;
}>();
const emit = defineEmits<{ select: [emoji: string] }>();
const open = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const summaryElement = ref<HTMLElement | null>(null);
const threadBoundary = shallowRef<HTMLElement | null>(null);
watch(summaryElement, (element) => {
    threadBoundary.value = element?.closest<HTMLElement>('.task-comments-history') ?? null;
});
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
    <div v-if="summary && reactions.length" ref="summaryElement" class="mt-1 flex flex-wrap gap-1" aria-label="Reactions">
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
                <TooltipPortal>
                    <TooltipContent
                        v-if="reaction.reactors?.length"
                        :collision-boundary="threadBoundary"
                        :collision-padding="8"
                        :side-offset="6"
                        align="start"
                        class="task-reaction-people bg-popover text-popover-foreground border-border data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=delayed-open]:animate-in data-[state=delayed-open]:fade-in-0 z-50 overflow-y-auto rounded-md border p-1.5 shadow-md motion-reduce:animate-none"
                    >
                        <ul class="m-0 list-none space-y-1 p-0" aria-label="People who reacted">
                            <li v-for="(name, index) in reaction.reactors" :key="index" class="flex items-center gap-2 rounded-sm px-1.5 py-1">
                                <Avatar class="size-7 shrink-0" aria-hidden="true">
                                    <AvatarFallback class="text-xs font-medium">{{ getInitials(name) }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1 text-sm break-words">{{ name }}</span>
                                <span class="shrink-0 text-base">{{ reaction.emoji }}</span>
                            </li>
                        </ul>
                    </TooltipContent>
                </TooltipPortal>
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
