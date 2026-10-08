<script setup lang="ts">
import { Label } from '@/components/ui/label';
import TaskChoiceMenu from '@/shared/components/tasks/TaskChoiceMenu.vue';
import { getPriorityOptions, getRequirementStatusOptions, getStatusOptions } from '@/shared/tasks/presentation';
import { computed } from 'vue';

const props = defineProps<{ state: any }>();
const state = props.state;
const editPriorityModel = computed({
    get: () => state.editForm.priority,
    set: (value: string) => state.setEditField('priority', value),
});
const editStatusModel = computed({
    get: () => state.editForm.status,
    set: (value: string) => state.setEditField('status', value),
});
const editRequirementStatusModel = computed({
    get: () => state.editForm.requirement_status,
    set: (value: string) => state.setEditField('requirement_status', value),
});
const taskStatusOptions = getStatusOptions({ includeClosed: false });
const requirementStatusOptions = getRequirementStatusOptions();
const taskPriorityOptions = getPriorityOptions();

function formatTaskTime(value?: string | null) {
    if (!value) return 'Unknown';
    return value.slice(11, 16);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            class="grid gap-4"
            :class="state.isErrorIntakeTask ? 'grid-cols-2 sm:grid-cols-4' : 'sm:grid-cols-2 xl:grid-cols-4'"
            data-testid="edit-task-meta"
        >
            <div class="space-y-1">
                <div class="text-muted-foreground text-[11px] leading-4" data-testid="edit-task-meta-label">Created by</div>
                <div data-testid="edit-task-created-by" class="text-foreground text-sm font-medium">
                    {{ state.editTaskCreatorLabel }}
                </div>
            </div>
            <div class="space-y-1">
                <div class="text-muted-foreground text-[11px] leading-4" data-testid="edit-task-meta-label">Created</div>
                <div data-testid="edit-task-created-at" class="text-foreground text-sm font-medium">
                    {{ formatTaskTime(state.editTask.created_at) }}
                </div>
            </div>
            <div class="space-y-1">
                <div class="text-muted-foreground text-[11px] leading-4" data-testid="edit-task-meta-label">Updated</div>
                <div data-testid="edit-task-updated-at" class="text-foreground text-sm font-medium">
                    {{ formatTaskTime(state.editTask.updated_at) }}
                </div>
            </div>
            <div class="space-y-1">
                <div class="text-muted-foreground text-[11px] leading-4" data-testid="edit-task-meta-label">Environment</div>
                <div data-testid="edit-task-environment" class="text-foreground text-sm font-medium">
                    {{ state.editTaskEnvironmentLabel }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3" data-testid="task-choice-fields">
            <div v-if="!state.isRequirementPhase" class="space-y-2">
                <Label class="text-muted-foreground">Status</Label>
                <TaskChoiceMenu
                    v-model="editStatusModel"
                    :label="'Task status'"
                    :disabled="!state.canEditTaskScope || state.taskSaving"
                    :options="taskStatusOptions"
                    test-id-prefix="task-status"
                />
            </div>

            <div v-if="state.isRequirementPhase" class="space-y-2">
                <Label class="text-muted-foreground">Requirement state</Label>
                <TaskChoiceMenu
                    v-model="editRequirementStatusModel"
                    :label="'Requirement state'"
                    :disabled="!state.canEditTaskScope || state.taskSaving"
                    :options="requirementStatusOptions"
                    test-id-prefix="requirement-status"
                />
            </div>

            <div class="space-y-2">
                <Label class="text-muted-foreground">Priority</Label>
                <TaskChoiceMenu
                    v-model="editPriorityModel"
                    :label="'Task priority'"
                    :disabled="!state.canEditTaskScope || state.taskSaving"
                    :options="taskPriorityOptions"
                    test-id-prefix="task-priority"
                />
            </div>
        </div>
    </div>
</template>
