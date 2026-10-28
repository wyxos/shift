import { collaboratorsEqual, normalizeTaskCollaborators, type TaskCollaboratorSelection } from '@/shared/tasks/collaborators';
import { getTaskIdFromQuery, syncTaskQuery } from '@/shared/tasks/history';
import { getTaskCreatorEmail, getTaskCreatorName, getTaskEnvironment } from '@/shared/tasks/metadata';
import { projectEnvironmentOptions, type TaskProjectOption } from '@/shared/tasks/projects';
import type { TaskDetail, TaskIndexEditSnapshot, TaskIndexOpenEditOptions } from '@/shared/tasks/types';
import { createUploadIdentifier } from '@shared/lib/uploadIdentifier';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch, type ComputedRef } from 'vue';
import { toast } from 'vue-sonner';
import { defaultTaskEditForm, editMobilePaneOptions } from './taskIndexEditDefaults';
import { currentTaskSnapshot, hasCollaboratorManagementChanges, hasPendingTaskChanges, withTaskSnapshot } from './taskIndexEditSnapshot';
import { taskIndexThreadBindings } from './taskIndexThreadBindings';
import { useTaskErrorOccurrences } from './useTaskErrorOccurrences';
import { useTaskIndexThreadState } from './useTaskIndexThreadState';
import { useTaskSaveToast } from './useTaskSaveToast';
type UseTaskIndexEditStateOptions = {
    projects: TaskProjectOption[];
    aiImproveEnabled: ComputedRef<boolean>;
    onTaskSaved: (taskId: number) => void;
};
export function useTaskIndexEditState(options: UseTaskIndexEditStateOptions) {
    const editOpen = ref(false);
    const editLoading = ref(false);
    const editError = ref<string | null>(null);
    const editUploading = ref(false);
    const editTask = ref<TaskDetail | null>(null);
    const deletedAttachmentIds = ref<number[]>([]);
    const editTempIdentifier = ref(createUploadIdentifier());
    const editForm = ref(defaultTaskEditForm());
    const confirmCloseOpen = ref(false);
    const initialEditSnapshot = ref<TaskIndexEditSnapshot | null>(null);
    const editMobilePane = ref<'details' | 'comments'>('details');
    const taskSaving = ref(false);
    const taskSaveError = ref<string | null>(null);
    const taskSaved = ref(false);
    const pendingTaskSave = ref(false);
    let editSessionVersion = 0;
    const requirementFinalizing = ref(false);
    const requirementFinalizeError = ref<string | null>(null);
    const autosaveArmed = ref(false);
    let taskAutosaveTimer: number | null = null;
    const errorOccurrenceState = useTaskErrorOccurrences();
    const taskSaveToast = useTaskSaveToast();
    const thread = useTaskIndexThreadState({
        aiImproveEnabled: options.aiImproveEnabled,
        editOpen,
        editTask,
    });
    const isRequirementPhase = computed(() => editTask.value?.phase === 'requirement');
    const isErrorIntakeTask = computed(() => Boolean(editTask.value?.error_signature));
    const canComment = computed(() => editTask.value?.can_comment !== false);
    const canEditTaskScope = computed(() => {
        if (!editTask.value) return false;

        return isRequirementPhase.value ? editTask.value.can_edit_requirement === true : editTask.value.can_edit_task === true;
    });
    const canFinalizeRequirement = computed(() => editTask.value?.can_finalize_requirement === true);
    const canManageCollaborators = computed(() => Boolean(editTask.value?.can_manage_collaborators));
    const editTaskCreatorLabel = computed(() => getTaskCreatorName(editTask.value) ?? getTaskCreatorEmail(editTask.value) ?? 'Unknown');
    const editTaskProjectUsersLabel = computed(() => {
        const projectId = editTask.value?.project_id ?? null;
        const projectName = projectId === null ? null : (options.projects.find((project) => project.id === projectId)?.name ?? null);

        return projectName ? `${projectName} users` : 'Project users';
    });
    const editTaskEnvironmentLabel = computed(() => {
        const projectId = editTask.value?.project_id ?? null;
        const selectedEnvironment = editForm.value.environment;
        if (projectId !== null && selectedEnvironment) {
            return (
                projectEnvironmentOptions(options.projects, projectId).find((environment) => environment.key === selectedEnvironment)?.label ??
                selectedEnvironment
            );
        }
        return getTaskEnvironment(editTask.value) ?? 'N/A';
    });
    const taskAttachments = computed(() => {
        if (!editTask.value?.attachments) return [];
        const removed = new Set(deletedAttachmentIds.value);
        return editTask.value.attachments.filter((attachment) => !removed.has(attachment.id));
    });
    const hasUnsavedTaskChanges = computed(() => {
        if (!editOpen.value) return false;
        if (taskSaving.value) return true;
        return hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value);
    });
    const hasUnsavedCommentDraft = computed(() => {
        if (!editOpen.value) return false;
        if (thread.threadEditingId.value) return true;
        if (thread.threadComposerHtml.value.trim()) return true;
        return false;
    });
    const hasUnsavedChanges = computed(() => hasUnsavedTaskChanges.value || hasUnsavedCommentDraft.value);
    const taskSaveState = computed<'idle' | 'pending' | 'saving' | 'saved' | 'error'>(() => {
        if (!editOpen.value || !editTask.value) return 'idle';
        if (taskSaveError.value && hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value)) return 'error';
        if (taskSaving.value) return 'saving';
        if (hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value)) return 'pending';
        return taskSaved.value ? 'saved' : 'idle';
    });
    function onTaskQueryPopState() {
        const taskId = getTaskIdFromQuery();
        const currentTaskId = editTask.value?.id ?? null;
        if (taskId === null) {
            if (editOpen.value) closeEditNow(false);
            return;
        }

        if (editOpen.value && currentTaskId === taskId) return;
        void openEdit(taskId, { updateHistory: false });
    }
    watch(
        () => [
            editForm.value.title,
            editForm.value.priority,
            editForm.value.status,
            editForm.value.requirement_status,
            editForm.value.description,
            editForm.value.environment,
            JSON.stringify(editForm.value.collaborators),
            deletedAttachmentIds.value.join(','),
        ],
        () => {
            if (!editOpen.value || !autosaveArmed.value) return;
            if (!hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value)) {
                if (!taskSaving.value) taskSaveError.value = null;
                return;
            }
            scheduleTaskAutosave();
        },
    );
    watch(editMobilePane, (pane) => {
        if (!editOpen.value || pane !== 'comments') return;
        thread.scrollCommentsToBottomSoon();
    });
    function mergeEditedTask(task: Partial<TaskDetail> | null | undefined, submittedSnapshot?: TaskIndexEditSnapshot, syncEditableFields = true) {
        if (!editTask.value || !task) return;
        editTask.value = {
            ...editTask.value,
            ...task,
            attachments: Array.isArray(task.attachments) ? task.attachments : editTask.value.attachments,
        };
        if (
            syncEditableFields &&
            Object.prototype.hasOwnProperty.call(task, 'environment') &&
            (!submittedSnapshot || editForm.value.environment === submittedSnapshot.environment)
        ) {
            editForm.value.environment = task.environment ?? null;
        }
        if (
            syncEditableFields &&
            (task.internal_collaborators || task.external_collaborators) &&
            (!submittedSnapshot || collaboratorsEqual(editForm.value.collaborators, submittedSnapshot.collaborators))
        ) {
            editForm.value.collaborators = normalizeTaskCollaborators({
                internal: Array.isArray(task.internal_collaborators) ? task.internal_collaborators : editForm.value.collaborators.internal,
                external: Array.isArray(task.external_collaborators) ? task.external_collaborators : editForm.value.collaborators.external,
            });
        }
    }
    function scheduleTaskAutosave(immediate = false) {
        if (!autosaveArmed.value || !editTask.value) return;
        if (taskSaving.value) {
            pendingTaskSave.value = true;
            return;
        }
        if (taskAutosaveTimer) {
            window.clearTimeout(taskAutosaveTimer);
            taskAutosaveTimer = null;
        }
        if (immediate) {
            void saveTaskChanges();
            return;
        }
        taskAutosaveTimer = window.setTimeout(() => {
            taskAutosaveTimer = null;
            void saveTaskChanges();
        }, 650);
    }
    async function saveTaskChanges() {
        if (!editTask.value) return;
        if (taskSaving.value) {
            pendingTaskSave.value = true;
            return;
        }
        if (taskAutosaveTimer) {
            window.clearTimeout(taskAutosaveTimer);
            taskAutosaveTimer = null;
        }
        if (!hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value)) return;
        const snapshot = initialEditSnapshot.value;
        if (!snapshot) return;
        const submittedSnapshot = currentTaskSnapshot(editForm.value);
        const submittedDeletedAttachmentIds = [...deletedAttachmentIds.value];
        const sessionVersion = editSessionVersion;
        const taskId = editTask.value.id;
        const taskEnvironment = submittedSnapshot.environment ?? editTask.value.environment;
        const needsCollaboratorUpdate = canManageCollaborators.value && hasCollaboratorManagementChanges(editForm.value, snapshot);
        const needsCoreUpdate = canEditTaskScope.value
            ? editForm.value.title !== snapshot.title ||
              editForm.value.priority !== snapshot.priority ||
              editForm.value.status !== snapshot.status ||
              editForm.value.requirement_status !== snapshot.requirement_status ||
              (editForm.value.description ?? '') !== (snapshot.description ?? '') ||
              submittedDeletedAttachmentIds.length > 0
            : false;

        if (!needsCoreUpdate && !needsCollaboratorUpdate) return;

        const collaboratorPayload = needsCollaboratorUpdate
            ? {
                  internal_collaborator_ids: submittedSnapshot.collaborators.internal.map((collaborator) => Number(collaborator.id)),
                  external_collaborators: submittedSnapshot.collaborators.external.map((collaborator) => ({
                      id: collaborator.id,
                      name: collaborator.name,
                      email: collaborator.email,
                      environment: collaborator.environment ?? taskEnvironment ?? undefined,
                  })),
              }
            : null;

        taskSaving.value = true;
        taskSaveError.value = null;
        taskSaved.value = false;
        taskSaveToast.showTaskSavingToast();

        try {
            if (needsCoreUpdate) {
                const payload = {
                    title: submittedSnapshot.title,
                    description: submittedSnapshot.description,
                    priority: submittedSnapshot.priority,
                    status: submittedSnapshot.status,
                    requirement_status: submittedSnapshot.requirement_status,
                    temp_identifier: editTempIdentifier.value,
                    deleted_attachment_ids: submittedDeletedAttachmentIds.length ? submittedDeletedAttachmentIds : undefined,
                };

                const response = await axios.put(route('tasks.update', { task: taskId }), payload);
                if (sessionVersion !== editSessionVersion) return;
                mergeEditedTask(response.data?.task ?? null, submittedSnapshot, false);
            }

            if (needsCollaboratorUpdate && collaboratorPayload) {
                const response = await axios.patch(route('tasks.collaborators.update', { task: taskId }), collaboratorPayload);
                if (sessionVersion !== editSessionVersion) return;
                mergeEditedTask(response.data?.task ?? null, submittedSnapshot);
            }

            deletedAttachmentIds.value = deletedAttachmentIds.value.filter((id) => !submittedDeletedAttachmentIds.includes(id));
            initialEditSnapshot.value = submittedSnapshot;
            taskSaved.value = true;
            if (editTask.value?.id === taskId) editTask.value = withTaskSnapshot(editTask.value, submittedSnapshot);
            options.onTaskSaved(taskId);
        } catch (e: any) {
            if (sessionVersion !== editSessionVersion) return;
            taskSaveError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to autosave task';
        } finally {
            if (sessionVersion !== editSessionVersion) return;
            taskSaving.value = false;
            const saveAgain = !taskSaveError.value && hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value);
            pendingTaskSave.value = false;
            if (saveAgain) {
                scheduleTaskAutosave(true);
                return;
            }
            taskSaveToast.showTaskSaveResultToast(!taskSaveError.value, taskSaveError.value ?? undefined);
        }
    }

    async function finalizeRequirement() {
        if (!editTask.value || !isRequirementPhase.value) return;
        if (!canFinalizeRequirement.value) return;

        requirementFinalizing.value = true;
        requirementFinalizeError.value = null;

        try {
            const response = await axios.patch(route('requirements.finalize', { task: editTask.value.id }), {
                title: editForm.value.title,
                description: editForm.value.description,
                requirement_status: editForm.value.requirement_status,
            });
            mergeEditedTask(response.data?.task ?? null);
            if (editTask.value) {
                editTask.value.phase = 'task';
                editTask.value.finalized = true;
            }
            initialEditSnapshot.value = currentTaskSnapshot(editForm.value);
            options.onTaskSaved(editTask.value.id);
            toast.success('Requirement finalized', {
                description: 'The item now appears in the active task list.',
            });
        } catch (e: any) {
            requirementFinalizeError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to finalize requirement';
            toast.error('Failed to finalize requirement', {
                description: requirementFinalizeError.value ?? undefined,
            });
        } finally {
            requirementFinalizing.value = false;
        }
    }

    async function openEdit(taskId: number, options: TaskIndexOpenEditOptions = {}) {
        const { updateHistory = true } = options;
        editSessionVersion += 1;

        if (taskAutosaveTimer) {
            window.clearTimeout(taskAutosaveTimer);
            taskAutosaveTimer = null;
        }

        editOpen.value = true;
        editLoading.value = true;
        editError.value = null;
        editTask.value = null;
        editUploading.value = false;
        taskSaving.value = false;
        taskSaveError.value = null;
        taskSaved.value = false;
        requirementFinalizing.value = false;
        requirementFinalizeError.value = null;
        pendingTaskSave.value = false;
        autosaveArmed.value = false;
        deletedAttachmentIds.value = [];
        initialEditSnapshot.value = null;
        editMobilePane.value = 'details';
        thread.resetThreadState();
        errorOccurrenceState.resetErrorOccurrences();

        if (updateHistory) {
            syncTaskQuery(taskId, 'push');
        }

        try {
            const response = await axios.get(route('tasks.show', { task: taskId }));
            const data = response.data as TaskDetail;
            editTask.value = data;
            editForm.value = {
                title: data?.title ?? '',
                priority: data?.priority ?? 'medium',
                status: data?.status ?? 'pending',
                requirement_status: data?.requirement_status ?? 'submitted',
                description: data?.description ?? '',
                environment: data?.environment ?? null,
                collaborators: normalizeTaskCollaborators({
                    internal: data?.internal_collaborators ?? [],
                    external: data?.external_collaborators ?? [],
                }),
            };
            editTempIdentifier.value = createUploadIdentifier();
            initialEditSnapshot.value = currentTaskSnapshot(editForm.value);
            autosaveArmed.value = true;
            void thread.fetchThreads(taskId);
            if (data?.error_signature) {
                void errorOccurrenceState.fetchErrorOccurrences(taskId);
            }
        } catch (e: any) {
            editError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to fetch task';
        } finally {
            editLoading.value = false;
        }
    }

    function closeEditNow(updateHistory = true) {
        editSessionVersion += 1;
        if (taskAutosaveTimer) {
            window.clearTimeout(taskAutosaveTimer);
            taskAutosaveTimer = null;
        }
        editOpen.value = false;
        editTask.value = null;
        editError.value = null;
        editUploading.value = false;
        deletedAttachmentIds.value = [];
        editForm.value = defaultTaskEditForm();
        initialEditSnapshot.value = null;
        editMobilePane.value = 'details';
        errorOccurrenceState.resetErrorOccurrences();
        taskSaving.value = false;
        taskSaveError.value = null;
        taskSaved.value = false;
        requirementFinalizing.value = false;
        requirementFinalizeError.value = null;
        pendingTaskSave.value = false;
        autosaveArmed.value = false;
        thread.resetThreadState();
        taskSaveToast.dismissTaskSaveToast();
        if (updateHistory) {
            syncTaskQuery(null, 'push');
        }
    }

    function attemptCloseEdit() {
        if (!hasUnsavedChanges.value) {
            closeEditNow();
            return;
        }
        confirmCloseOpen.value = true;
    }

    function discardChangesAndClose() {
        confirmCloseOpen.value = false;
        closeEditNow();
    }

    function onEditOpenChange(open: boolean) {
        if (open) {
            editOpen.value = true;
            return;
        }
        attemptCloseEdit();
    }

    function updateEditCollaborators(value: TaskCollaboratorSelection) {
        editForm.value.collaborators = normalizeTaskCollaborators(value);
        if (!editOpen.value || !autosaveArmed.value) return;
        if (!hasPendingTaskChanges(editForm.value, initialEditSnapshot.value, deletedAttachmentIds.value)) return;
        scheduleTaskAutosave();
    }

    function removeAttachmentFromTask(attachmentId: number) {
        if (!canEditTaskScope.value) return;
        if (!deletedAttachmentIds.value.includes(attachmentId)) {
            deletedAttachmentIds.value = [...deletedAttachmentIds.value, attachmentId];
            scheduleTaskAutosave(true);
        }
    }

    onMounted(() => {
        window.addEventListener('popstate', onTaskQueryPopState);

        const deepLinkedTaskId = getTaskIdFromQuery();
        if (deepLinkedTaskId !== null) {
            void openEdit(deepLinkedTaskId, { updateHistory: false });
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('popstate', onTaskQueryPopState);
        if (taskAutosaveTimer) {
            window.clearTimeout(taskAutosaveTimer);
            taskAutosaveTimer = null;
        }
        taskSaveToast.dismissTaskSaveToast();
    });

    return {
        aiImproveEnabled: options.aiImproveEnabled,
        attemptCloseEdit,
        activeErrorSection: errorOccurrenceState.activeErrorSection,
        canComment,
        canEditTaskScope,
        canFinalizeRequirement,
        canManageCollaborators,
        closeEditNow,
        confirmCloseOpen,
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
        editTaskProjectUsersLabel,
        editTempIdentifier,
        editUploading,
        errorOccurrences: errorOccurrenceState.errorOccurrences,
        errorOccurrencesError: errorOccurrenceState.errorOccurrencesError,
        errorOccurrencesLoading: errorOccurrenceState.errorOccurrencesLoading,
        errorOccurrencesPagination: errorOccurrenceState.errorOccurrencesPagination,
        fetchErrorOccurrences: errorOccurrenceState.fetchErrorOccurrences,
        hasUnsavedChanges,
        isErrorIntakeTask,
        isRequirementPhase,
        onEditOpenChange,
        openEdit,
        finalizeRequirement,
        removeAttachmentFromTask,
        requirementFinalizeError,
        requirementFinalizing,
        saveTaskChanges,
        setActiveErrorSection: errorOccurrenceState.setActiveErrorSection,
        taskAttachments,
        taskSaveError,
        taskSaveState,
        taskSaving,
        ...taskIndexThreadBindings(thread),
        updateEditCollaborators,
    };
}
