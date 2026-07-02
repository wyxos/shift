import { collaboratorsEqual, normalizeTaskCollaborators, type TaskCollaboratorSelection } from '@/shared/tasks/collaborators';
import { getTaskIdFromQuery, syncTaskQuery } from '@/shared/tasks/history';
import { getTaskCreatorEmail, getTaskCreatorName, getTaskEnvironment } from '@/shared/tasks/metadata';
import { projectEnvironmentOptions, type TaskProjectOption } from '@/shared/tasks/projects';
import type { TaskDetail, TaskIndexEditSnapshot, TaskIndexOpenEditOptions } from '@/shared/tasks/types';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch, type ComputedRef } from 'vue';
import { toast } from 'vue-sonner';
import { defaultTaskEditForm, editMobilePaneOptions } from './taskIndexEditDefaults';
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
    const editTempIdentifier = ref(Date.now().toString());
    const editForm = ref(defaultTaskEditForm());
    const confirmCloseOpen = ref(false);
    const initialEditSnapshot = ref<TaskIndexEditSnapshot | null>(null);
    const editMobilePane = ref<'details' | 'comments'>('details');
    const taskSaving = ref(false);
    const taskSaveError = ref<string | null>(null);
    const pendingTaskSave = ref(false);
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
        return getTaskEnvironment(editTask.value) ?? 'Unknown';
    });
    const taskAttachments = computed(() => {
        if (!editTask.value?.attachments) return [];
        const removed = new Set(deletedAttachmentIds.value);
        return editTask.value.attachments.filter((attachment) => !removed.has(attachment.id));
    });
    const hasUnsavedTaskChanges = computed(() => {
        if (!editOpen.value) return false;
        if (taskSaving.value) return true;
        return hasPendingTaskChanges();
    });
    const hasUnsavedCommentDraft = computed(() => {
        if (!editOpen.value) return false;
        if (thread.threadEditingId.value) return true;
        if (thread.threadComposerHtml.value.trim()) return true;
        return false;
    });
    const hasUnsavedChanges = computed(() => hasUnsavedTaskChanges.value || hasUnsavedCommentDraft.value);
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
            if (!hasPendingTaskChanges()) return;
            scheduleTaskAutosave();
        },
    );
    watch(editMobilePane, (pane) => {
        if (!editOpen.value || pane !== 'comments') return;
        thread.scrollCommentsToBottomSoon();
    });
    function hasPendingTaskChanges() {
        const snap = initialEditSnapshot.value;
        if (!snap) return false;
        if (editForm.value.title !== snap.title) return true;
        if (editForm.value.priority !== snap.priority) return true;
        if (editForm.value.status !== snap.status) return true;
        if (editForm.value.requirement_status !== snap.requirement_status) return true;
        if ((editForm.value.description ?? '') !== (snap.description ?? '')) return true;
        if ((editForm.value.environment ?? '') !== (snap.environment ?? '')) return true;
        if (!collaboratorsEqual(editForm.value.collaborators, snap.collaborators)) return true;
        return deletedAttachmentIds.value.length > 0;
    }
    function currentTaskSnapshot(): TaskIndexEditSnapshot {
        return {
            title: editForm.value.title,
            priority: editForm.value.priority,
            status: editForm.value.status,
            requirement_status: editForm.value.requirement_status,
            description: editForm.value.description,
            environment: editForm.value.environment,
            collaborators: normalizeTaskCollaborators(editForm.value.collaborators),
        };
    }
    function hasCollaboratorManagementChanges() {
        const snap = initialEditSnapshot.value;
        if (!snap) return false;
        if ((editForm.value.environment ?? '') !== (snap.environment ?? '')) return true;
        return !collaboratorsEqual(editForm.value.collaborators, snap.collaborators);
    }
    function mergeEditedTask(task: Partial<TaskDetail> | null | undefined) {
        if (!editTask.value || !task) return;
        editTask.value = {
            ...editTask.value,
            ...task,
            attachments: Array.isArray(task.attachments) ? task.attachments : editTask.value.attachments,
        };
        if (Object.prototype.hasOwnProperty.call(task, 'environment')) {
            editForm.value.environment = task.environment ?? null;
        }
        if (task.internal_collaborators || task.external_collaborators) {
            editForm.value.collaborators = normalizeTaskCollaborators({
                internal: Array.isArray(task.internal_collaborators) ? task.internal_collaborators : editForm.value.collaborators.internal,
                external: Array.isArray(task.external_collaborators) ? task.external_collaborators : editForm.value.collaborators.external,
            });
        }
    }
    function syncTaskRowFromEditForm(taskId: number) {
        if (editTask.value?.id !== taskId) return;
        editTask.value = {
            ...editTask.value,
            title: editForm.value.title,
            status: editForm.value.status,
            requirement_status: editForm.value.requirement_status,
            priority: editForm.value.priority,
            environment: editForm.value.environment,
        };
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
        if (!hasPendingTaskChanges()) return;
        const snapshot = initialEditSnapshot.value;
        if (!snapshot) return;
        const taskId = editTask.value.id;
        const needsCollaboratorUpdate = canManageCollaborators.value && hasCollaboratorManagementChanges();
        const needsCoreUpdate = canEditTaskScope.value
            ? editForm.value.title !== snapshot.title ||
              editForm.value.priority !== snapshot.priority ||
              editForm.value.status !== snapshot.status ||
              editForm.value.requirement_status !== snapshot.requirement_status ||
              (editForm.value.description ?? '') !== (snapshot.description ?? '') ||
              deletedAttachmentIds.value.length > 0
            : false;

        if (!needsCoreUpdate && !needsCollaboratorUpdate) return;

        const collaboratorPayload = needsCollaboratorUpdate
            ? {
                  environment: editTask.value.environment ?? null,
                  internal_collaborator_ids: editForm.value.collaborators.internal.map((collaborator) => Number(collaborator.id)),
                  external_collaborators: editForm.value.collaborators.external.map((collaborator) => ({
                      id: collaborator.id,
                      name: collaborator.name,
                      email: collaborator.email,
                  })),
              }
            : null;

        taskSaving.value = true;
        taskSaveError.value = null;
        taskSaveToast.showTaskSavingToast();

        try {
            if (needsCoreUpdate) {
                const payload = {
                    title: editForm.value.title,
                    description: editForm.value.description,
                    priority: editForm.value.priority,
                    status: editForm.value.status,
                    requirement_status: editForm.value.requirement_status,
                    temp_identifier: editTempIdentifier.value,
                    deleted_attachment_ids: deletedAttachmentIds.value.length ? deletedAttachmentIds.value : undefined,
                };

                const response = await axios.put(route('tasks.update', { task: taskId }), payload);
                mergeEditedTask(response.data?.task ?? null);
            }

            if (needsCollaboratorUpdate && collaboratorPayload) {
                const response = await axios.patch(route('tasks.collaborators.update', { task: taskId }), collaboratorPayload);
                mergeEditedTask(response.data?.task ?? null);
            }

            deletedAttachmentIds.value = [];
            initialEditSnapshot.value = currentTaskSnapshot();
            syncTaskRowFromEditForm(taskId);
            options.onTaskSaved(taskId);
        } catch (e: any) {
            taskSaveError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to autosave task';
        } finally {
            taskSaving.value = false;
            if (pendingTaskSave.value) {
                pendingTaskSave.value = false;
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
            initialEditSnapshot.value = currentTaskSnapshot();
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
            editTempIdentifier.value = Date.now().toString();
            initialEditSnapshot.value = {
                title: editForm.value.title,
                priority: editForm.value.priority,
                status: editForm.value.status,
                requirement_status: editForm.value.requirement_status,
                description: editForm.value.description,
                environment: editForm.value.environment,
                collaborators: normalizeTaskCollaborators(editForm.value.collaborators),
            };
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
        if (!hasPendingTaskChanges()) return;
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
        activeErrorThreadTab: errorOccurrenceState.activeErrorThreadTab,
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
        setActiveErrorThreadTab: errorOccurrenceState.setActiveErrorThreadTab,
        taskAttachments,
        taskSaveError,
        taskSaving,
        ...taskIndexThreadBindings(thread),
        updateEditCollaborators,
    };
}
