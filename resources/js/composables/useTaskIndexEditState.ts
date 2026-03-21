import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch, type ComputedRef } from 'vue';
import { toast } from 'vue-sonner';
import { collaboratorsEqual, emptyTaskCollaborators, normalizeTaskCollaborators, type TaskCollaboratorSelection } from '@/shared/tasks/collaborators';
import { getTaskIdFromQuery, syncTaskQuery } from '@/shared/tasks/history';
import { getTaskCreatorEmail, getTaskCreatorName, getTaskEnvironment } from '@/shared/tasks/metadata';
import { projectEnvironmentOptions, type TaskProjectOption } from '@/shared/tasks/projects';
import { useTaskIndexThreadState } from './useTaskIndexThreadState';
import type { TaskDetail, TaskIndexEditSnapshot, TaskIndexOpenEditOptions } from '@/shared/tasks/types';
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
    const editForm = ref({
        title: '',
        priority: 'medium',
        status: 'pending',
        description: '',
        environment: null as string | null,
        collaborators: emptyTaskCollaborators(),
    });
    const confirmCloseOpen = ref(false);
    const initialEditSnapshot = ref<TaskIndexEditSnapshot | null>(null);
    const editMobilePane = ref<'details' | 'comments'>('details');
    const editMobilePaneOptions = [
        { value: 'details', label: 'Details' },
        { value: 'comments', label: 'Comments' },
    ];
    const taskSaving = ref(false);
    const taskSaveError = ref<string | null>(null);
    const pendingTaskSave = ref(false);
    const autosaveArmed = ref(false);
    let taskAutosaveTimer: number | null = null;
    const taskSaveToastId = ref<string | number | null>(null);
    const thread = useTaskIndexThreadState({
        aiImproveEnabled: options.aiImproveEnabled,
        editOpen,
        editTask,
    });
    const isOwner = computed(() => Boolean(editTask.value?.is_owner));
    const canManageCollaborators = computed(() => Boolean(editTask.value?.can_manage_collaborators));
    const editTaskCreatorLabel = computed(() => getTaskCreatorName(editTask.value) ?? getTaskCreatorEmail(editTask.value) ?? 'Unknown');
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

    function showTaskSavingToast() {
        if (taskSaveToastId.value !== null) return;
        taskSaveToastId.value = toast.loading('Saving task changes...');
    }

    function showTaskSaveResultToast(success: boolean, message?: string) {
        const id = taskSaveToastId.value ?? undefined;
        taskSaveToastId.value = null;
        if (success) {
            toast.success('Task changes saved', { id, duration: 1400 });
            return;
        }
        toast.error('Failed to save task changes', { id, description: message ?? 'Unknown error', duration: 4000 });
    }

    async function saveTaskChanges() {
        if (!editTask.value) return;
        if (!hasPendingTaskChanges()) return;

        const snapshot = initialEditSnapshot.value;
        if (!snapshot) return;

        const taskId = editTask.value.id;
        const needsCollaboratorUpdate = canManageCollaborators.value && hasCollaboratorManagementChanges();
        const needsCoreUpdate = isOwner.value
            ? editForm.value.title !== snapshot.title ||
              editForm.value.priority !== snapshot.priority ||
              editForm.value.status !== snapshot.status ||
              (editForm.value.description ?? '') !== (snapshot.description ?? '') ||
              deletedAttachmentIds.value.length > 0
            : editForm.value.status !== snapshot.status;

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
        showTaskSavingToast();

        try {
            if (needsCoreUpdate) {
                const payload = isOwner.value
                    ? {
                          title: editForm.value.title,
                          description: editForm.value.description,
                          priority: editForm.value.priority,
                          status: editForm.value.status,
                          temp_identifier: editTempIdentifier.value,
                          deleted_attachment_ids: deletedAttachmentIds.value.length ? deletedAttachmentIds.value : undefined,
                      }
                    : {
                          status: editForm.value.status,
                      };

                const response = await axios.put(route('tasks.v2.update', { task: taskId }), payload);
                mergeEditedTask(response.data?.task ?? null);
            }

            if (needsCollaboratorUpdate && collaboratorPayload) {
                const response = await axios.patch(route('tasks.v2.collaborators.update', { task: taskId }), collaboratorPayload);
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
            showTaskSaveResultToast(!taskSaveError.value, taskSaveError.value ?? undefined);
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
        pendingTaskSave.value = false;
        autosaveArmed.value = false;
        deletedAttachmentIds.value = [];
        initialEditSnapshot.value = null;
        editMobilePane.value = 'details';
        thread.resetThreadState();

        if (updateHistory) {
            syncTaskQuery(taskId, 'push');
        }

        try {
            const response = await axios.get(route('tasks.v2.show', { task: taskId }));
            const data = response.data as TaskDetail;
            editTask.value = data;
            editForm.value = {
                title: data?.title ?? '',
                priority: data?.priority ?? 'medium',
                status: data?.status ?? 'pending',
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
                description: editForm.value.description,
                environment: editForm.value.environment,
                collaborators: normalizeTaskCollaborators(editForm.value.collaborators),
            };
            autosaveArmed.value = true;
            void thread.fetchThreads(taskId);
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
        editForm.value = {
            title: '',
            priority: 'medium',
            status: 'pending',
            description: '',
            environment: null,
            collaborators: emptyTaskCollaborators(),
        };
        initialEditSnapshot.value = null;
        editMobilePane.value = 'details';
        taskSaving.value = false;
        taskSaveError.value = null;
        pendingTaskSave.value = false;
        autosaveArmed.value = false;
        thread.resetThreadState();
        if (taskSaveToastId.value !== null) {
            toast.dismiss(taskSaveToastId.value);
            taskSaveToastId.value = null;
        }
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
        if (taskSaveToastId.value !== null) {
            toast.dismiss(taskSaveToastId.value);
            taskSaveToastId.value = null;
        }
    });

    return {
        aiImproveEnabled: options.aiImproveEnabled,
        attemptCloseEdit,
        canManageCollaborators,
        cancelThreadEdit: thread.cancelThreadEdit,
        commentsScrollRef: thread.commentsScrollRef,
        closeEditNow,
        confirmCloseOpen,
        contextMenuMessageId: thread.contextMenuMessageId,
        contextMenuSelectionText: thread.contextMenuSelectionText,
        copyEntireMessage: thread.copyEntireMessage,
        copySelectedMessage: thread.copySelectedMessage,
        deleteThreadMessage: thread.deleteThreadMessage,
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
        fetchThreads: thread.fetchThreads,
        handleReplyReferenceClick: thread.handleReplyReferenceClick,
        handleThreadSend: thread.handleThreadSend,
        hasUnsavedChanges,
        hasUnsavedCommentDraft,
        hasUnsavedTaskChanges,
        isOwner,
        lastTouchTapAt: thread.lastTouchTapAt,
        lastTouchTapId: thread.lastTouchTapId,
        lightboxAlt: thread.lightboxAlt,
        lightboxOpen: thread.lightboxOpen,
        lightboxSrc: thread.lightboxSrc,
        onCommentContextMenuOpen: thread.onCommentContextMenuOpen,
        onCommentsMediaLoadCapture: thread.onCommentsMediaLoadCapture,
        onEditOpenChange,
        onGlobalClickCapture: thread.onGlobalClickCapture,
        onGlobalDblClickCapture: thread.onGlobalDblClickCapture,
        onGlobalKeyDownCapture: thread.onGlobalKeyDownCapture,
        onMessageDblClick: thread.onMessageDblClick,
        onMessageTouchEnd: thread.onMessageTouchEnd,
        onRichContentClick: thread.onRichContentClick,
        openEdit,
        pendingTaskSave,
        removeAttachmentFromTask,
        resetThreadState: thread.resetThreadState,
        saveTaskChanges,
        scrollCommentsToBottomSoon: thread.scrollCommentsToBottomSoon,
        shouldShowCopySelection: thread.shouldShowCopySelection,
        startReplyToMessage: thread.startReplyToMessage,
        startThreadEdit: thread.startThreadEdit,
        taskAttachments,
        taskSaveError,
        taskSaving,
        threadAiContext: thread.threadAiContext,
        threadComposerHtml: thread.threadComposerHtml,
        threadComposerRef: thread.threadComposerRef,
        threadComposerUploading: thread.threadComposerUploading,
        threadEditError: thread.threadEditError,
        threadEditSaving: thread.threadEditSaving,
        threadError: thread.threadError,
        threadLoading: thread.threadLoading,
        threadMessages: thread.threadMessages,
        threadSending: thread.threadSending,
        threadTempIdentifier: thread.threadTempIdentifier,
        threadEditingId: thread.threadEditingId,
        updateEditCollaborators,
    };
}
