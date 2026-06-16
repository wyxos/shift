<script setup lang="ts">
import TaskCreateSheet from '@/components/tasks/TaskCreateSheet.vue';
import type { TaskProjectOption } from '@/shared/tasks/projects';
import type { RequirementBatchSummary } from '@/shared/tasks/types';
import ConfirmRequestDialog from '@shared/components/ConfirmRequestDialog.vue';
import TaskListOverviewPanel from '@shared/components/tasks/TaskListOverviewPanel.vue';
import { getTaskEnvironment } from '@shared/tasks/metadata';
import { computed, ref, unref } from 'vue';

const props = defineProps<{
    filters: any;
    projects?: TaskProjectOption[];
    state: any;
    surface?: 'tasks' | 'requirements';
}>();

type PendingDelete = {
    id: number;
    title: string;
};

type PendingRequirementBatch = RequirementBatchSummary & {
    title?: string | null;
};

const pendingDelete = ref<PendingDelete | null>(null);
const pendingRequirementBatch = ref<PendingRequirementBatch | null>(null);
const deleteConfirmLoading = ref(false);
const deleteConfirmError = ref<string | null>(null);
const requirementBatchConfirmLoading = ref(false);
const requirementBatchConfirmError = ref<string | null>(null);
const deleteDialogOpen = computed({
    get: () => pendingDelete.value !== null,
    set: (open: boolean) => {
        if (deleteConfirmLoading.value) return;
        if (!open) pendingDelete.value = null;
    },
});
const requirementBatchDialogOpen = computed({
    get: () => pendingRequirementBatch.value !== null,
    set: (open: boolean) => {
        if (requirementBatchConfirmLoading.value) return;
        if (!open) pendingRequirementBatch.value = null;
    },
});
const taskRows = computed(() => {
    const rows = unref(props.state.taskRows);

    return Array.isArray(rows) ? rows : [];
});
const createProjects = computed(() => (props.projects ?? []).filter((project) => project.can_create_task !== false));
const projectFilterOptions = computed(() => (props.projects ?? []).map((project) => ({ value: String(project.id), label: project.name })));
const deleteNoun = computed(() => (props.surface === 'requirements' ? 'requirement' : 'task'));
const pendingRequirementBatchTitle = computed(() => pendingRequirementBatch.value?.title || 'these requirements');
const pendingRequirementBatchCount = computed(
    () => pendingRequirementBatch.value?.ready_items ?? pendingRequirementBatch.value?.requirement_items ?? 0,
);

function requestErrorMessage(error: unknown, fallback: string) {
    return error instanceof Error && error.message ? error.message : fallback;
}

function findTask(taskId: number) {
    return taskRows.value.find((task) => task.id === taskId) ?? null;
}

function findRequirementBatch(batchId: number) {
    return taskRows.value.map((task) => task.batch).find((batch) => batch?.id === batchId) ?? null;
}

function requestDeleteTask(taskId: number) {
    const task = findTask(taskId);
    deleteConfirmLoading.value = false;
    deleteConfirmError.value = null;
    pendingDelete.value = {
        id: taskId,
        title: task?.title ?? `${deleteNoun.value} #${taskId}`,
    };
}

function requestRequirementBatchFinalize(batchId: number) {
    const batch = findRequirementBatch(batchId);

    requirementBatchConfirmLoading.value = false;
    requirementBatchConfirmError.value = null;
    pendingRequirementBatch.value = {
        id: batchId,
        title: batch?.title ?? null,
        created_at: batch?.created_at ?? null,
        total_items: batch?.total_items ?? 0,
        requirement_items: batch?.requirement_items ?? 0,
        ready_items: batch?.ready_items ?? 0,
        finalized_items: batch?.finalized_items ?? 0,
    };
}

async function confirmDeleteTask() {
    const task = pendingDelete.value;
    if (!task || deleteConfirmLoading.value) return;

    deleteConfirmLoading.value = true;
    deleteConfirmError.value = null;

    let deleted;
    try {
        deleted = await props.state.deleteTask(task.id);
    } catch (error) {
        deleteConfirmError.value = unref(props.state.error) || requestErrorMessage(error, `Unable to delete this ${deleteNoun.value} right now.`);
        deleteConfirmLoading.value = false;
        return;
    }

    if (deleted === false) {
        deleteConfirmError.value = unref(props.state.error) || `Unable to delete this ${deleteNoun.value} right now.`;
        deleteConfirmLoading.value = false;
        return;
    }

    pendingDelete.value = null;
}

async function confirmRequirementBatchFinalize() {
    const batch = pendingRequirementBatch.value;
    if (!batch || requirementBatchConfirmLoading.value) return;

    requirementBatchConfirmLoading.value = true;
    requirementBatchConfirmError.value = null;

    let finalized;
    try {
        finalized = await props.state.finalizeRequirementBatch(batch.id);
    } catch (error) {
        requirementBatchConfirmError.value =
            unref(props.state.error) || requestErrorMessage(error, 'Unable to finalize these requirements right now.');
        requirementBatchConfirmLoading.value = false;
        return;
    }

    if (finalized === false) {
        requirementBatchConfirmError.value = unref(props.state.error) || 'Unable to finalize these requirements right now.';
        requirementBatchConfirmLoading.value = false;
        return;
    }

    pendingRequirementBatch.value = null;
}
</script>

<template>
    <TaskListOverviewPanel
        :tasks="state.taskRows"
        :title="surface === 'requirements' ? 'Requirements' : 'Tasks'"
        :description="
            surface === 'requirements'
                ? 'Review submitted requirement items before they become active tasks.'
                : 'Default view hides completed and closed tasks.'
        "
        :empty-label="surface === 'requirements' ? 'No requirements found' : 'No tasks found'"
        :item-label="surface === 'requirements' ? 'requirements' : 'tasks'"
        :total-tasks="state.tasksPage.total"
        :loading="state.loading"
        :error="state.error"
        :delete-loading="state.deleteLoading"
        :requirement-batch-finalize-loading="state.requirementBatchFinalizeLoading"
        :current-page="state.tasksPage.current_page"
        :last-page="state.tasksPage.last_page"
        :from="state.tasksPage.from ?? 0"
        :to="state.tasksPage.to ?? 0"
        :highlighted-task-id="state.highlightedTaskId"
        :filters-open="filters.filtersOpen"
        :active-filter-count="filters.activeFilterCount"
        :draft-statuses="filters.draftStatuses"
        :draft-priorities="filters.draftPriorities"
        :draft-search-term="filters.draftSearchTerm"
        :draft-environment-term="filters.draftEnvironmentTerm"
        :draft-project-id="filters.draftProjectId"
        :draft-sort-by="filters.draftSortBy"
        :project-options="projectFilterOptions"
        :status-options="filters.statusOptions"
        :priority-options="filters.priorityOptions"
        :sort-by-options="filters.sortByOptions"
        :get-task-environment-label="(task) => getTaskEnvironment(task) ?? 'Unknown'"
        :set-filters-open="filters.setFiltersOpen"
        :set-draft-statuses="filters.setDraftStatuses"
        :set-draft-priorities="filters.setDraftPriorities"
        :set-draft-search-term="filters.setDraftSearchTerm"
        :set-draft-environment-term="filters.setDraftEnvironmentTerm"
        :set-draft-project-id="filters.setDraftProjectId"
        :set-draft-sort-by="filters.setDraftSortBy"
        :reset-filters="filters.resetFilters"
        :apply-filters="filters.applyFilters"
        :select-all-statuses="filters.selectAllStatuses"
        :select-all-priorities="filters.selectAllPriorities"
        :open-edit="state.openEdit"
        :delete-task="requestDeleteTask"
        :finalize-requirement-batch="surface === 'requirements' ? requestRequirementBatchFinalize : undefined"
        :go-to-page="state.goToPage"
    >
        <template #actions>
            <TaskCreateSheet v-if="createProjects.length > 0" :projects="createProjects" :surface="surface" @created="state.handleTaskCreated" />
        </template>
    </TaskListOverviewPanel>

    <ConfirmRequestDialog
        v-model:open="requirementBatchDialogOpen"
        confirm-label="Finalize"
        confirm-test-id="confirm-requirement-pack-finalize"
        :error="requirementBatchConfirmError"
        :loading="requirementBatchConfirmLoading"
        loading-label="Finalizing..."
        title="Finalize requirements"
        @confirm="confirmRequirementBatchFinalize"
    >
        Finalize all {{ pendingRequirementBatchCount }} ready {{ pendingRequirementBatchCount === 1 ? 'requirement' : 'requirements' }} in
        {{ pendingRequirementBatchTitle }} as active tasks.
    </ConfirmRequestDialog>

    <ConfirmRequestDialog
        v-model:open="deleteDialogOpen"
        confirm-label="Delete"
        confirm-test-id="confirm-task-delete"
        confirm-variant="destructive"
        :error="deleteConfirmError"
        :loading="deleteConfirmLoading"
        loading-label="Deleting..."
        :title="`Delete ${deleteNoun}`"
        @confirm="confirmDeleteTask"
    >
        Delete {{ pendingDelete?.title ?? `this ${deleteNoun}` }}? This action cannot be undone.
    </ConfirmRequestDialog>
</template>
