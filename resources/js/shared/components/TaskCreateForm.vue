<script setup lang="ts">
import type { AxiosInstance } from 'axios';
import { ButtonGroup } from '../../components/ui/button-group';
import type { UploadEndpoints } from '../lib/chunkedUpload';
import { getPriorityOptions } from '../tasks/presentation';
import ShiftEditor from './ShiftEditor.vue';

type TaskCreateDraft = {
    title: string;
    priority: string;
    description: string;
};

const props = withDefaults(
    defineProps<{
        modelValue: TaskCreateDraft;
        tempIdentifier: string;
        titleLabel?: string;
        priorityLabel?: string;
        descriptionLabel?: string;
        titlePlaceholder?: string;
        descriptionPlaceholder?: string;
        titleTestId?: string;
        descriptionTestId?: string;
        error?: string | null;
        enableAiImprove?: boolean;
        aiImproveUrl?: string;
        removeTempUrl?: string;
        resolveTempUrl?: (data: any) => string;
        uploadEndpoints?: UploadEndpoints;
        axiosInstance?: AxiosInstance | typeof import('axios').default;
    }>(),
    {
        titleLabel: 'Task',
        priorityLabel: 'Priority',
        descriptionLabel: 'Description',
        titlePlaceholder: 'Short, descriptive title',
        descriptionPlaceholder: 'Write the full task details, then drag files into the editor.',
        titleTestId: 'create-task-title',
        descriptionTestId: 'create-description-editor',
        error: null,
        enableAiImprove: true,
    },
);

const emit = defineEmits<{
    submit: [];
    'update:modelValue': [value: TaskCreateDraft];
    'update:uploading': [value: boolean];
}>();

const priorityOptions = getPriorityOptions();

const baseControlClass = 'text-muted-foreground flex items-center gap-2 text-sm leading-none font-medium select-none';
const inputClass =
    'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

function updateField(field: keyof TaskCreateDraft, value: string) {
    emit('update:modelValue', {
        ...props.modelValue,
        [field]: value,
    });
}
</script>

<template>
    <form class="flex h-full min-h-0 flex-col" data-testid="create-task-form" @submit.prevent="emit('submit')">
        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 pb-6">
            <div class="space-y-2">
                <label :class="baseControlClass">
                    {{ titleLabel }}
                </label>
                <input
                    :value="modelValue.title"
                    :placeholder="titlePlaceholder"
                    :class="inputClass"
                    :data-testid="titleTestId"
                    required
                    type="text"
                    @input="updateField('title', ($event.target as HTMLInputElement).value)"
                />
            </div>

            <div class="space-y-2">
                <label :class="baseControlClass">
                    {{ priorityLabel }}
                </label>
                <ButtonGroup
                    :model-value="modelValue.priority"
                    aria-label="Task priority"
                    test-id-prefix="create-task-priority"
                    :options="priorityOptions"
                    :columns="3"
                    @update:modelValue="updateField('priority', $event)"
                />
            </div>

            <div class="space-y-2">
                <label :class="baseControlClass">
                    {{ descriptionLabel }}
                </label>
                <ShiftEditor
                    :model-value="modelValue.description"
                    :temp-identifier="tempIdentifier"
                    :min-height="180"
                    :axios-instance="axiosInstance"
                    :enable-ai-improve="enableAiImprove"
                    :upload-endpoints="uploadEndpoints"
                    :remove-temp-url="removeTempUrl"
                    :ai-improve-url="aiImproveUrl"
                    :resolve-temp-url="resolveTempUrl"
                    :placeholder="descriptionPlaceholder"
                    :data-testid="descriptionTestId"
                    @update:modelValue="updateField('description', $event)"
                    @uploading="emit('update:uploading', $event)"
                />
            </div>

            <slot />

            <div v-if="error" class="text-sm text-red-600">
                {{ error }}
            </div>
        </div>

        <slot name="actions" />
    </form>
</template>
