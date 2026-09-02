<script setup lang="ts">
import { formatListDateTime } from '@shared/tasks/date-time';
import { computed } from 'vue';

const props = defineProps<{
    value?: string | Date | null;
}>();

const formatted = computed(() => formatListDateTime(props.value));
</script>

<template>
    <time :datetime="formatted.dateTime ?? undefined" class="flex flex-col" data-testid="task-list-timestamp">
        <span class="text-foreground text-sm">{{ formatted.label }}</span>
        <span v-if="formatted.time" class="text-muted-foreground text-xs">{{ formatted.time }}</span>
    </time>
</template>
