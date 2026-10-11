<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    state: 'idle' | 'pending' | 'saving' | 'saved' | 'error';
    error?: string | null;
}>();
defineEmits<{ retry: [] }>();
const statusLabel = computed(() => ({ idle: '', pending: 'Changes pending', saving: 'Saving…', saved: 'All changes saved', error: '' })[props.state]);
</script>

<template>
    <div class="mt-2 space-y-1 text-xs" data-testid="task-save-status" :data-state="state">
        <div class="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>Changes save automatically.</span>
            <span role="status" aria-live="polite" aria-atomic="true">{{ statusLabel }}</span>
        </div>
        <div v-if="state === 'error'" class="text-destructive flex flex-wrap items-center gap-x-2 gap-y-1">
            <span role="alert">Changes couldn’t be saved. {{ error }}</span>
            <button
                type="button"
                class="focus-visible:ring-ring min-h-8 rounded px-2 font-medium underline underline-offset-2 focus-visible:ring-2 max-md:min-h-11 [@media(pointer:coarse)]:min-h-11"
                data-testid="task-save-retry"
                @click="$emit('retry')"
            >
                Retry
            </button>
        </div>
    </div>
</template>
