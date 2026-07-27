<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    empty: boolean;
    emptyLabel: string;
    label: string;
}>();
</script>

<template>
    <div :class="cn('w-full', props.class)" data-slot="responsive-record-list">
        <div class="hidden lg:block" data-slot="responsive-record-list-desktop">
            <slot name="desktop" />
        </div>

        <div class="lg:hidden" data-slot="responsive-record-list-compact">
            <div v-if="empty" class="text-foreground rounded-lg border px-4 py-10 text-center text-sm" role="status">
                {{ emptyLabel }}
            </div>
            <div v-else :aria-label="label" class="divide-y overflow-hidden rounded-lg border" role="list">
                <slot name="compact" />
            </div>
        </div>
    </div>
</template>
