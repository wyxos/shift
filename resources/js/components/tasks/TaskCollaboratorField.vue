<script setup lang="ts">
import SharedTaskCollaboratorField from '@/shared/components/TaskCollaboratorField.vue';
import { emptyTaskCollaborators, type TaskCollaboratorSelection } from '@/shared/tasks/collaborators';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: TaskCollaboratorSelection | null;
        projectId?: number | null;
        environment?: string | null;
        readOnly?: boolean;
        disabled?: boolean;
        lookupUrl?: string | null;
        internalLabel?: string;
        internalDescription?: string;
        externalLabel?: string;
        externalDescription?: string;
        searchPlaceholder?: string;
    }>(),
    {
        modelValue: () => emptyTaskCollaborators(),
        projectId: null,
        environment: null,
        readOnly: false,
        disabled: false,
        lookupUrl: null,
        internalLabel: 'SHIFT team',
        internalDescription: 'Registered SHIFT users on this project.',
        externalLabel: 'Project users',
        externalDescription: 'Users available in this project.',
        searchPlaceholder: 'Search collaborators',
    },
);

const resolvedLookupUrl = computed(
    () => props.lookupUrl ?? (props.projectId === null ? null : route('tasks.collaborators', { project: props.projectId })),
);

const emit = defineEmits<{
    'update:modelValue': [value: TaskCollaboratorSelection];
}>();
</script>

<template>
    <SharedTaskCollaboratorField v-bind="props" :lookup-url="resolvedLookupUrl" @update:model-value="emit('update:modelValue', $event)" />
</template>
