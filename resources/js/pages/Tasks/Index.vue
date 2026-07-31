<script lang="ts" setup>
/* eslint-disable @typescript-eslint/no-unused-vars */
import TaskEditSheet from '@/components/tasks/index/TaskEditSheet.vue';
import TaskIndexListCard from '@/components/tasks/index/TaskIndexListCard.vue';
import { useTaskIndexEditState } from '@/composables/useTaskIndexEditState';
import { useTaskIndexFilters } from '@/composables/useTaskIndexFilters';
import { useTaskIndexListState } from '@/composables/useTaskIndexListState';
import AppLayout from '@/layouts/AppLayout.vue';
import type { TaskProjectOption } from '@/shared/tasks/projects';
import type { TaskIndexFilters, TaskIndexSurface, TaskPaginator } from '@/shared/tasks/types';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, reactive, toRef, toRefs } from 'vue';

const props = withDefaults(
    defineProps<{
        tasks: TaskPaginator;
        projects?: TaskProjectOption[];
        filters: TaskIndexFilters;
        surface?: TaskIndexSurface;
    }>(),
    {
        projects: () => [],
        surface: 'tasks',
    },
);

const indexHref = computed(() => {
    const organisationId = props.filters.organisation_id;
    const surfacePath = props.surface;

    return organisationId ? `/organisation/${organisationId}/${surfacePath}` : `/${surfacePath}`;
});
const surfaceLabel = computed(() => {
    if (props.surface === 'app-errors') return 'App errors';
    if (props.surface === 'requirements') return 'Requirements';

    return 'Tasks';
});
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: surfaceLabel.value, href: indexHref.value },
    { title: surfaceLabel.value, href: indexHref.value },
]);

const filtersState = useTaskIndexFilters({ filters: props.filters, surface: props.surface });
const listState = useTaskIndexListState({
    tasks: toRef(props, 'tasks'),
    buildListQuery: filtersState.buildListQuery,
    indexPath: filtersState.indexPath,
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
const setDraftProjectId = (value: string) => {
    filtersState.draftProjectId.value = value;
};
const setDraftType = (value: string) => {
    filtersState.draftType.value = value;
};
const setDraftSortBy = (value: string) => {
    filtersState.draftSortBy.value = value;
};

const setEditField = (field: 'title' | 'priority' | 'status' | 'requirement_status' | 'description', value: string) => {
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
    setDraftProjectId,
    setDraftPriorities,
    setDraftSearchTerm,
    setDraftSortBy,
    setDraftStatuses,
    setDraftType,
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
    draftProjectId,
    draftPriorities,
    draftSearchTerm,
    draftSortBy,
    draftStatuses,
    draftType,
    filtersOpen,
    includeTypeFilter,
    priorityOptions,
    resetFilters,
    selectAllPriorities,
    selectAllStatuses,
    sortByOptions,
    statusOptions,
    syncAppliedToDraft,
    typeOptions,
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
    handleMentionQuery,
    handleSlashCommand,
    handleThreadSend,
    hasUnsavedChanges,
    hasUnsavedCommentDraft,
    hasUnsavedTaskChanges,
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
    setThreadAudience,
    taskAttachments,
    taskSaveError,
    taskSaving,
    threadAiContext,
    threadAudience,
    threadAudienceError,
    threadComposerHtml,
    threadComposerRef,
    threadComposerUploading,
    threadEditError,
    threadEditSaving,
    threadEditingId,
    threadError,
    threadLoading,
    threadMentionCandidates,
    threadMentionError,
    threadMentionLoading,
    threadMessages,
    threadSending,
    threadTempIdentifier,
    updateEditCollaborators,
} = toRefs(combined as any);
</script>

<template>
    <Head :title="surfaceLabel" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <TaskIndexListCard :filters="filtersModel" :projects="props.projects" :state="combined" :surface="props.surface" />
            <TaskEditSheet :state="combined" />
        </div>
    </AppLayout>
</template>
