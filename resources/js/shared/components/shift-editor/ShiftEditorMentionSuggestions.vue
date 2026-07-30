<script setup lang="ts">
import type { MentionCandidate } from './types';
import { mentionKey } from './useShiftEditorMentions';

defineProps<{
    activeIndex: number;
    candidates: MentionCandidate[];
    error?: string | null;
    loading?: boolean;
    open: boolean;
}>();

defineEmits<{
    select: [candidate: MentionCandidate];
}>();
</script>

<template>
    <div
        v-if="open"
        class="bg-popover text-popover-foreground mx-1 max-h-56 overflow-auto rounded-md border p-1 shadow-md"
        data-testid="mention-suggestions"
    >
        <div v-if="loading" class="text-muted-foreground px-2 py-2 text-xs">Finding people...</div>
        <template v-else>
            <template v-for="(candidate, index) in candidates" :key="mentionKey(candidate)">
                <div
                    v-if="index === 0 || (!candidate.isCollaborator && candidates[index - 1]?.isCollaborator)"
                    class="text-muted-foreground px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wide uppercase"
                >
                    {{ candidate.isCollaborator ? 'Task collaborators' : 'Add to task' }}
                </div>
                <button
                    type="button"
                    :class="index === activeIndex ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/70'"
                    class="flex w-full items-center gap-3 rounded-sm px-2 py-2 text-left"
                    :data-testid="`mention-candidate-${mentionKey(candidate)}`"
                    @mousedown.prevent
                    @click="$emit('select', candidate)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ candidate.name }}</span>
                        <span v-if="candidate.email" class="text-muted-foreground block truncate text-xs">{{ candidate.email }}</span>
                    </span>
                    <span v-if="!candidate.isCollaborator" class="text-muted-foreground shrink-0 text-xs font-medium">Add to task</span>
                </button>
            </template>
            <div v-if="error" class="text-destructive px-2 py-2 text-xs">{{ error }}</div>
            <div v-if="!error && candidates.length === 0" class="text-muted-foreground px-2 py-2 text-xs">No eligible people found.</div>
        </template>
    </div>
</template>
