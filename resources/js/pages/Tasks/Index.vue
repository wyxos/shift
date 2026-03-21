<script lang="ts" setup>
/* eslint-disable @typescript-eslint/no-unused-vars */
import AppLayout from '@/layouts/AppLayout.vue';
import TaskEditSheet from '@/components/tasks/index/TaskEditSheet.vue';
import TaskIndexListCard from '@/components/tasks/index/TaskIndexListCard.vue';
import { useTaskIndexEditState } from '@/composables/useTaskIndexEditState';
import { useTaskIndexFilters } from '@/composables/useTaskIndexFilters';
import { useTaskIndexListState } from '@/composables/useTaskIndexListState';
import type { TaskIndexFilters, TaskPaginator, TaskProjectOption } from '@/shared/tasks/types';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { reactive, toRefs } from 'vue';

const props = withDefaults(
    defineProps<{
        tasks: TaskPaginator;
        projects?: TaskProjectOption[];
        filters: TaskIndexFilters;
    }>(),
    {
        projects: () => [],
    },
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/tasks' },
    { title: 'Tasks', href: '/tasks' },
];

const filtersState = useTaskIndexFilters({ filters: props.filters });
const listState = useTaskIndexListState({
    tasks: props.tasks,
    buildListQuery: filtersState.buildListQuery,
});
const editState = useTaskIndexEditState({
    projects: props.projects,
    aiImproveEnabled: listState.aiImproveEnabled,
    onTaskSaved: (taskId) => listState.handleTaskCreated(taskId),
});

const setFiltersOpen = (open: boolean) => {
    filtersState.filtersOpen.value = open;
};
const setDraftSearchTerm = (value: string) => {
    filtersState.draftSearchTerm.value = value;
};
const setDraftStatuses = (value: string[]) => {
    filtersState.draftStatuses.value = value;
};
const setDraftPriorities = (value: string[]) => {
    filtersState.draftPriorities.value = value;
};
const setDraftEnvironmentTerm = (value: string) => {
    filtersState.draftEnvironmentTerm.value = value;
};
const setDraftSortBy = (value: string) => {
    filtersState.draftSortBy.value = value;
};

const setEditField = (field: 'title' | 'priority' | 'status' | 'description', value: string) => {
    editState.editForm.value = {
        ...editState.editForm.value,
        [field]: value,
    };
};
const setEditMobilePane = (pane: 'details' | 'comments') => {
    editState.editMobilePane.value = pane;
};
const setConfirmCloseOpen = (open: boolean) => {
    editState.confirmCloseOpen.value = open;
};
const setThreadComposerHtml = (value: string) => {
    editState.threadComposerHtml.value = value;
};
const setThreadComposerUploading = (uploading: boolean) => {
    editState.threadComposerUploading.value = uploading;
};

const closeEditNow = editState.closeEditNow;
const onGlobalClickCapture = editState.onGlobalClickCapture;
const onGlobalDblClickCapture = editState.onGlobalDblClickCapture;
const onGlobalKeyDownCapture = editState.onGlobalKeyDownCapture;

const filtersModel = reactive({
    ...filtersState,
    setDraftEnvironmentTerm,
    setDraftPriorities,
    setDraftSearchTerm,
    setDraftSortBy,
    setDraftStatuses,
    setFiltersOpen,
});
const combined = reactive({
    ...listState,
    ...editState,
    setConfirmCloseOpen,
    setEditField,
    setEditMobilePane,
    setThreadComposerHtml,
    setThreadComposerUploading,
});

const {
    activeFilterCount,
    appliedEnvironmentTerm,
    appliedPriorities,
    appliedSearchTerm,
    appliedSortBy,
    appliedStatuses,
    applyFilters,
    buildListQuery,
    defaultSortBy,
    draftEnvironmentTerm,
    draftPriorities,
    draftSearchTerm,
    draftSortBy,
    draftStatuses,
    filtersOpen,
    priorityOptions,
    resetFilters,
    selectAllPriorities,
    selectAllStatuses,
    sortByOptions,
    statusOptions,
    syncAppliedToDraft,
} = toRefs(filtersModel as any);

const {
    aiImproveEnabled,
    attemptCloseEdit,
    canManageCollaborators,
    cancelThreadEdit,
    commentsScrollRef,
    confirmCloseOpen,
    contextMenuMessageId,
    contextMenuSelectionText,
    copyEntireMessage,
    copySelectedMessage,
    deleteThreadMessage,
    deletedAttachmentIds,
    discardChangesAndClose,
    editError,
    editForm,
    editLoading,
    editMobilePane,
    editMobilePaneOptions,
    editOpen,
    editTask,
    editTaskCreatorLabel,
    editTaskEnvironmentLabel,
    editTempIdentifier,
    editUploading,
    fetchThreads,
    handleReplyReferenceClick,
    handleThreadSend,
    hasUnsavedChanges,
    hasUnsavedCommentDraft,
    hasUnsavedTaskChanges,
    isOwner,
    lastTouchTapAt,
    lastTouchTapId,
    lightboxAlt,
    lightboxOpen,
    lightboxSrc,
    onCommentContextMenuOpen,
    onCommentsMediaLoadCapture,
    onEditOpenChange,
    onMessageDblClick,
    onMessageTouchEnd,
    onRichContentClick,
    openEdit,
    pendingTaskSave,
    removeAttachmentFromTask,
    resetThreadState,
    saveTaskChanges,
    scrollCommentsToBottomSoon,
    shouldShowCopySelection,
    startReplyToMessage,
    startThreadEdit,
    taskAttachments,
    taskSaveError,
    taskSaving,
    threadAiContext,
    threadComposerHtml,
    threadComposerRef,
    threadComposerUploading,
    threadEditError,
    threadEditSaving,
    threadEditingId,
    threadError,
    threadLoading,
    threadMessages,
    threadSending,
    threadTempIdentifier,
    updateEditCollaborators,
} = toRefs(combined as any);
</script>

<template>
    <Head title="Tasks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <TaskIndexListCard :edit-state="combined" :filters="filtersModel" :projects="props.projects" :state="combined" />
            <TaskEditSheet :state="combined" />
        </div>
    </AppLayout>
</template>
