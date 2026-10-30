<script setup lang="ts">
import type { TaskErrorSourceResolution } from '@/shared/tasks/types';
import { computed } from 'vue';

const props = defineProps<{
    buildId?: unknown;
    resolution?: TaskErrorSourceResolution;
}>();

const build = computed(() => (typeof props.buildId === 'string' ? props.buildId : props.resolution?.build_id));
const label = computed(() => {
    const resolution = props.resolution;
    switch (resolution?.status) {
        case 'resolved':
            return 'Original source resolved';
        case 'partial':
            return `${resolution.matched_frames ?? 0} of ${resolution.total_frames ?? 0} frames resolved`;
        case 'invalid_build':
            return 'Invalid UI build identifier';
        case 'invalid_artifact':
            return 'Source maps unavailable';
        case 'no_frames':
            return 'No stack positions captured';
        case 'unmapped':
            return 'No matching source positions';
        default:
            return build.value ? 'Build not registered' : 'UI build not identified';
    }
});
</script>

<template>
    <div class="border-border/60 bg-muted/20 space-y-2 rounded-md border p-3 text-xs" data-testid="error-source-map-status">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-muted-foreground font-medium">UI build</span>
            <code v-if="build" class="min-w-0 font-mono break-all" :title="build" data-testid="error-ui-build">{{ build }}</code>
            <span v-else class="text-muted-foreground">Not recorded</span>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-muted-foreground font-medium">Source maps</span>
            <span data-testid="error-source-map-label">{{ label }}</span>
        </div>
        <div v-if="resolution?.sdk_revision" class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-muted-foreground font-medium">SDK source revision</span>
            <span class="font-mono" :title="resolution.sdk_revision">
                {{ resolution.sdk_revision.slice(0, 12) }}<span v-if="resolution.sdk_dirty"> · modified source</span>
            </span>
        </div>
    </div>
</template>
