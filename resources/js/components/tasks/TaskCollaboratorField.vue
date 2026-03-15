<script setup lang="ts">
import SharedTaskCollaboratorField from '@/shared/components/TaskCollaboratorField.vue';
import { emptyTaskCollaborators, type TaskCollaboratorSelection } from '@/shared/tasks/collaborators';

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
        internalLabel: 'Internal',
        internalDescription: 'Registered SHIFT users on this project.',
        externalLabel: 'External',
        externalDescription: 'Users available in the selected environment.',
        searchPlaceholder: 'Search collaborators',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: TaskCollaboratorSelection];
}>();
</script>

<template>
    <SharedTaskCollaboratorField v-bind="props" @update:model-value="emit('update:modelValue', $event)" />
</template>
