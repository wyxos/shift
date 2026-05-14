<script setup lang="ts">
import { ButtonGroup } from '@/components/ui/button-group';
import { Label } from '@/components/ui/label';
import { projectEnvironmentOptions, type TaskProjectOption } from '@/shared/tasks/projects';
import { computed, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        projectId: number | null;
        projects: TaskProjectOption[];
        label?: string;
        disabled?: boolean;
        testId?: string;
    }>(),
    {
        modelValue: null,
        label: 'Environment',
        disabled: false,
        testId: 'task-environment',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const options = computed(() => projectEnvironmentOptions(props.projects, props.projectId));
const buttonOptions = computed(() => [
    { value: '', label: 'No environment' },
    ...options.value.map((option) => ({
        value: option.key,
        label: option.label,
    })),
]);

watch(
    () => [props.projectId, props.modelValue, options.value.map((option) => option.key).join('|')],
    () => {
        if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
            return;
        }

        if (!options.value.some((option) => option.key === props.modelValue)) {
            emit('update:modelValue', null);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-2">
        <Label class="text-muted-foreground">{{ label }}</Label>

        <div
            v-if="projectId === null"
            class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
        >
            Select a project before choosing an environment.
        </div>

        <div
            v-else-if="options.length === 0"
            class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
        >
            No environments are registered for this project yet.
        </div>

        <ButtonGroup
            v-else
            :model-value="modelValue ?? ''"
            :options="buttonOptions"
            :disabled="disabled"
            :aria-label="label"
            :test-id-prefix="testId"
            :columns="buttonOptions.length <= 2 ? 2 : 3"
            @update:modelValue="emit('update:modelValue', $event || null)"
        />
    </div>
</template>
