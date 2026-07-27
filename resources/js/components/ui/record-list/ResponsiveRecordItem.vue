<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="cn('bg-background', props.class)" data-slot="responsive-record-item" role="listitem">
        <div class="flex min-w-0 flex-col gap-3 p-4" data-slot="responsive-record-item-content">
            <slot />
        </div>
        <div
            v-if="$slots.actions"
            class="bg-muted/20 flex min-h-12 flex-wrap items-center justify-end gap-2 border-t px-4 py-3"
            data-slot="responsive-record-item-actions"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
