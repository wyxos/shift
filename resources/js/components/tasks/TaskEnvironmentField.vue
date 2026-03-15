<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { projectEnvironmentOptions, type TaskProjectOption } from '@/shared/tasks/projects';
import { computed, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        projectId: number | null;
        projects: TaskProjectOption[];
        label?: string;
        placeholder?: string;
        disabled?: boolean;
        testId?: string;
    }>(),
    {
        modelValue: null,
        label: 'Environment',
        placeholder: 'Select an environment',
        disabled: false,
        testId: 'task-environment',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const inputClass =
    'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

const options = computed(() => projectEnvironmentOptions(props.projects, props.projectId));

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

        <select
            v-else
            :value="modelValue ?? ''"
            :class="inputClass"
            :data-testid="testId"
            :disabled="disabled"
            @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value || null)"
        >
            <option value="">{{ placeholder }}</option>
            <option v-for="environment in options" :key="environment.key" :value="environment.key">
                {{ environment.label }}
            </option>
        </select>
    </div>
</template>
