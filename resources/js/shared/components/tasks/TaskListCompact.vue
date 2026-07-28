<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ResponsiveRecordItem } from '@/components/ui/record-list';
import ActionIconButton from '@shared/components/ActionIconButton.vue';
import {
    getPriorityBadgeClass,
    getPriorityLabel,
    getRequirementStatusBadgeClass,
    getRequirementStatusLabel,
    getStatusBadgeClass,
    getStatusLabel,
    getTaskTypeBadgeClass,
    getTaskTypeLabel,
} from '@shared/tasks/presentation';
import { CheckCircle2, Eye, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    canDeleteTask,
    canFinalizeRequirementPack,
    groupRequirementTasks,
    requirementPackId,
    requirementPackMeta,
    requirementPackTitle,
    requirementState,
    taskProjectLabel,
    type RequirementGroup,
    type TaskListRow,
} from './task-list';

const props = withDefaults(
    defineProps<{
        deleteLoading?: number | null;
        deleteTask: (taskId: number) => void | Promise<void>;
        finalizeRequirementBatch?: (batchId: number) => void | Promise<void>;
        getTaskEnvironmentLabel: (task: TaskListRow) => string;
        highlightedTaskId: number | null;
        itemLabel: string;
        openEdit: (taskId: number) => void | Promise<void>;
        requirementBatchFinalizeLoading?: number | null;
        tasks: TaskListRow[];
    }>(),
    {
        deleteLoading: null,
        finalizeRequirementBatch: undefined,
        requirementBatchFinalizeLoading: null,
    },
);

const groupedRequirements = computed(() => groupRequirementTasks(props.tasks, props.itemLabel));

function canFinalizeGroup(group: RequirementGroup) {
    return Boolean(props.finalizeRequirementBatch) && canFinalizeRequirementPack(group);
}

function isRequirementPackFinalizeLoading(group: RequirementGroup) {
    const batchId = requirementPackId(group);

    return batchId !== null && props.requirementBatchFinalizeLoading === batchId;
}

function requirementPackFinalizeLabel(group: RequirementGroup) {
    return isRequirementPackFinalizeLoading(group) ? 'Finalizing...' : 'Finalize';
}

async function finalizeRequirementPack(group: RequirementGroup) {
    const batchId = requirementPackId(group);

    if (batchId === null || !props.finalizeRequirementBatch) return;

    await props.finalizeRequirementBatch(batchId);
}
</script>

<template>
    <div class="contents" role="presentation">
        <template v-if="itemLabel === 'requirements'">
            <section
                v-for="group in groupedRequirements"
                :key="group.key"
                :aria-label="requirementPackTitle(group.batch)"
                data-testid="requirement-pack-compact-group"
                role="listitem"
            >
                <div class="bg-muted/30 flex items-start justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <h2 class="text-foreground text-sm font-semibold">{{ requirementPackTitle(group.batch) }}</h2>
                        <p class="text-muted-foreground mt-1 text-xs">{{ requirementPackMeta(group.batch, group.tasks) }}</p>
                    </div>
                    <Button
                        v-if="canFinalizeGroup(group)"
                        :data-testid="`requirement-pack-compact-finalize-${requirementPackId(group)}`"
                        :disabled="isRequirementPackFinalizeLoading(group)"
                        :aria-label="requirementPackFinalizeLabel(group)"
                        class="shrink-0"
                        size="sm"
                        type="button"
                        variant="outline"
                        @click="finalizeRequirementPack(group)"
                    >
                        <CheckCircle2 class="h-4 w-4" />
                        {{ requirementPackFinalizeLabel(group) }}
                    </Button>
                </div>
                <div :aria-label="`${requirementPackTitle(group.batch)} items`" class="divide-y border-t" role="list">
                    <ResponsiveRecordItem
                        v-for="task in group.tasks"
                        :key="task.id"
                        :class="highlightedTaskId === task.id ? 'bg-sky-500/10 ring-2 ring-sky-500/40 ring-inset' : ''"
                        :data-testid="`requirement-compact-row-${task.id}`"
                    >
                        <button
                            type="button"
                            class="text-card-foreground hover:text-primary focus-visible:ring-ring min-w-0 self-start rounded-sm text-left font-medium [overflow-wrap:anywhere] transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            :aria-label="`Open requirement details for ${task.title}`"
                            @click="openEdit(task.id)"
                        >
                            {{ task.title }}
                        </button>
                        <dl class="grid grid-cols-2 gap-3">
                            <div class="flex min-w-0 flex-col gap-1">
                                <dt class="text-muted-foreground text-xs">State</dt>
                                <dd>
                                    <Badge :class="getRequirementStatusBadgeClass(requirementState(task))" variant="outline">
                                        {{ getRequirementStatusLabel(requirementState(task)) }}
                                    </Badge>
                                </dd>
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <dt class="text-muted-foreground text-xs">Priority</dt>
                                <dd>
                                    <Badge :class="getPriorityBadgeClass(task.priority)" variant="outline">{{
                                        getPriorityLabel(task.priority)
                                    }}</Badge>
                                </dd>
                            </div>
                            <div class="col-span-2 flex min-w-0 flex-col gap-1">
                                <dt class="text-muted-foreground text-xs">Environment</dt>
                                <dd>
                                    <Badge variant="outline">{{ getTaskEnvironmentLabel(task) }}</Badge>
                                </dd>
                            </div>
                        </dl>

                        <template #actions>
                            <ActionIconButton
                                label="Open requirement details"
                                title="Open details"
                                :data-testid="`requirement-compact-open-${task.id}`"
                                @click="openEdit(task.id)"
                            >
                                <Eye class="h-4 w-4" />
                            </ActionIconButton>
                            <ActionIconButton
                                v-if="canDeleteTask(task)"
                                label="Delete requirement"
                                title="Delete"
                                variant="destructive"
                                :loading="deleteLoading === task.id"
                                :data-testid="`requirement-compact-delete-${task.id}`"
                                @click="deleteTask(task.id)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </ActionIconButton>
                        </template>
                    </ResponsiveRecordItem>
                </div>
            </section>
        </template>

        <template v-else>
            <ResponsiveRecordItem
                v-for="task in tasks"
                :key="task.id"
                :class="highlightedTaskId === task.id ? 'bg-sky-500/10 ring-2 ring-sky-500/40 ring-inset' : ''"
                :data-testid="`task-compact-row-${task.id}`"
            >
                <button
                    type="button"
                    class="text-card-foreground hover:text-primary focus-visible:ring-ring min-w-0 self-start rounded-sm text-left font-medium [overflow-wrap:anywhere] transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    :aria-label="`Open task details for ${task.title}`"
                    @click="openEdit(task.id)"
                >
                    {{ task.title }}
                </button>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="taskProjectLabel(task)" variant="secondary">{{ taskProjectLabel(task) }}</Badge>
                    <Badge :class="getTaskTypeBadgeClass(task.type)" variant="outline">
                        {{ getTaskTypeLabel(task.type, task.type_label) }}
                    </Badge>
                </div>
                <dl class="grid grid-cols-2 gap-3">
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-muted-foreground text-xs">Status</dt>
                        <dd>
                            <Badge :class="getStatusBadgeClass(task.status)" variant="outline">{{ getStatusLabel(task.status) }}</Badge>
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-muted-foreground text-xs">Priority</dt>
                        <dd>
                            <Badge :class="getPriorityBadgeClass(task.priority)" variant="outline">{{ getPriorityLabel(task.priority) }}</Badge>
                        </dd>
                    </div>
                    <div class="col-span-2 flex min-w-0 flex-col gap-1">
                        <dt class="text-muted-foreground text-xs">Environment</dt>
                        <dd>
                            <Badge variant="outline">{{ getTaskEnvironmentLabel(task) }}</Badge>
                        </dd>
                    </div>
                </dl>

                <template #actions>
                    <ActionIconButton
                        label="Open task details"
                        title="Open details"
                        :data-testid="`task-compact-open-${task.id}`"
                        @click="openEdit(task.id)"
                    >
                        <Eye class="h-4 w-4" />
                    </ActionIconButton>
                    <ActionIconButton
                        v-if="canDeleteTask(task)"
                        label="Delete task"
                        title="Delete"
                        variant="destructive"
                        :loading="deleteLoading === task.id"
                        :data-testid="`task-compact-delete-${task.id}`"
                        @click="deleteTask(task.id)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </ActionIconButton>
                </template>
            </ResponsiveRecordItem>
        </template>
    </div>
</template>
