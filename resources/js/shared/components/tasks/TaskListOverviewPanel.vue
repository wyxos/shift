<script lang="ts" setup>
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ResponsiveRecordList } from '@/components/ui/record-list';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
import TaskListCompact from './TaskListCompact.vue';
import TaskListFiltersSheet from './TaskListFiltersSheet.vue';
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

type Option = {
    value: string;
    label: string;
};

interface Props {
    tasks: TaskListRow[];
    totalTasks: number;
    loading?: boolean;
    error?: string | null;
    deleteLoading?: number | null;
    currentPage: number;
    lastPage: number;
    from: number;
    to: number;
    highlightedTaskId: number | null;
    filtersOpen: boolean;
    activeFilterCount: number;
    draftStatuses: string[];
    draftPriorities: string[];
    draftSearchTerm: string;
    draftEnvironmentTerm: string;
    draftProjectId?: string;
    draftType?: string;
    draftSortBy: string;
    includeTypeFilter?: boolean;
    projectOptions?: Option[];
    statusOptions: Option[];
    priorityOptions: Option[];
    typeOptions?: Option[];
    sortByOptions: Option[];
    title?: string;
    description?: string;
    emptyLabel?: string;
    itemLabel?: string;
    getTaskEnvironmentLabel: (task: TaskListRow) => string;
    setFiltersOpen: (value: boolean) => void;
    setDraftStatuses: (value: string[]) => void;
    setDraftPriorities: (value: string[]) => void;
    setDraftSearchTerm: (value: string) => void;
    setDraftEnvironmentTerm: (value: string) => void;
    setDraftProjectId?: (value: string) => void;
    setDraftType?: (value: string) => void;
    setDraftSortBy: (value: string) => void;
    resetFilters: () => void;
    applyFilters: () => void;
    selectAllStatuses: () => void;
    selectAllPriorities: () => void;
    openEdit: (taskId: number) => void | Promise<void>;
    deleteTask: (taskId: number) => void | Promise<void>;
    finalizeRequirementBatch?: (batchId: number) => void | Promise<void>;
    requirementBatchFinalizeLoading?: number | null;
    goToPage: (page: number) => void;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Tasks',
    description: 'Default view hides completed and closed tasks.',
    emptyLabel: 'No tasks found',
    itemLabel: 'tasks',
    loading: false,
    error: null,
    deleteLoading: null,
    finalizeRequirementBatch: undefined,
    requirementBatchFinalizeLoading: null,
    draftProjectId: '',
    draftType: 'all',
    includeTypeFilter: false,
    projectOptions: () => [],
    typeOptions: () => [],
    setDraftProjectId: () => {},
    setDraftType: () => {},
});

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
    <section class="flex w-full flex-col gap-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-lg font-semibold">{{ title }}</h1>
                <p class="text-muted-foreground text-sm">{{ description }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <TaskListFiltersSheet
                    :open="filtersOpen"
                    :active-filter-count="activeFilterCount"
                    :draft-statuses="draftStatuses"
                    :draft-priorities="draftPriorities"
                    :draft-search-term="draftSearchTerm"
                    :draft-environment-term="draftEnvironmentTerm"
                    :draft-project-id="draftProjectId"
                    :draft-type="draftType"
                    :draft-sort-by="draftSortBy"
                    :include-type-filter="includeTypeFilter"
                    :project-options="projectOptions"
                    :status-options="statusOptions"
                    :status-label="itemLabel === 'requirements' ? 'State' : 'Status'"
                    :priority-options="priorityOptions"
                    :type-options="typeOptions"
                    :sort-by-options="sortByOptions"
                    :set-open="setFiltersOpen"
                    :set-draft-statuses="setDraftStatuses"
                    :set-draft-priorities="setDraftPriorities"
                    :set-draft-search-term="setDraftSearchTerm"
                    :set-draft-environment-term="setDraftEnvironmentTerm"
                    :set-draft-project-id="setDraftProjectId"
                    :set-draft-type="setDraftType"
                    :set-draft-sort-by="setDraftSortBy"
                    :reset-filters="resetFilters"
                    :apply-filters="applyFilters"
                    :select-all-statuses="selectAllStatuses"
                    :select-all-priorities="selectAllPriorities"
                />

                <slot name="actions" />
            </div>
        </div>

        <div>
            <div class="text-muted-foreground mb-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span>Showing {{ from }} to {{ to }} of {{ totalTasks }} {{ itemLabel }}</span>
                <span v-if="activeFilterCount">{{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active</span>
            </div>

            <div v-if="loading" class="text-muted-foreground py-8 text-center">Loading...</div>
            <div v-else-if="error" class="text-destructive py-8 text-center">{{ error }}</div>
            <ResponsiveRecordList
                v-else
                :empty="tasks.length === 0"
                :empty-label="emptyLabel"
                :label="itemLabel === 'requirements' ? 'Requirements' : itemLabel === 'app errors' ? 'App errors' : 'Tasks'"
            >
                <template #desktop>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{
                                    itemLabel === 'requirements' ? 'Requirement' : itemLabel === 'app errors' ? 'Error' : 'Task'
                                }}</TableHead>
                                <TableHead>{{ itemLabel === 'requirements' ? 'State' : 'Status' }}</TableHead>
                                <TableHead>Priority</TableHead>
                                <TableHead>Environment</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableEmpty v-if="tasks.length === 0" :colspan="5">{{ emptyLabel }}</TableEmpty>
                            <template v-else-if="itemLabel === 'requirements'">
                                <template v-for="group in groupedRequirements" :key="group.key">
                                    <TableRow data-testid="requirement-pack-row" class="bg-muted/30 hover:bg-muted/40">
                                        <TableCell colspan="5" class="py-3">
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div class="min-w-0">
                                                    <div class="text-foreground truncate text-sm font-semibold">
                                                        {{ requirementPackTitle(group.batch) }}
                                                    </div>
                                                    <div class="text-muted-foreground mt-0.5 text-xs">
                                                        {{ requirementPackMeta(group.batch, group.tasks) }}
                                                    </div>
                                                </div>
                                                <Button
                                                    v-if="canFinalizeGroup(group)"
                                                    :data-testid="`requirement-pack-finalize-${requirementPackId(group)}`"
                                                    :disabled="isRequirementPackFinalizeLoading(group)"
                                                    :aria-label="requirementPackFinalizeLabel(group)"
                                                    class="h-8 w-8 shrink-0 p-0 sm:h-9 sm:w-auto sm:px-3"
                                                    size="sm"
                                                    type="button"
                                                    variant="outline"
                                                    @click="finalizeRequirementPack(group)"
                                                >
                                                    <CheckCircle2 class="h-4 w-4 sm:mr-2" />
                                                    <span class="hidden sm:inline">
                                                        {{ requirementPackFinalizeLabel(group) }}
                                                    </span>
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow
                                        v-for="task in group.tasks"
                                        :key="task.id"
                                        :class="highlightedTaskId === task.id ? 'bg-sky-500/10 ring-2 ring-sky-500/40 ring-inset' : ''"
                                        data-testid="task-row"
                                    >
                                        <TableCell class="min-w-[18rem] whitespace-normal">
                                            <button
                                                type="button"
                                                class="text-card-foreground hover:text-primary focus-visible:ring-ring rounded-sm text-left font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                                :aria-label="`Open task details for ${task.title}`"
                                                :data-testid="`task-title-${task.id}`"
                                                @click="openEdit(task.id)"
                                            >
                                                {{ task.title }}
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                :class="getRequirementStatusBadgeClass(requirementState(task))"
                                                :data-testid="`task-status-badge-${task.id}`"
                                                variant="outline"
                                            >
                                                {{ getRequirementStatusLabel(requirementState(task)) }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                :class="getPriorityBadgeClass(task.priority)"
                                                :data-testid="`task-priority-badge-${task.id}`"
                                                variant="outline"
                                            >
                                                {{ getPriorityLabel(task.priority) }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge :data-testid="`task-environment-badge-${task.id}`" variant="outline">
                                                {{ getTaskEnvironmentLabel(task) }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex justify-end gap-2">
                                                <ActionIconButton
                                                    label="Open requirement details"
                                                    title="Open details"
                                                    :data-testid="`task-open-${task.id}`"
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
                                                    :data-testid="`task-delete-${task.id}`"
                                                    @click="deleteTask(task.id)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </ActionIconButton>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>
                            </template>
                            <template v-else>
                                <TableRow
                                    v-for="task in tasks"
                                    :key="task.id"
                                    :class="highlightedTaskId === task.id ? 'bg-sky-500/10 ring-2 ring-sky-500/40 ring-inset' : ''"
                                    data-testid="task-row"
                                >
                                    <TableCell class="min-w-[18rem] whitespace-normal">
                                        <div class="flex min-w-0 flex-col gap-1.5" :data-testid="`task-title-cell-${task.id}`">
                                            <button
                                                type="button"
                                                class="text-card-foreground hover:text-primary focus-visible:ring-ring rounded-sm text-left font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                                :aria-label="`Open task details for ${task.title}`"
                                                :data-testid="`task-title-${task.id}`"
                                                @click="openEdit(task.id)"
                                            >
                                                {{ task.title }}
                                            </button>
                                            <div class="flex flex-wrap items-center gap-2" :data-testid="`task-title-badges-${task.id}`">
                                                <Badge
                                                    v-if="taskProjectLabel(task)"
                                                    :data-testid="`task-project-badge-${task.id}`"
                                                    variant="secondary"
                                                >
                                                    {{ taskProjectLabel(task) }}
                                                </Badge>
                                                <Badge
                                                    :class="getTaskTypeBadgeClass(task.type)"
                                                    :data-testid="`task-type-badge-${task.id}`"
                                                    variant="outline"
                                                >
                                                    {{ getTaskTypeLabel(task.type, task.type_label) }}
                                                </Badge>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            :class="getStatusBadgeClass(task.status)"
                                            :data-testid="`task-status-badge-${task.id}`"
                                            variant="outline"
                                        >
                                            {{ getStatusLabel(task.status) }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            :class="getPriorityBadgeClass(task.priority)"
                                            :data-testid="`task-priority-badge-${task.id}`"
                                            variant="outline"
                                        >
                                            {{ getPriorityLabel(task.priority) }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :data-testid="`task-environment-badge-${task.id}`" variant="outline">
                                            {{ getTaskEnvironmentLabel(task) }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex justify-end gap-2">
                                            <ActionIconButton
                                                label="Open task details"
                                                title="Open details"
                                                :data-testid="`task-open-${task.id}`"
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
                                                :data-testid="`task-delete-${task.id}`"
                                                @click="deleteTask(task.id)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </ActionIconButton>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                </template>

                <template #compact>
                    <TaskListCompact
                        :delete-loading="deleteLoading"
                        :delete-task="deleteTask"
                        :finalize-requirement-batch="finalizeRequirementBatch"
                        :get-task-environment-label="getTaskEnvironmentLabel"
                        :highlighted-task-id="highlightedTaskId"
                        :item-label="itemLabel"
                        :open-edit="openEdit"
                        :requirement-batch-finalize-loading="requirementBatchFinalizeLoading"
                        :tasks="tasks"
                    />
                </template>
            </ResponsiveRecordList>

            <div v-if="lastPage > 1" class="mt-4 flex items-center justify-between border-t pt-4">
                <div class="text-muted-foreground text-xs">Page {{ currentPage }} of {{ lastPage }}</div>
                <div class="flex items-center gap-2">
                    <Button :disabled="loading || currentPage <= 1" size="sm" variant="outline" @click="goToPage(currentPage - 1)">Previous</Button>
                    <Button :disabled="loading || currentPage >= lastPage" size="sm" variant="outline" @click="goToPage(currentPage + 1)"
                        >Next</Button
                    >
                </div>
            </div>
        </div>
    </section>
</template>
