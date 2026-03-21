<script lang="ts" setup>
/* eslint-disable max-lines */
import ShiftEditor from '@/components/ShiftEditor.vue';
import TaskCollaboratorField from '@/components/tasks/TaskCollaboratorField.vue';
import TaskCreateSheet from '@/components/tasks/TaskCreateSheet.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { ImageLightbox } from '@/components/ui/image-lightbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import AppLayout from '@/layouts/AppLayout.vue';
import { buildThreadAiContext } from '@/shared/tasks/ai';
import {
    collaboratorsEqual,
    emptyTaskCollaborators,
    normalizeTaskCollaborators,
    type CollaboratorOption,
    type TaskCollaboratorSelection,
} from '@/shared/tasks/collaborators';
import { getTaskIdFromQuery, syncTaskQuery } from '@/shared/tasks/history';
import {
    copyTextToClipboard,
    getLightboxImageData,
    getSelectionForMessage as getSelectionForMessageText,
    resolveTouchTap,
    shouldIgnoreEditGesture as shouldIgnoreEditGestureForEvent,
    shouldShowCopySelection as shouldShowCopySelectionForContext,
} from '@/shared/tasks/interaction';
import { getTaskCreatorEmail, getTaskCreatorName, getTaskEnvironment } from '@/shared/tasks/metadata';
import {
    DEFAULT_SORT_BY,
    getDefaultStatuses,
    getPriorityBadgeClass,
    getPriorityLabel,
    getPriorityOptions,
    getSortByOptions,
    getStatusBadgeClass,
    getStatusLabel,
    getStatusOptions,
    normalizeStringList,
} from '@/shared/tasks/presentation';
import { projectEnvironmentOptions, type TaskProjectOption } from '@/shared/tasks/projects';
import { buildReplyQuoteHtml, extractPlainTextFromContent, renderRichContent } from '@/shared/tasks/rich-content';
import { formatThreadTime, getReplyTargetFromEventTarget, mapThreadToMessage, shouldHandleImage } from '@/shared/tasks/thread';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Filter, Paperclip, Pencil, Trash2 } from 'lucide-vue-next';
import { ContextMenuContent, ContextMenuItem, ContextMenuPortal, ContextMenuRoot, ContextMenuSeparator, ContextMenuTrigger } from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

type Task = {
    id: number;
    title: string;
    status: string;
    priority: string;
    environment?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
};

type TaskPaginator = {
    data: Task[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type TaskAttachment = {
    id: number;
    original_filename: string;
    url?: string;
    path?: string;
};

type TaskDetail = Task & {
    project_id: number;
    description?: string;
    created_at?: string;
    submitter?: { name?: string; email?: string } | null;
    environment?: string | null;
    attachments?: TaskAttachment[];
    is_owner?: boolean;
    can_manage_collaborators?: boolean;
    internal_collaborators?: CollaboratorOption[];
    external_collaborators?: CollaboratorOption[];
};

type ThreadMessage = {
    clientId: string;
    id?: number;
    author: string;
    time: string;
    content: string;
    isYou?: boolean;
    pending?: boolean;
    failed?: boolean;
    attachments?: TaskAttachment[];
};

const props = withDefaults(
    defineProps<{
        tasks: TaskPaginator;
        projects?: TaskProjectOption[];
        filters: {
            status?: string[] | string | null;
            priority?: string[] | string | null;
            search?: string | null;
            environment?: string | null;
            sort_by?: string | null;
        };
    }>(),
    {
        projects: () => [],
    },
);

const tasksPage = ref<TaskPaginator>({ ...props.tasks });
watch(
    () => props.tasks,
    (next) => {
        tasksPage.value = { ...next };
    },
    { deep: true },
);
const taskRows = computed(() => tasksPage.value.data ?? []);
const page = usePage<SharedData>();
const aiImproveEnabled = computed(() => Boolean(page.props.shift?.ai_enabled));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/tasks' },
    { title: 'Tasks', href: '/tasks' },
];

const statusOptions = getStatusOptions({ includeClosed: false });
const priorityOptions = getPriorityOptions();
const sortByOptions = getSortByOptions();
const defaultSortBy = DEFAULT_SORT_BY;
const allowedSortBy = new Set(sortByOptions.map((option) => option.value));

const defaultStatuses = getDefaultStatuses(statusOptions, ['completed']);
const allPriorities = priorityOptions.map((option) => option.value);

const filtersOpen = ref(false);
const error = ref<string | null>(null);
const deleteLoading = ref<number | null>(null);
const highlightedTaskId = ref<number | null>(null);
let highlightTimer: number | null = null;

const providedStatuses = normalizeStringList(props.filters.status);
const providedPriorities = normalizeStringList(props.filters.priority);
const providedSearchTerm = typeof props.filters.search === 'string' ? props.filters.search : '';
const providedEnvironmentTerm = typeof props.filters.environment === 'string' ? props.filters.environment : '';
const providedSortBy = typeof props.filters.sort_by === 'string' && allowedSortBy.has(props.filters.sort_by) ? props.filters.sort_by : defaultSortBy;

const appliedStatuses = ref<string[]>(providedStatuses.length ? providedStatuses : [...defaultStatuses]);
const appliedPriorities = ref<string[]>(providedPriorities.length ? providedPriorities : [...allPriorities]);
const appliedSearchTerm = ref(providedSearchTerm);
const appliedEnvironmentTerm = ref(providedEnvironmentTerm);
const appliedSortBy = ref(providedSortBy);

const draftStatuses = ref<string[]>([...appliedStatuses.value]);
const draftPriorities = ref<string[]>([...appliedPriorities.value]);
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftEnvironmentTerm = ref(appliedEnvironmentTerm.value);
const draftSortBy = ref(appliedSortBy.value);

watch(
    () => props.filters,
    (next) => {
        const nextStatuses = normalizeStringList(next.status);
        const nextPriorities = normalizeStringList(next.priority);
        const nextSearch = typeof next.search === 'string' ? next.search : '';
        const nextEnvironment = typeof next.environment === 'string' ? next.environment : '';
        const nextSortBy = typeof next.sort_by === 'string' && allowedSortBy.has(next.sort_by) ? next.sort_by : defaultSortBy;

        appliedStatuses.value = nextStatuses.length ? nextStatuses : [...defaultStatuses];
        appliedPriorities.value = nextPriorities.length ? nextPriorities : [...allPriorities];
        appliedSearchTerm.value = nextSearch;
        appliedEnvironmentTerm.value = nextEnvironment;
        appliedSortBy.value = nextSortBy;
    },
    { deep: true },
);

const activeFilterCount = computed(() => {
    let count = 0;
    if (appliedStatuses.value.length && appliedStatuses.value.length < statusOptions.length) count += 1;
    if (appliedPriorities.value.length && appliedPriorities.value.length < priorityOptions.length) count += 1;
    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedEnvironmentTerm.value.trim()) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;
    return count;
});

watch(filtersOpen, (open) => {
    if (!open) return;
    draftStatuses.value = [...appliedStatuses.value];
    draftPriorities.value = [...appliedPriorities.value];
    draftSearchTerm.value = appliedSearchTerm.value;
    draftEnvironmentTerm.value = appliedEnvironmentTerm.value;
    draftSortBy.value = appliedSortBy.value;
});

function resetFilters() {
    draftStatuses.value = [...defaultStatuses];
    draftPriorities.value = [...allPriorities];
    draftSearchTerm.value = '';
    draftEnvironmentTerm.value = '';
    draftSortBy.value = defaultSortBy;

    appliedStatuses.value = [...draftStatuses.value];
    appliedPriorities.value = [...draftPriorities.value];
    appliedSearchTerm.value = draftSearchTerm.value;
    appliedEnvironmentTerm.value = draftEnvironmentTerm.value;
    appliedSortBy.value = draftSortBy.value;

    router.get(
        '/tasks',
        {
            status: appliedStatuses.value,
            priority: appliedPriorities.value,
            search: appliedSearchTerm.value || undefined,
            environment: appliedEnvironmentTerm.value || undefined,
            sort_by: appliedSortBy.value,
            page: 1,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
    filtersOpen.value = false;
}

function applyFilters() {
    appliedStatuses.value = [...draftStatuses.value];
    appliedPriorities.value = [...draftPriorities.value];
    appliedSearchTerm.value = draftSearchTerm.value;
    appliedEnvironmentTerm.value = draftEnvironmentTerm.value;
    appliedSortBy.value = draftSortBy.value;

    router.get(
        '/tasks',
        {
            status: appliedStatuses.value,
            priority: appliedPriorities.value,
            search: appliedSearchTerm.value || undefined,
            environment: appliedEnvironmentTerm.value || undefined,
            sort_by: appliedSortBy.value,
            page: 1,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
    filtersOpen.value = false;
}

function selectAllStatuses() {
    draftStatuses.value = statusOptions.map((option) => option.value);
}

function selectAllPriorities() {
    draftPriorities.value = priorityOptions.map((option) => option.value);
}

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
const initialEditSnapshot = ref<{
    title: string;
    priority: string;
    status: string;
    description: string;
    environment: string | null;
    collaborators: TaskCollaboratorSelection;
} | null>(null);

const threadTempIdentifier = ref(Date.now().toString());
const threadLoading = ref(false);
const threadSending = ref(false);
const threadError = ref<string | null>(null);
const threadMessages = ref<ThreadMessage[]>([]);
const threadAiContext = computed(() => buildThreadAiContext(threadMessages.value));

const threadComposerRef = ref<InstanceType<typeof ShiftEditor> | null>(null);
const threadComposerHtml = ref('');
const threadComposerUploading = ref(false);
const threadEditingId = ref<number | null>(null);
const threadEditSaving = ref(false);
const threadEditError = ref<string | null>(null);
const contextMenuMessageId = ref<number | null>(null);
const contextMenuSelectionText = ref('');
const lastTouchTapAt = ref(0);
const lastTouchTapId = ref<number | null>(null);

const commentsScrollRef = ref<HTMLElement | null>(null);
const editMobilePane = ref<'details' | 'comments'>('details');
const editMobilePaneOptions = [
    { value: 'details', label: 'Details' },
    { value: 'comments', label: 'Comments' },
];

const lightboxOpen = ref(false);
const lightboxSrc = ref('');
const lightboxAlt = ref('');

const isOwner = computed(() => Boolean(editTask.value?.is_owner));
const canManageCollaborators = computed(() => Boolean(editTask.value?.can_manage_collaborators));
const editTaskCreatorLabel = computed(() => getTaskCreatorName(editTask.value) ?? getTaskCreatorEmail(editTask.value) ?? 'Unknown');
const editTaskEnvironmentLabel = computed(() => {
    const projectId = editTask.value?.project_id ?? null;
    const selectedEnvironment = editForm.value.environment;

    if (projectId !== null && selectedEnvironment) {
        return (
            projectEnvironmentOptions(props.projects, projectId).find((environment) => environment.key === selectedEnvironment)?.label ??
            selectedEnvironment
        );
    }

    return getTaskEnvironment(editTask.value) ?? 'Unknown';
});
const taskSaving = ref(false);
const taskSaveError = ref<string | null>(null);
const pendingTaskSave = ref(false);
const autosaveArmed = ref(false);
let taskAutosaveTimer: number | null = null;
const taskSaveToastId = ref<string | number | null>(null);

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
    if (threadEditingId.value) return true;
    if (threadComposerHtml.value.trim()) return true;
    return false;
});

const hasUnsavedChanges = computed(() => hasUnsavedTaskChanges.value || hasUnsavedCommentDraft.value);
type OpenEditOptions = {
    updateHistory?: boolean;
};

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
    threadMessages.value = [];
    threadError.value = null;
    editForm.value = {
        title: '',
        priority: 'medium',
        status: 'pending',
        description: '',
        environment: null,
        collaborators: emptyTaskCollaborators(),
    };
    initialEditSnapshot.value = null;
    threadComposerHtml.value = '';
    threadEditingId.value = null;
    threadEditError.value = null;
    threadEditSaving.value = false;
    contextMenuMessageId.value = null;
    contextMenuSelectionText.value = '';
    editMobilePane.value = 'details';
    taskSaving.value = false;
    taskSaveError.value = null;
    pendingTaskSave.value = false;
    autosaveArmed.value = false;
    if (taskSaveToastId.value !== null) {
        toast.dismiss(taskSaveToastId.value);
        taskSaveToastId.value = null;
    }
    if (updateHistory) {
        syncTaskQuery(null, 'push');
    }
}

function highlightTask(taskId: number) {
    highlightedTaskId.value = taskId;

    if (highlightTimer) {
        window.clearTimeout(highlightTimer);
    }

    highlightTimer = window.setTimeout(() => {
        highlightedTaskId.value = null;
        highlightTimer = null;
    }, 4500);
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

function openLightboxForImage(img: HTMLImageElement) {
    const data = getLightboxImageData(img);
    if (!data) return;
    lightboxSrc.value = data.src;
    lightboxAlt.value = data.alt;
    lightboxOpen.value = true;
}

function onRichContentClick(event: MouseEvent) {
    const target = event.target as HTMLElement | null;
    if (!target) return;

    if (handleReplyReferenceClick(target, event)) return;

    const img = target.closest('img') as HTMLImageElement | null;
    if (!img) return;
    const inRich = Boolean(img.closest('.shift-rich')) || Boolean(img.closest('.tiptap')) || img.classList.contains('editor-tile');
    if (!inRich) return;
    event.preventDefault();
    event.stopPropagation();
    openLightboxForImage(img);
}

function highlightReplyTargetBubble(target: HTMLElement) {
    target.classList.add('shift-reply-target');
    window.setTimeout(() => {
        target.classList.remove('shift-reply-target');
    }, 1800);
}

function scrollToReplyTarget(commentId: number): boolean {
    const selector = `#comment-${commentId}`;
    const withinComments = commentsScrollRef.value?.querySelector(selector) as HTMLElement | null;
    const target = withinComments ?? (document.getElementById(`comment-${commentId}`) as HTMLElement | null);
    if (!target) return false;
    target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    highlightReplyTargetBubble(target);
    return true;
}

function handleReplyReferenceClick(target: HTMLElement, event: MouseEvent): boolean {
    if (!editOpen.value) return false;
    if (target.closest('[contenteditable="true"]')) return false;
    const commentId = getReplyTargetFromEventTarget(target);
    if (!commentId) return false;
    event.preventDefault();
    event.stopPropagation();
    return scrollToReplyTarget(commentId);
}

function onGlobalClickCapture(event: MouseEvent) {
    if (!editOpen.value) return;
    const target = event.target as HTMLElement | null;
    if (!target) return;

    if (handleReplyReferenceClick(target, event)) return;

    const img = target.closest('img') as HTMLImageElement | null;
    if (!img) return;
    const { ok, inEditable } = shouldHandleImage(img);
    if (!ok || inEditable) return;
    event.preventDefault();
    event.stopPropagation();
    openLightboxForImage(img);
}

function onGlobalDblClickCapture(event: MouseEvent) {
    if (!editOpen.value) return;
    const target = event.target as HTMLElement | null;
    if (!target) return;
    const img = target.closest('img') as HTMLImageElement | null;
    if (!img) return;
    const { ok, inEditable } = shouldHandleImage(img);
    if (!ok || !inEditable) return;
    event.preventDefault();
    event.stopPropagation();
    openLightboxForImage(img);
}

function onGlobalKeyDownCapture(event: KeyboardEvent) {
    if (!editOpen.value) return;
    if (!threadEditingId.value) return;
    if (event.key !== 'Escape') return;

    // Escape should cancel edit mode (and not close the sheet).
    event.preventDefault();
    event.stopPropagation();
    (event as any).stopImmediatePropagation?.();
    cancelThreadEdit();
}

function onCommentContextMenuOpen(message: ThreadMessage, open: boolean) {
    if (!open) {
        contextMenuMessageId.value = null;
        contextMenuSelectionText.value = '';
        return;
    }
    contextMenuMessageId.value = message.id ?? null;
    contextMenuSelectionText.value = getSelectionForMessageText(message.id);
}

function shouldShowCopySelection(message: ThreadMessage): boolean {
    return shouldShowCopySelectionForContext(message, contextMenuMessageId.value, contextMenuSelectionText.value);
}

async function copyEntireMessage(message: ThreadMessage) {
    const copied = await copyTextToClipboard(extractPlainTextFromContent(message.content));
    if (copied) {
        toast.success('Message copied');
        return;
    }
    toast.error('Unable to copy message');
}

async function copySelectedMessage() {
    const copied = await copyTextToClipboard(contextMenuSelectionText.value);
    if (copied) {
        toast.success('Selection copied');
        return;
    }
    toast.error('Unable to copy selection');
}

onMounted(() => {
    document.addEventListener('click', onGlobalClickCapture, true);
    document.addEventListener('dblclick', onGlobalDblClickCapture, true);
    document.addEventListener('keydown', onGlobalKeyDownCapture, true);
    window.addEventListener('popstate', onTaskQueryPopState);

    const deepLinkedTaskId = getTaskIdFromQuery();
    if (deepLinkedTaskId !== null) {
        void openEdit(deepLinkedTaskId, { updateHistory: false });
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onGlobalClickCapture, true);
    document.removeEventListener('dblclick', onGlobalDblClickCapture, true);
    document.removeEventListener('keydown', onGlobalKeyDownCapture, true);
    window.removeEventListener('popstate', onTaskQueryPopState);
    if (highlightTimer) {
        window.clearTimeout(highlightTimer);
        highlightTimer = null;
    }
    if (taskSaveToastId.value !== null) {
        toast.dismiss(taskSaveToastId.value);
        taskSaveToastId.value = null;
    }
});

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

function scrollCommentsToBottom() {
    const el = commentsScrollRef.value;
    if (!el) return;
    if (typeof (el as any).scrollTo === 'function') {
        (el as any).scrollTo({ top: el.scrollHeight, behavior: 'auto' });
        return;
    }
    el.scrollTop = el.scrollHeight;
}

function scrollCommentsToBottomSoon() {
    void nextTick().then(scrollCommentsToBottom);
    const raf = globalThis.requestAnimationFrame ?? ((cb: FrameRequestCallback) => window.setTimeout(cb, 0));
    raf(scrollCommentsToBottom);
    window.setTimeout(scrollCommentsToBottom, 50);
    window.setTimeout(scrollCommentsToBottom, 250);
}

function onCommentsMediaLoadCapture(event: Event) {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    const tag = target.tagName?.toLowerCase();
    if (tag !== 'img' && tag !== 'video') return;
    scrollCommentsToBottomSoon();
}

async function fetchThreads(taskId: number) {
    threadLoading.value = true;
    threadError.value = null;
    try {
        const response = await axios.get(route('task-threads.index', { task: taskId }));
        const list = Array.isArray(response.data?.external) ? response.data.external : [];
        threadMessages.value = list.map((thread) => mapThreadToMessage<TaskAttachment>(thread));
        scrollCommentsToBottomSoon();
    } catch (e: any) {
        threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to load comments';
    } finally {
        threadLoading.value = false;
    }
}

watch(editMobilePane, (pane) => {
    if (!editOpen.value || pane !== 'comments') return;
    scrollCommentsToBottomSoon();
});

async function openEdit(taskId: number, options: OpenEditOptions = {}) {
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
    threadMessages.value = [];
    threadError.value = null;
    threadTempIdentifier.value = Date.now().toString();
    threadComposerHtml.value = '';
    threadEditingId.value = null;
    threadEditError.value = null;
    threadEditSaving.value = false;
    initialEditSnapshot.value = null;
    editMobilePane.value = 'details';
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
        void fetchThreads(taskId);
    } catch (e: any) {
        editError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to fetch task';
    } finally {
        editLoading.value = false;
    }
}

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

function currentTaskSnapshot() {
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

function updateEditCollaborators(value: TaskCollaboratorSelection) {
    editForm.value.collaborators = normalizeTaskCollaborators(value);

    if (!editOpen.value || !autosaveArmed.value) return;
    if (!hasPendingTaskChanges()) return;

    scheduleTaskAutosave();
}

function syncListRowFromEditForm(taskId: number) {
    tasksPage.value = {
        ...tasksPage.value,
        data: tasksPage.value.data.map((task) =>
            task.id === taskId
                ? {
                      ...task,
                      title: editForm.value.title,
                      status: editForm.value.status,
                      priority: editForm.value.priority,
                      environment: editForm.value.environment,
                  }
                : task,
        ),
    };
}

function refreshTasksList() {
    router.reload({
        only: ['tasks'],
        preserveScroll: true,
        preserveState: true,
    });
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

    const taskId = editTask.value.id;
    const snapshot = initialEditSnapshot.value;
    const canUpdateCollaborators = canManageCollaborators.value;
    const needsCollaboratorUpdate = canUpdateCollaborators && hasCollaboratorManagementChanges();
    const needsCoreUpdate = isOwner.value
        ? Boolean(snapshot) &&
          (editForm.value.title !== snapshot.title ||
              editForm.value.priority !== snapshot.priority ||
              editForm.value.status !== snapshot.status ||
              (editForm.value.description ?? '') !== (snapshot.description ?? '') ||
              deletedAttachmentIds.value.length > 0)
        : Boolean(snapshot) && editForm.value.status !== snapshot.status;

    if (!needsCoreUpdate && !needsCollaboratorUpdate) return;

    const collaboratorPayload = needsCollaboratorUpdate
        ? {
              environment: editForm.value.environment,
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
        syncListRowFromEditForm(taskId);
        refreshTasksList();
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

function removeAttachmentFromTask(attachmentId: number) {
    if (!deletedAttachmentIds.value.includes(attachmentId)) {
        deletedAttachmentIds.value = [...deletedAttachmentIds.value, attachmentId];
        scheduleTaskAutosave(true);
    }
}

async function handleThreadSend(payload: { html: string }) {
    if (!editTask.value) return;
    if (threadComposerUploading.value) return;
    if (threadSending.value || threadEditSaving.value) return;
    const html = payload?.html?.trim();
    if (!html) return;

    if (threadEditingId.value) {
        threadEditSaving.value = true;
        threadEditError.value = null;
        try {
            const response = await axios.put(route('task-threads.update', { task: editTask.value.id, thread: threadEditingId.value }), {
                content: html,
                temp_identifier: threadTempIdentifier.value,
            });

            const thread = response.data?.thread ?? response.data;
            const serverMsg = mapThreadToMessage<TaskAttachment>(thread);
            threadMessages.value = threadMessages.value.map((m) =>
                m.id === threadEditingId.value ? { ...m, content: serverMsg.content, attachments: serverMsg.attachments } : m,
            );

            threadEditingId.value = null;
            threadTempIdentifier.value = Date.now().toString();
            threadComposerHtml.value = '';
            threadComposerRef.value?.reset?.();
            scrollCommentsToBottomSoon();
        } catch (e: any) {
            threadEditError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to update comment';
        } finally {
            threadEditSaving.value = false;
        }
        return;
    }

    const localId = `local-${Date.now()}`;
    const optimistic: ThreadMessage = {
        clientId: localId,
        author: 'You',
        time: 'Sending...',
        content: html,
        isYou: true,
        pending: true,
        failed: false,
    };
    threadMessages.value = [...threadMessages.value, optimistic];
    scrollCommentsToBottomSoon();

    try {
        threadSending.value = true;
        const response = await axios.post(route('task-threads.store', { task: editTask.value.id }), {
            content: html,
            type: 'external',
            temp_identifier: threadTempIdentifier.value,
        });

        const thread = response.data?.thread ?? response.data;
        const serverMsg = mapThreadToMessage<TaskAttachment>(thread);
        threadMessages.value = [...threadMessages.value.filter((m) => m.clientId !== localId), serverMsg];
        threadTempIdentifier.value = Date.now().toString();
        threadComposerHtml.value = '';
        threadComposerRef.value?.reset?.();
        scrollCommentsToBottomSoon();
    } catch (e: any) {
        threadMessages.value = threadMessages.value.map((m) =>
            m.clientId === localId ? { ...m, pending: false, failed: true, time: 'Failed to send' } : m,
        );
        threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to send comment';
    } finally {
        threadSending.value = false;
    }
}

function startThreadEdit(message: ThreadMessage) {
    if (!editTask.value) return;
    if (!message.id || !message.isYou || message.pending) return;

    threadEditingId.value = message.id;
    threadEditError.value = null;
    threadTempIdentifier.value = Date.now().toString();
    threadComposerHtml.value = message.content;

    void nextTick().then(() => {
        threadComposerRef.value?.editor?.chain().focus().run();
        scrollCommentsToBottomSoon();
    });
}

function startReplyToMessage(message: ThreadMessage) {
    if (!editTask.value) return;
    if (!message.id || message.pending) return;

    if (threadEditingId.value) {
        cancelThreadEdit();
    }

    threadEditError.value = null;
    threadTempIdentifier.value = Date.now().toString();
    const quoteHtml = buildReplyQuoteHtml(message);
    const editor = threadComposerRef.value?.editor;

    if (editor) {
        const currentHtml = editor.getHTML();
        const hasContent = editor.getText().trim().length > 0 || currentHtml.replace(/<p><\/p>/g, '').trim().length > 0;

        if (hasContent) {
            editor.chain().focus('end').insertContent(quoteHtml).run();
        } else {
            editor.commands.setContent(quoteHtml, false);
        }
        threadComposerHtml.value = editor.getHTML();
    } else {
        threadComposerHtml.value = threadComposerHtml.value.trim() ? `${threadComposerHtml.value}${quoteHtml}` : quoteHtml;
    }

    void nextTick().then(() => {
        threadComposerRef.value?.editor?.chain().focus('end').run();
        scrollCommentsToBottomSoon();
    });
}

function cancelThreadEdit() {
    threadEditingId.value = null;
    threadComposerHtml.value = '';
    threadEditError.value = null;
    threadEditSaving.value = false;
    threadTempIdentifier.value = Date.now().toString();
    threadComposerRef.value?.reset?.();
    contextMenuMessageId.value = null;
    contextMenuSelectionText.value = '';
}

function onMessageDblClick(message: ThreadMessage, event: MouseEvent) {
    if (shouldIgnoreEditGestureForEvent(event)) return;
    startThreadEdit(message);
}

function onMessageTouchEnd(message: ThreadMessage, event: TouchEvent) {
    if (shouldIgnoreEditGestureForEvent(event)) return;
    if (!message.isYou || !message.id || message.pending) return;

    const { isDoubleTap, nextTapState } = resolveTouchTap(message.id, {
        lastTapAt: lastTouchTapAt.value,
        lastTapId: lastTouchTapId.value,
    });
    lastTouchTapAt.value = nextTapState.lastTapAt;
    lastTouchTapId.value = nextTapState.lastTapId;

    if (isDoubleTap) {
        startThreadEdit(message);
    }
}

async function deleteThreadMessage(message: ThreadMessage) {
    if (!editTask.value) return;
    if (!message.id || !message.isYou || message.pending) return;
    if (!confirm('Delete this message?')) return;

    try {
        await axios.delete(route('task-threads.destroy', { task: editTask.value.id, thread: message.id }));
        threadMessages.value = threadMessages.value.filter((m) => m.id !== message.id);
        if (threadEditingId.value === message.id) {
            cancelThreadEdit();
        }
    } catch (e: any) {
        threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to delete comment';
    }
}

async function deleteTask(taskId: number) {
    if (!confirm('Are you sure you want to delete this task?')) return;

    deleteLoading.value = taskId;
    error.value = null;
    try {
        await axios.delete(route('tasks.v2.destroy', { task: taskId }));
        router.reload({ preserveScroll: true, preserveState: true });
    } catch (e: any) {
        error.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to delete task';
    } finally {
        deleteLoading.value = null;
    }
}

function goToPage(page: number) {
    const current = Number(tasksPage.value.current_page ?? 1);
    const last = Number(tasksPage.value.last_page ?? 1);
    const next = Math.max(1, Math.min(last, page));
    if (next === current) return;

    router.get(
        '/tasks',
        {
            status: appliedStatuses.value,
            priority: appliedPriorities.value,
            search: appliedSearchTerm.value || undefined,
            environment: appliedEnvironmentTerm.value || undefined,
            sort_by: appliedSortBy.value,
            page: next,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function getTaskEnvironmentLabel(task: Task): string {
    return getTaskEnvironment(task) ?? 'Unknown';
}

function handleTaskCreated(taskId: number | null) {
    router.reload({
        only: ['tasks', 'filters', 'projects'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (taskId !== null) {
                highlightTask(taskId);
            }
        },
    });
}
</script>

<template>
    <Head title="Tasks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <Card class="w-full">
                <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle>Tasks</CardTitle>
                        <p class="text-muted-foreground text-sm">Default view hides completed tasks.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Sheet v-model:open="filtersOpen">
                            <SheetTrigger as-child>
                                <Button variant="outline" size="sm" data-testid="filters-trigger">
                                    <Filter class="mr-2 h-4 w-4" />
                                    Filters
                                    <Badge v-if="activeFilterCount" variant="secondary" class="ml-2">
                                        {{ activeFilterCount }}
                                    </Badge>
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="right" class="flex h-full flex-col p-0">
                                <SheetHeader class="p-0">
                                    <div class="px-6 pt-6 pb-3">
                                        <SheetTitle>Filters</SheetTitle>
                                        <SheetDescription class="text-muted-foreground mt-1 text-sm"> Refine your task list. </SheetDescription>
                                    </div>
                                </SheetHeader>

                                <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 pb-6">
                                    <div class="space-y-2">
                                        <Label class="text-muted-foreground">Search</Label>
                                        <Input v-model="draftSearchTerm" data-testid="filter-search" placeholder="Search by title" />
                                    </div>

                                    <div class="space-y-2">
                                        <Label class="text-muted-foreground">Environment</Label>
                                        <Input v-model="draftEnvironmentTerm" data-testid="filter-environment" placeholder="e.g. Production" />
                                    </div>

                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <Label class="text-muted-foreground">Status</Label>
                                            <Button variant="ghost" size="sm" @click="selectAllStatuses">All</Button>
                                        </div>
                                        <div class="grid gap-2">
                                            <label v-for="option in statusOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="draftStatuses"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    :data-testid="`status-${option.value}`"
                                                />
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <Label class="text-muted-foreground">Priority</Label>
                                            <Button variant="ghost" size="sm" @click="selectAllPriorities">All</Button>
                                        </div>
                                        <div class="grid gap-2">
                                            <label v-for="option in priorityOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="draftPriorities"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    :data-testid="`priority-${option.value}`"
                                                />
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <Label class="text-muted-foreground">Sort By</Label>
                                        <ButtonGroup
                                            v-model="draftSortBy"
                                            test-id-prefix="sort-by"
                                            :options="sortByOptions"
                                            :columns="3"
                                            aria-label="Sort tasks"
                                        />
                                    </div>
                                </div>

                                <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                                    <Button data-testid="filters-reset" variant="ghost" @click="resetFilters">Reset</Button>
                                    <Button data-testid="filters-apply" variant="default" @click="applyFilters">Apply</Button>
                                </SheetFooter>
                            </SheetContent>
                        </Sheet>

                        <TaskCreateSheet :projects="props.projects" @created="handleTaskCreated" />
                    </div>
                </CardHeader>

                <CardContent>
                    <div class="text-muted-foreground mb-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span> Showing {{ tasksPage.from ?? 0 }} to {{ tasksPage.to ?? 0 }} of {{ tasksPage.total ?? taskRows.length }} tasks </span>
                        <span v-if="activeFilterCount">{{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active</span>
                    </div>

                    <div v-if="error" class="text-destructive py-2 text-center text-sm">{{ error }}</div>

                    <div v-if="taskRows.length === 0" class="text-muted-foreground py-8 text-center">No tasks found</div>

                    <ul v-else class="divide-border divide-y">
                        <li
                            v-for="task in taskRows"
                            :key="task.id"
                            data-testid="task-row"
                            :class="
                                highlightedTaskId === task.id
                                    ? 'ring-offset-background rounded-lg bg-sky-500/10 ring-2 ring-sky-500/40 ring-offset-2'
                                    : ''
                            "
                            class="flex flex-col gap-3 py-4 transition sm:flex-row sm:items-center sm:gap-4"
                        >
                            <div class="flex-1">
                                <div class="text-card-foreground text-lg font-medium">{{ task.title }}</div>
                                <div class="text-muted-foreground mt-1 flex flex-wrap items-center gap-2 text-xs">
                                    <Badge :class="getStatusBadgeClass(task.status)" :data-testid="`task-status-badge-${task.id}`" variant="outline">
                                        {{ getStatusLabel(task.status) }}
                                    </Badge>
                                    <Badge
                                        :class="getPriorityBadgeClass(task.priority)"
                                        :data-testid="`task-priority-badge-${task.id}`"
                                        variant="outline"
                                    >
                                        {{ getPriorityLabel(task.priority) }}
                                    </Badge>
                                    <Badge :data-testid="`task-environment-badge-${task.id}`" variant="outline">
                                        {{ getTaskEnvironmentLabel(task) }}
                                    </Badge>
                                </div>
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <Button size="sm" title="Edit" variant="outline" @click="openEdit(task.id)">
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <Button
                                    :disabled="deleteLoading === task.id"
                                    size="sm"
                                    title="Delete"
                                    variant="destructive"
                                    @click="deleteTask(task.id)"
                                >
                                    <span v-if="deleteLoading === task.id">Deleting...</span>
                                    <Trash2 v-else class="h-4 w-4" />
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <div v-if="(tasksPage.last_page ?? 1) > 1" class="mt-4 flex items-center justify-between border-t pt-4">
                        <div class="text-muted-foreground text-xs">Page {{ tasksPage.current_page ?? 1 }} of {{ tasksPage.last_page ?? 1 }}</div>
                        <div class="flex items-center gap-2">
                            <Button
                                :disabled="(tasksPage.current_page ?? 1) <= 1"
                                size="sm"
                                variant="outline"
                                @click="goToPage((tasksPage.current_page ?? 1) - 1)"
                            >
                                Previous
                            </Button>
                            <Button
                                :disabled="(tasksPage.current_page ?? 1) >= (tasksPage.last_page ?? 1)"
                                size="sm"
                                variant="outline"
                                @click="goToPage((tasksPage.current_page ?? 1) + 1)"
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Sheet :open="editOpen" @update:open="onEditOpenChange">
            <SheetContent side="right" class="flex h-full flex-col p-0" width-preset="task">
                <form class="flex h-full min-h-0 flex-col" data-testid="edit-form">
                    <SheetHeader class="sr-only">
                        <SheetTitle>Task</SheetTitle>
                    </SheetHeader>

                    <div v-if="editTask && !editLoading && !editError" class="border-b px-6 py-4 lg:hidden">
                        <ButtonGroup
                            v-model="editMobilePane"
                            aria-label="Edit task section"
                            test-id-prefix="edit-mobile-pane"
                            :options="editMobilePaneOptions"
                            :columns="2"
                        />
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 pt-6 pb-10 lg:min-h-0 lg:overflow-hidden lg:py-10" @click="onRichContentClick">
                        <div v-if="editLoading" class="text-muted-foreground py-8 text-center">Loading task...</div>
                        <div v-else-if="editError" class="text-destructive py-8 text-center">{{ editError }}</div>
                        <div v-else-if="editTask" class="grid gap-6 lg:h-full lg:min-h-0 lg:grid-cols-2">
                            <div
                                :class="[editMobilePane === 'comments' ? 'hidden lg:block' : 'block', 'space-y-6 pr-1 lg:min-h-0 lg:overflow-y-auto']"
                            >
                                <div class="border-muted-foreground/20 bg-muted/10 grid gap-2 rounded-lg border p-3 text-xs">
                                    <div v-if="editTask.created_at" class="text-muted-foreground" data-testid="edit-task-created-at">
                                        Created {{ formatThreadTime(editTask.created_at) }}
                                    </div>
                                    <div v-if="editTask.updated_at" class="text-muted-foreground" data-testid="edit-task-updated-at">
                                        Updated {{ formatThreadTime(editTask.updated_at) }}
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-muted-foreground">Environment</span>
                                        <span class="text-foreground font-medium" data-testid="edit-task-environment">{{
                                            editTaskEnvironmentLabel
                                        }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-muted-foreground">Created by</span>
                                        <span class="text-foreground font-medium" data-testid="edit-task-created-by">{{ editTaskCreatorLabel }}</span>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <Label class="text-muted-foreground">Task</Label>
                                    <template v-if="isOwner">
                                        <Input v-model="editForm.title" placeholder="Short, descriptive title" required />
                                    </template>
                                    <template v-else>
                                        <div
                                            class="border-muted-foreground/30 bg-muted/10 text-foreground rounded-md border border-dashed p-3 text-sm"
                                        >
                                            {{ editTask.title }}
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-2">
                                    <Label class="text-muted-foreground">Status</Label>
                                    <ButtonGroup
                                        v-model="editForm.status"
                                        aria-label="Task status"
                                        test-id-prefix="task-status"
                                        :disabled="editLoading || editUploading"
                                        :options="statusOptions"
                                        :columns="2"
                                        class="xl:grid-cols-4"
                                    />
                                </div>

                                <div class="space-y-2">
                                    <Label class="text-muted-foreground">Priority</Label>
                                    <template v-if="isOwner">
                                        <ButtonGroup
                                            v-model="editForm.priority"
                                            aria-label="Task priority"
                                            test-id-prefix="task-priority"
                                            :options="priorityOptions"
                                            :columns="3"
                                        />
                                    </template>
                                    <template v-else>
                                        <div
                                            class="border-muted-foreground/30 bg-muted/10 text-foreground inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                        >
                                            {{ getPriorityLabel(editTask.priority) }}
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-2">
                                    <Label class="text-muted-foreground">Description</Label>
                                    <template v-if="isOwner">
                                        <ShiftEditor
                                            v-model="editForm.description"
                                            :enable-ai-improve="aiImproveEnabled"
                                            :temp-identifier="editTempIdentifier"
                                            placeholder="Update the task details and drag files inline."
                                            @uploading="editUploading = $event"
                                        />
                                    </template>
                                    <template v-else>
                                        <div class="border-muted-foreground/30 bg-muted/10 text-foreground rounded-lg border p-4 text-sm">
                                            <div
                                                v-if="editTask.description"
                                                class="tiptap shift-rich [&_img]:max-w-full [&_img]:cursor-zoom-in [&_img]:rounded-lg [&_img]:shadow-sm [&_img.editor-tile]:aspect-square [&_img.editor-tile]:w-[200px] [&_img.editor-tile]:max-w-[200px] [&_img.editor-tile]:object-cover"
                                                v-html="renderRichContent(editTask.description)"
                                            ></div>
                                            <div v-else class="text-muted-foreground">No description provided.</div>
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-2">
                                    <TaskCollaboratorField
                                        :model-value="editForm.collaborators"
                                        :disabled="editLoading || editUploading"
                                        :environment="editForm.environment"
                                        :project-id="editTask.project_id ?? null"
                                        :read-only="!canManageCollaborators"
                                        @update:model-value="updateEditCollaborators"
                                    />
                                    <p v-if="canManageCollaborators" class="text-muted-foreground text-xs">
                                        Adding collaborators here sends access notifications to newly added collaborators only.
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label class="text-muted-foreground">Attachments</Label>
                                    <div v-if="taskAttachments.length" class="space-y-2">
                                        <div
                                            v-for="attachment in taskAttachments"
                                            :key="attachment.id"
                                            class="border-muted-foreground/20 bg-muted/10 text-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                        >
                                            <a
                                                :href="attachment.url"
                                                class="hover:text-foreground min-w-0 flex-1 truncate transition"
                                                rel="noreferrer"
                                                target="_blank"
                                            >
                                                {{ attachment.original_filename }}
                                            </a>
                                            <Button
                                                v-if="isOwner"
                                                size="sm"
                                                type="button"
                                                variant="outline"
                                                @click="removeAttachmentFromTask(attachment.id)"
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    </div>
                                    <div
                                        v-else
                                        class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
                                    >
                                        No attachments available
                                    </div>
                                </div>
                            </div>

                            <div
                                :class="[
                                    editMobilePane === 'details' ? 'hidden lg:flex' : 'flex',
                                    'border-muted-foreground/10 via-background to-background max-h-[70vh] min-h-[28rem] flex-col overflow-hidden rounded-2xl border bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-900/5 lg:h-full lg:max-h-none lg:min-h-0',
                                ]"
                            >
                                <div class="border-muted-foreground/10 flex items-center justify-between border-b px-4 py-3">
                                    <div>
                                        <h3 class="text-foreground text-sm font-semibold">Comments</h3>
                                    </div>
                                    <div class="text-muted-foreground text-xs">
                                        {{ threadMessages.length }} message{{ threadMessages.length === 1 ? '' : 's' }}
                                    </div>
                                </div>

                                <div
                                    ref="commentsScrollRef"
                                    class="flex-1 space-y-3 overflow-auto px-4 py-4"
                                    @load.capture="onCommentsMediaLoadCapture"
                                >
                                    <div v-if="threadLoading" class="text-muted-foreground py-6 text-center text-sm">Loading comments...</div>
                                    <div v-else-if="threadError" class="text-destructive py-6 text-center text-sm">{{ threadError }}</div>
                                    <div v-else-if="threadMessages.length === 0" class="text-muted-foreground py-6 text-center text-sm">
                                        No comments yet.
                                    </div>
                                    <div
                                        v-for="message in threadMessages"
                                        :key="message.clientId"
                                        :class="message.isYou ? 'justify-end' : 'justify-start'"
                                        class="flex"
                                    >
                                        <div class="max-w-[86%]">
                                            <ContextMenuRoot @update:open="(open) => onCommentContextMenuOpen(message, open)">
                                                <ContextMenuTrigger as-child>
                                                    <div
                                                        :id="message.id ? `comment-${message.id}` : undefined"
                                                        :data-testid="message.id ? `comment-bubble-${message.id}` : undefined"
                                                        :class="
                                                            message.isYou
                                                                ? 'rounded-br-md bg-sky-600 text-white'
                                                                : 'border-muted-foreground/10 bg-background/70 text-foreground rounded-bl-md border'
                                                        "
                                                        class="rounded-lg px-3 py-2 text-sm shadow-sm"
                                                        @dblclick="onMessageDblClick(message, $event)"
                                                        @touchend="onMessageTouchEnd(message, $event)"
                                                    >
                                                        <div v-if="!message.isYou" class="text-foreground/80 mb-1 text-[11px] font-semibold">
                                                            {{ message.author }}
                                                        </div>
                                                        <div
                                                            class="shift-rich text-inherit [&_img]:my-2 [&_img]:max-w-full [&_img]:cursor-zoom-in [&_img]:rounded-lg [&_img]:shadow-sm [&_img.editor-tile]:aspect-square [&_img.editor-tile]:w-[200px] [&_img.editor-tile]:max-w-[200px] [&_img.editor-tile]:object-cover"
                                                            @click="onRichContentClick"
                                                            v-html="renderRichContent(message.content)"
                                                        ></div>
                                                        <div v-if="message.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                                                            <a
                                                                v-for="attachment in message.attachments"
                                                                :key="attachment.id"
                                                                :href="attachment.url"
                                                                :class="
                                                                    message.isYou
                                                                        ? 'border-white/20 bg-white/10 text-white hover:bg-white/15'
                                                                        : 'border-muted-foreground/20 bg-muted/20 text-foreground hover:bg-muted/30'
                                                                "
                                                                class="inline-flex max-w-[260px] items-center gap-1.5 truncate rounded-md border px-2 py-1 text-xs transition"
                                                                rel="noreferrer"
                                                                target="_blank"
                                                            >
                                                                <Paperclip class="h-3 w-3 shrink-0 opacity-80" />
                                                                <span class="min-w-0 truncate">{{ attachment.original_filename }}</span>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </ContextMenuTrigger>
                                                <ContextMenuPortal>
                                                    <ContextMenuContent
                                                        class="bg-popover text-popover-foreground z-50 min-w-[10rem] overflow-hidden rounded-md border p-1 shadow-md"
                                                    >
                                                        <ContextMenuItem
                                                            v-if="!message.isYou"
                                                            class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                                            @select="copyEntireMessage(message)"
                                                        >
                                                            Copy
                                                        </ContextMenuItem>
                                                        <ContextMenuItem
                                                            v-if="shouldShowCopySelection(message)"
                                                            class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                                            @select="copySelectedMessage"
                                                        >
                                                            Copy selection
                                                        </ContextMenuItem>
                                                        <ContextMenuItem
                                                            v-if="!message.isYou && message.id && !message.pending"
                                                            class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                                            @select="startReplyToMessage(message)"
                                                        >
                                                            Reply
                                                        </ContextMenuItem>
                                                        <ContextMenuSeparator
                                                            v-if="!message.isYou && message.id && !message.pending"
                                                            class="bg-border -mx-1 my-1 h-px"
                                                        />
                                                        <ContextMenuItem
                                                            v-if="message.isYou && message.id && !message.pending"
                                                            class="hover:bg-accent hover:text-accent-foreground relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                                            @select="startThreadEdit(message)"
                                                        >
                                                            Edit
                                                        </ContextMenuItem>
                                                        <ContextMenuSeparator
                                                            v-if="message.isYou && message.id && !message.pending"
                                                            class="bg-border -mx-1 my-1 h-px"
                                                        />
                                                        <ContextMenuItem
                                                            v-if="message.isYou && message.id && !message.pending"
                                                            class="text-destructive hover:bg-accent hover:text-destructive relative flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none"
                                                            @select="deleteThreadMessage(message)"
                                                        >
                                                            Delete
                                                        </ContextMenuItem>
                                                    </ContextMenuContent>
                                                </ContextMenuPortal>
                                            </ContextMenuRoot>
                                            <div :class="message.isYou ? 'text-right' : 'text-left'" class="text-muted-foreground mt-1 text-[11px]">
                                                {{ message.time }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="border-muted-foreground/10 bg-background/80 border-t px-4 py-3 backdrop-blur">
                                    <div v-if="threadEditError" class="text-destructive mb-2 text-xs">{{ threadEditError }}</div>
                                    <ShiftEditor
                                        ref="threadComposerRef"
                                        v-model="threadComposerHtml"
                                        :enable-ai-improve="aiImproveEnabled"
                                        :ai-context="threadAiContext"
                                        :cancelable="Boolean(threadEditingId)"
                                        :clear-on-send="false"
                                        :temp-identifier="threadTempIdentifier"
                                        data-testid="comments-editor"
                                        :placeholder="threadEditingId ? 'Edit your comment...' : 'Write a comment...'"
                                        @cancel="cancelThreadEdit"
                                        @uploading="threadComposerUploading = $event"
                                        @send="handleThreadSend"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <Dialog :open="confirmCloseOpen" @update:open="confirmCloseOpen = $event">
            <DialogContent class="sm:max-w-md">
                <div class="space-y-2">
                    <div class="text-base font-semibold">Discard changes?</div>
                    <div class="text-muted-foreground text-sm">You have unsaved changes. If you close now, they will be lost.</div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <Button type="button" variant="outline" @click="confirmCloseOpen = false">Cancel</Button>
                    <Button type="button" variant="destructive" @click="discardChangesAndClose">Discard</Button>
                </div>
            </DialogContent>
        </Dialog>

        <ImageLightbox v-model:open="lightboxOpen" :alt="lightboxAlt" :src="lightboxSrc" />
    </AppLayout>
</template>
