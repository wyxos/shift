<script lang="ts" setup>
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ActionIconButton from '@shared/components/ActionIconButton.vue';
import { getPriorityBadgeClass, getPriorityLabel, getStatusBadgeClass, getStatusLabel } from '@shared/tasks/presentation';
import { CheckCircle2, Eye, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import TaskListFiltersSheet from './TaskListFiltersSheet.vue';

type Option = {
    value: string;
    label: string;
};

type TaskListRow = {
    id: number;
    project_id?: number | null;
    project?: {
        id: number;
        name: string;
    } | null;
    title: string;
    status: string;
    priority: string;
    phase?: string | null;
    finalized?: boolean | null;
    finalized_at?: string | null;
    environment?: string | null;
    batch_id?: number | null;
    batch_title?: string | null;
    batch?: {
        id: number;
        title?: string | null;
        created_at?: string | null;
        total_items: number;
        requirement_items: number;
        finalized_items: number;
        can_finalize_requirement?: boolean;
    } | null;
    can_delete?: boolean;
    can_finalize_requirement?: boolean;
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
    draftSortBy: string;
    projectOptions?: Option[];
    statusOptions: Option[];
    priorityOptions: Option[];
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
    projectOptions: () => [],
    setDraftProjectId: () => {},
});

const groupedRequirements = computed(() => {
    if (props.itemLabel !== 'requirements') {
        return [];
    }

    const groups = new Map<string, { key: string; batch: TaskListRow['batch']; tasks: TaskListRow[] }>();

    props.tasks.forEach((task) => {
        const batch = taskBatch(task);
        const key = batch?.id ? `batch-${batch.id}` : 'ungrouped';
        const existing = groups.get(key);

        if (existing) {
            existing.tasks.push(task);
            return;
        }

        groups.set(key, {
            key,
            batch,
            tasks: [task],
        });
    });

    return Array.from(groups.values());
});

function taskBatch(task: TaskListRow) {
    if (task.batch) return task.batch;
    if (!task.batch_id) return null;

    return {
        id: task.batch_id,
        title: task.batch_title ?? null,
        created_at: null,
        total_items: 0,
        requirement_items: 0,
        finalized_items: 0,
    };
}

function taskProjectLabel(task: TaskListRow) {
    return task.project?.name?.trim() || null;
}

function requirementPackTitle(batch: TaskListRow['batch']) {
    if (batch?.title) return batch.title;
    if (batch?.id) return `Requirement pack #${batch.id}`;
    return 'Ungrouped requirements';
}

function requirementPackMeta(batch: TaskListRow['batch'], tasks: TaskListRow[]) {
    const total = batch?.total_items || tasks.length;
    const pending = batch?.requirement_items || tasks.filter((task) => task.phase === 'requirement').length;
    const finalized = batch?.finalized_items ?? Math.max(total - pending, 0);

    return `${total} ${total === 1 ? 'item' : 'items'} · ${pending} pending · ${finalized} finalized`;
}

function isFinalizedRequirement(task: TaskListRow) {
    return task.finalized === true || (task.phase !== undefined && task.phase !== null && task.phase !== 'requirement');
}

function canDeleteTask(task: TaskListRow) {
    return task.can_delete === true;
}

function canFinalizeRequirement(task: TaskListRow) {
    return task.can_finalize_requirement === true;
}

function canFinalizeRequirementPack(group: { batch: TaskListRow['batch']; tasks: TaskListRow[] }) {
    if (!group.batch?.id) return false;
    if (group.batch.requirement_items <= 0) return false;
    if (!props.finalizeRequirementBatch) return false;
    if (typeof group.batch.can_finalize_requirement === 'boolean') {
        return group.batch.can_finalize_requirement;
    }

    const openRequirements = group.tasks.filter((task) => !isFinalizedRequirement(task));

    return openRequirements.length > 0 && openRequirements.every(canFinalizeRequirement);
}

function requirementPackId(group: { batch: TaskListRow['batch'] }) {
    return group.batch?.id ?? null;
}

function isRequirementPackFinalizeLoading(group: { batch: TaskListRow['batch'] }) {
    const batchId = requirementPackId(group);

    return batchId !== null && props.requirementBatchFinalizeLoading === batchId;
}

function requirementPackFinalizeLabel(group: { batch: TaskListRow['batch'] }) {
    return isRequirementPackFinalizeLoading(group) ? 'Finalizing...' : 'Finalize pack';
}

async function finalizeRequirementPack(group: { batch: TaskListRow['batch'] }) {
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
                    :draft-sort-by="draftSortBy"
                    :project-options="projectOptions"
                    :status-options="statusOptions"
                    :priority-options="priorityOptions"
                    :sort-by-options="sortByOptions"
                    :set-open="setFiltersOpen"
                    :set-draft-statuses="setDraftStatuses"
                    :set-draft-priorities="setDraftPriorities"
                    :set-draft-search-term="setDraftSearchTerm"
                    :set-draft-environment-term="setDraftEnvironmentTerm"
                    :set-draft-project-id="setDraftProjectId"
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
            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ itemLabel === 'requirements' ? 'Requirement' : 'Task' }}</TableHead>
                        <TableHead>Status</TableHead>
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
                                            v-if="canFinalizeRequirementPack(group)"
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
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            class="text-card-foreground hover:text-primary focus-visible:ring-ring rounded-sm text-left font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                            :aria-label="`Open task details for ${task.title}`"
                                            :data-testid="`task-title-${task.id}`"
                                            @click="openEdit(task.id)"
                                        >
                                            {{ task.title }}
                                        </button>
                                        <Badge v-if="taskProjectLabel(task)" :data-testid="`task-project-badge-${task.id}`" variant="secondary">
                                            {{ taskProjectLabel(task) }}
                                        </Badge>
                                        <Badge
                                            v-if="isFinalizedRequirement(task)"
                                            class="border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-100"
                                            :data-testid="`requirement-finalized-badge-${task.id}`"
                                            variant="outline"
                                        >
                                            Finalized
                                        </Badge>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :class="getStatusBadgeClass(task.status)" :data-testid="`task-status-badge-${task.id}`" variant="outline">
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
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        class="text-card-foreground hover:text-primary focus-visible:ring-ring rounded-sm text-left font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                        :aria-label="`Open task details for ${task.title}`"
                                        :data-testid="`task-title-${task.id}`"
                                        @click="openEdit(task.id)"
                                    >
                                        {{ task.title }}
                                    </button>
                                    <Badge v-if="taskProjectLabel(task)" :data-testid="`task-project-badge-${task.id}`" variant="secondary">
                                        {{ taskProjectLabel(task) }}
                                    </Badge>
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge :class="getStatusBadgeClass(task.status)" :data-testid="`task-status-badge-${task.id}`" variant="outline">
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
