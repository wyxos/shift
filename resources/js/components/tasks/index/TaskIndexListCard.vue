<script setup lang="ts">
import TaskCreateSheet from '@/components/tasks/TaskCreateSheet.vue';
import type { TaskProjectOption } from '@/shared/tasks/projects';
import TaskListOverviewPanel from '@shared/components/tasks/TaskListOverviewPanel.vue';
import { getTaskEnvironment } from '@shared/tasks/metadata';

defineProps<{
    filters: any;
    projects?: TaskProjectOption[];
    state: any;
}>();
</script>

<template>
    <TaskListOverviewPanel
        :tasks="state.taskRows"
        :total-tasks="state.tasksPage.total"
        :loading="state.loading"
        :error="state.error"
        :delete-loading="state.deleteLoading"
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
        :draft-sort-by="filters.draftSortBy"
        :status-options="filters.statusOptions"
        :priority-options="filters.priorityOptions"
        :sort-by-options="filters.sortByOptions"
        :get-task-environment-label="(task) => getTaskEnvironment(task) ?? 'Unknown'"
        :set-filters-open="filters.setFiltersOpen"
        :set-draft-statuses="filters.setDraftStatuses"
        :set-draft-priorities="filters.setDraftPriorities"
        :set-draft-search-term="filters.setDraftSearchTerm"
        :set-draft-environment-term="filters.setDraftEnvironmentTerm"
        :set-draft-sort-by="filters.setDraftSortBy"
        :reset-filters="filters.resetFilters"
        :apply-filters="filters.applyFilters"
        :select-all-statuses="filters.selectAllStatuses"
        :select-all-priorities="filters.selectAllPriorities"
        :open-edit="state.openEdit"
        :delete-task="state.deleteTask"
        :go-to-page="state.goToPage"
    >
        <template #actions>
            <TaskCreateSheet :projects="projects" @created="state.handleTaskCreated" />
        </template>
    </TaskListOverviewPanel>
</template>
