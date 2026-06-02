<script lang="ts" setup>
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ActionIconButton from '@shared/components/ActionIconButton.vue';
import { getPriorityBadgeClass, getPriorityLabel, getStatusBadgeClass, getStatusLabel } from '@shared/tasks/presentation';
import { Eye, Trash2 } from 'lucide-vue-next';
import TaskListFiltersSheet from './TaskListFiltersSheet.vue';

type Option = {
    value: string;
    label: string;
};

type TaskListRow = {
    id: number;
    title: string;
    status: string;
    priority: string;
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
    draftSortBy: string;
    statusOptions: Option[];
    priorityOptions: Option[];
    sortByOptions: Option[];
    title?: string;
    description?: string;
    emptyLabel?: string;
    getTaskEnvironmentLabel: (task: TaskListRow) => string;
    setFiltersOpen: (value: boolean) => void;
    setDraftStatuses: (value: string[]) => void;
    setDraftPriorities: (value: string[]) => void;
    setDraftSearchTerm: (value: string) => void;
    setDraftEnvironmentTerm: (value: string) => void;
    setDraftSortBy: (value: string) => void;
    resetFilters: () => void;
    applyFilters: () => void;
    selectAllStatuses: () => void;
    selectAllPriorities: () => void;
    openEdit: (taskId: number) => void | Promise<void>;
    deleteTask: (taskId: number) => void | Promise<void>;
    goToPage: (page: number) => void;
}

withDefaults(defineProps<Props>(), {
    title: 'Tasks',
    description: 'Default view hides completed and closed tasks.',
    emptyLabel: 'No tasks found',
    loading: false,
    error: null,
    deleteLoading: null,
});
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
                    :draft-sort-by="draftSortBy"
                    :status-options="statusOptions"
                    :priority-options="priorityOptions"
                    :sort-by-options="sortByOptions"
                    :set-open="setFiltersOpen"
                    :set-draft-statuses="setDraftStatuses"
                    :set-draft-priorities="setDraftPriorities"
                    :set-draft-search-term="setDraftSearchTerm"
                    :set-draft-environment-term="setDraftEnvironmentTerm"
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
                <span>Showing {{ from }} to {{ to }} of {{ totalTasks }} tasks</span>
                <span v-if="activeFilterCount">{{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active</span>
            </div>

            <div v-if="loading" class="text-muted-foreground py-8 text-center">Loading...</div>
            <div v-else-if="error" class="text-destructive py-8 text-center">{{ error }}</div>
            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Task</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Priority</TableHead>
                        <TableHead>Environment</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="tasks.length === 0" :colspan="5">{{ emptyLabel }}</TableEmpty>
                    <template v-else>
                        <TableRow
                            v-for="task in tasks"
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
