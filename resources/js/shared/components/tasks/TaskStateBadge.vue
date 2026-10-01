<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { getPriorityLabel, getRequirementStatusLabel, getStatusLabel, getTaskStateIcon } from '@shared/tasks/presentation';
import { computed } from 'vue';

const props = defineProps<{
    kind: 'status' | 'requirement' | 'priority';
    value: string;
}>();

const label = computed(() => {
    if (props.kind === 'priority') return getPriorityLabel(props.value);
    if (props.kind === 'requirement') return getRequirementStatusLabel(props.value);
    return getStatusLabel(props.value);
});
</script>

<template>
    <Badge variant="state">
        <component :is="getTaskStateIcon(value)" aria-hidden="true" />
        {{ label }}
    </Badge>
</template>
