<script lang="ts" setup>
/* eslint-disable max-lines */
import ShiftEditor from '@/components/ShiftEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ImageLightbox } from '@/components/ui/image-lightbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Filter, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type Task = {
    id: number;
    title: string;
    status: string;
    priority: string;
};

type TaskAttachment = {
    id: number;
    original_filename: string;
    url?: string;
    path?: string;
};

type TaskDetail = Task & {
    description?: string;
    created_at?: string;
    submitter?: { name?: string; email?: string } | null;
    attachments?: TaskAttachment[];
    is_owner?: boolean;
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

const props = defineProps<{
    tasks: Task[];
    filters: {
        status?: string[] | string | null;
    };
}>();

const tasks = ref<Task[]>([...props.tasks]);
watch(
    () => props.tasks,
    (next) => {
        tasks.value = [...next];
    },
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/tasks' },
    { title: 'Tasks V2', href: '/tasks-v2' },
];

const statusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'in-progress', label: 'In Progress' },
    { value: 'awaiting-feedback', label: 'Awaiting Feedback' },
    { value: 'completed', label: 'Completed' },
];

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const defaultStatuses = statusOptions.filter((option) => option.value !== 'completed').map((option) => option.value);

function normalizeStringList(value: unknown): string[] {
    if (Array.isArray(value)) return value.map(String).filter((item) => item.trim().length > 0);
    if (typeof value === 'string' && value.trim().length > 0) return [value.trim()];
    return [];
}

const filtersOpen = ref(false);
const searchTerm = ref('');
const error = ref<string | null>(null);
const deleteLoading = ref<number | null>(null);

const providedStatuses = normalizeStringList(props.filters.status);
const selectedStatuses = ref<string[]>(providedStatuses.length ? providedStatuses : [...defaultStatuses]);

const selectedPriorities = ref<string[]>(priorityOptions.map((option) => option.value));

const activeFilterCount = computed(() => {
    let count = 0;
    if (selectedStatuses.value.length && selectedStatuses.value.length < statusOptions.length) count += 1;
    if (selectedPriorities.value.length && selectedPriorities.value.length < priorityOptions.length) count += 1;
    if (searchTerm.value.trim()) count += 1;
    return count;
});

function visitWithFilters() {
    router.get(
        '/tasks-v2',
        { status: selectedStatuses.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(
    selectedStatuses,
    () => {
        visitWithFilters();
    },
    { deep: true },
);

function resetFilters() {
    selectedStatuses.value = [...defaultStatuses];
    selectedPriorities.value = priorityOptions.map((option) => option.value);
    searchTerm.value = '';
}

function selectAllStatuses() {
    selectedStatuses.value = statusOptions.map((option) => option.value);
}

function selectAllPriorities() {
    selectedPriorities.value = priorityOptions.map((option) => option.value);
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
    description: '',
});

const threadTempIdentifier = ref(Date.now().toString());
const threadLoading = ref(false);
const threadSending = ref(false);
const threadError = ref<string | null>(null);
const threadMessages = ref<ThreadMessage[]>([]);

const threadEditingId = ref<number | null>(null);
const threadEditHtml = ref('');
const threadEditTempIdentifier = ref(Date.now().toString());
const threadEditUploading = ref(false);
const threadEditSaving = ref(false);
const threadEditError = ref<string | null>(null);

const commentsScrollRef = ref<HTMLElement | null>(null);

const lightboxOpen = ref(false);
const lightboxSrc = ref('');
const lightboxAlt = ref('');

const isOwner = computed(() => Boolean(editTask.value?.is_owner));

const taskAttachments = computed(() => {
    if (!editTask.value?.attachments) return [];
    const removed = new Set(deletedAttachmentIds.value);
    return editTask.value.attachments.filter((attachment) => !removed.has(attachment.id));
});

function openLightboxForImage(img: HTMLImageElement) {
    const src = img.currentSrc || img.src;
    if (!src) return;
    lightboxSrc.value = src;
    lightboxAlt.value = img.alt || img.title || 'Image';
    lightboxOpen.value = true;
}

function onRichContentClick(event: MouseEvent) {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    const img = target.closest('img') as HTMLImageElement | null;
    if (!img) return;
    const inRich = Boolean(img.closest('.shift-rich')) || Boolean(img.closest('.tiptap')) || img.classList.contains('editor-tile');
    if (!inRich) return;
    event.preventDefault();
    event.stopPropagation();
    openLightboxForImage(img);
}

function shouldHandleImage(img: HTMLImageElement) {
    const inRich = Boolean(img.closest('.shift-rich')) || Boolean(img.closest('.tiptap')) || img.classList.contains('editor-tile');
    if (!inRich) return { ok: false, inEditable: false };
    const inEditable = Boolean(img.closest('[contenteditable="true"]'));
    return { ok: true, inEditable };
}

function onGlobalClickCapture(event: MouseEvent) {
    if (!editOpen.value) return;
    const target = event.target as HTMLElement | null;
    if (!target) return;
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

onMounted(() => {
    document.addEventListener('click', onGlobalClickCapture, true);
    document.addEventListener('dblclick', onGlobalDblClickCapture, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onGlobalClickCapture, true);
    document.removeEventListener('dblclick', onGlobalDblClickCapture, true);
});

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

function formatThreadTime(value: any): string {
    if (!value) return '';
    const date = value instanceof Date ? value : new Date(String(value));
    if (Number.isNaN(date.getTime())) return String(value);

    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startYesterday = new Date(startToday);
    startYesterday.setDate(startToday.getDate() - 1);

    const time = new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);

    if (date >= startToday) return time;
    if (date >= startYesterday && date < startToday) return `Yesterday - ${time}`;

    const day = new Intl.DateTimeFormat('en-GB', { day: '2-digit' }).format(date);
    const month = new Intl.DateTimeFormat('en-GB', { month: 'short' }).format(date);
    return `${day} ${month} ${time}`;
}

function mapThreadToMessage(thread: any): ThreadMessage {
    const id = typeof thread?.id === 'number' ? (thread.id as number) : undefined;
    const author = String(thread?.sender_name ?? thread?.author ?? 'Unknown');
    const isYou = Boolean(thread?.is_current_user ?? thread?.isYou);
    const content = String(thread?.content ?? '');
    const time = formatThreadTime(thread?.created_at);
    const attachments = Array.isArray(thread?.attachments) ? (thread.attachments as TaskAttachment[]) : [];
    return {
        clientId: id ? `thread-${id}` : `thread-${Date.now()}`,
        id,
        author,
        time,
        content,
        isYou,
        attachments,
    };
}

async function fetchThreads(taskId: number) {
    threadLoading.value = true;
    threadError.value = null;
    try {
        const response = await axios.get(route('task-threads.index', { task: taskId }));
        const list = Array.isArray(response.data?.external) ? response.data.external : [];
        threadMessages.value = list.map(mapThreadToMessage);
        scrollCommentsToBottomSoon();
    } catch (e: any) {
        threadError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to load comments';
    } finally {
        threadLoading.value = false;
    }
}

async function openEdit(taskId: number) {
    editOpen.value = true;
    editLoading.value = true;
    editError.value = null;
    editTask.value = null;
    editUploading.value = false;
    deletedAttachmentIds.value = [];
    threadMessages.value = [];
    threadError.value = null;
    threadTempIdentifier.value = Date.now().toString();

    try {
        const response = await axios.get(route('tasks.v2.show', { task: taskId }));
        const data = response.data as TaskDetail;
        editTask.value = data;
        editForm.value = {
            title: data?.title ?? '',
            priority: data?.priority ?? 'medium',
            description: data?.description ?? '',
        };
        editTempIdentifier.value = Date.now().toString();
        void fetchThreads(taskId);
    } catch (e: any) {
        editError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to fetch task';
    } finally {
        editLoading.value = false;
    }
}

function closeEdit() {
    editOpen.value = false;
    editTask.value = null;
    editError.value = null;
    editUploading.value = false;
    deletedAttachmentIds.value = [];
    threadMessages.value = [];
    threadError.value = null;
}

async function saveEdit() {
    if (!editTask.value || !isOwner.value) return;

    editError.value = null;
    editLoading.value = true;
    try {
        const payload = {
            title: editForm.value.title,
            description: editForm.value.description,
            priority: editForm.value.priority,
            temp_identifier: editTempIdentifier.value,
            deleted_attachment_ids: deletedAttachmentIds.value.length ? deletedAttachmentIds.value : undefined,
        };

        await axios.put(route('tasks.v2.update', { task: editTask.value.id }), payload);

        const idx = tasks.value.findIndex((t) => t.id === editTask.value?.id);
        if (idx !== -1) {
            tasks.value[idx] = {
                ...tasks.value[idx],
                title: editForm.value.title,
                priority: editForm.value.priority,
            };
        }

        closeEdit();
    } catch (e: any) {
        editError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to update task';
    } finally {
        editLoading.value = false;
    }
}

function removeAttachmentFromTask(attachmentId: number) {
    if (!deletedAttachmentIds.value.includes(attachmentId)) {
        deletedAttachmentIds.value = [...deletedAttachmentIds.value, attachmentId];
    }
}

async function handleThreadSend(payload: { html: string }) {
    if (!editTask.value) return;
    const html = payload?.html?.trim();
    if (!html) return;

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
        const serverMsg = mapThreadToMessage(thread);
        threadMessages.value = [...threadMessages.value.filter((m) => m.clientId !== localId), serverMsg];
        threadTempIdentifier.value = Date.now().toString();
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
    threadEditHtml.value = message.content;
    threadEditTempIdentifier.value = Date.now().toString();
    threadEditError.value = null;
    threadEditUploading.value = false;
}

function cancelThreadEdit() {
    threadEditingId.value = null;
    threadEditHtml.value = '';
    threadEditTempIdentifier.value = Date.now().toString();
    threadEditError.value = null;
    threadEditUploading.value = false;
    threadEditSaving.value = false;
}

async function saveThreadEdit(htmlOverride?: string) {
    if (!editTask.value) return;
    if (!threadEditingId.value) return;

    const html = (htmlOverride ?? threadEditHtml.value)?.trim();
    if (!html) return;

    threadEditSaving.value = true;
    threadEditError.value = null;
    try {
        const response = await axios.put(route('task-threads.update', { task: editTask.value.id, thread: threadEditingId.value }), {
            content: html,
            temp_identifier: threadEditTempIdentifier.value,
        });

        const thread = response.data?.thread ?? response.data;
        const serverMsg = mapThreadToMessage(thread);
        threadMessages.value = threadMessages.value.map((m) =>
            m.id === threadEditingId.value ? { ...m, content: serverMsg.content, attachments: serverMsg.attachments } : m,
        );
        cancelThreadEdit();
        scrollCommentsToBottomSoon();
    } catch (e: any) {
        threadEditError.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to update comment';
    } finally {
        threadEditSaving.value = false;
    }
}

async function deleteTask(taskId: number) {
    if (!confirm('Are you sure you want to delete this task?')) return;

    deleteLoading.value = taskId;
    error.value = null;
    try {
        await axios.delete(route('tasks.v2.destroy', { task: taskId }));
        tasks.value = tasks.value.filter((task) => task.id !== taskId);
    } catch (e: any) {
        error.value = e.response?.data?.error || e.response?.data?.message || e.message || 'Failed to delete task';
    } finally {
        deleteLoading.value = null;
    }
}

const filteredTasks = computed(() => {
    let list = [...tasks.value];

    if (selectedStatuses.value.length === 0) return [];
    if (selectedStatuses.value.length < statusOptions.length) {
        list = list.filter((task) => selectedStatuses.value.includes(task.status));
    }

    if (selectedPriorities.value.length === 0) return [];
    if (selectedPriorities.value.length < priorityOptions.length) {
        list = list.filter((task) => selectedPriorities.value.includes(task.priority));
    }

    const query = searchTerm.value.trim().toLowerCase();
    if (query) {
        list = list.filter((task) => task.title.toLowerCase().includes(query));
    }

    return list;
});

const totalTasks = computed(() => tasks.value.length);

function statusVariant(status: string) {
    switch (status) {
        case 'pending':
            return 'secondary';
        case 'in-progress':
            return 'default';
        case 'completed':
            return 'outline';
        default:
            return 'secondary';
    }
}

function priorityVariant(priority: string) {
    switch (priority) {
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        default:
            return 'outline';
    }
}

function getStatusLabel(value: string) {
    return statusOptions.find((option) => option.value === value)?.label ?? value;
}

function getPriorityLabel(value: string) {
    return priorityOptions.find((option) => option.value === value)?.label ?? value;
}
</script>

<template>
    <Head title="Tasks V2" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <Card class="w-full">
                <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle>Tasks V2</CardTitle>
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
                            <SheetContent side="right" class="flex h-full w-[320px] flex-col p-0">
                                <SheetHeader class="p-0">
                                    <div class="px-6 pt-6 pb-3">
                                        <SheetTitle>Filters</SheetTitle>
                                        <SheetDescription class="text-muted-foreground mt-1 text-sm"> Refine your task list. </SheetDescription>
                                    </div>
                                </SheetHeader>

                                <div class="flex-1 space-y-6 overflow-auto px-6 pb-6">
                                    <div class="space-y-2">
                                        <Label>Search</Label>
                                        <Input v-model="searchTerm" data-testid="filter-search" placeholder="Search by title" />
                                    </div>

                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <Label>Status</Label>
                                            <Button variant="ghost" size="sm" @click="selectAllStatuses">All</Button>
                                        </div>
                                        <div class="grid gap-2">
                                            <label v-for="option in statusOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="selectedStatuses"
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
                                            <Label>Priority</Label>
                                            <Button variant="ghost" size="sm" @click="selectAllPriorities">All</Button>
                                        </div>
                                        <div class="grid gap-2">
                                            <label v-for="option in priorityOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                                <input
                                                    v-model="selectedPriorities"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    :data-testid="`priority-${option.value}`"
                                                />
                                                <span>{{ option.label }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                                    <Button variant="ghost" @click="resetFilters">Reset</Button>
                                    <Button variant="default" @click="filtersOpen = false">Apply</Button>
                                </SheetFooter>
                            </SheetContent>
                        </Sheet>
                    </div>
                </CardHeader>

                <CardContent>
                    <div class="text-muted-foreground mb-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span> Showing {{ filteredTasks.length }} of {{ totalTasks }} tasks </span>
                        <span v-if="activeFilterCount">{{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active</span>
                    </div>

                    <div v-if="error" class="text-destructive py-2 text-center text-sm">{{ error }}</div>

                    <div v-if="filteredTasks.length === 0" class="text-muted-foreground py-8 text-center">No tasks found</div>

                    <ul v-else class="divide-border divide-y">
                        <li
                            v-for="task in filteredTasks"
                            :key="task.id"
                            data-testid="task-row"
                            class="flex flex-col gap-3 py-4 transition sm:flex-row sm:items-center sm:gap-4"
                        >
                            <div class="flex-1">
                                <div class="text-card-foreground text-lg font-medium">{{ task.title }}</div>
                                <div class="text-muted-foreground mt-1 flex flex-wrap items-center gap-2 text-xs">
                                    <Badge :variant="statusVariant(task.status)">{{ getStatusLabel(task.status) }}</Badge>
                                    <Badge :variant="priorityVariant(task.priority)">{{ getPriorityLabel(task.priority) }}</Badge>
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
                </CardContent>
            </Card>
        </div>

        <Sheet v-model:open="editOpen">
            <SheetContent side="right" class="flex h-full w-full max-w-none flex-col p-0 sm:w-1/2 sm:max-w-none">
                <form class="flex h-full flex-col" @submit.prevent="saveEdit">
                    <SheetHeader class="sr-only">
                        <SheetTitle>Task</SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 overflow-hidden px-6 py-10" @click="onRichContentClick">
                        <div v-if="editLoading" class="text-muted-foreground py-8 text-center">Loading task...</div>
                        <div v-else-if="editError" class="text-destructive py-8 text-center">{{ editError }}</div>
                        <div v-else-if="editTask" class="grid h-full gap-6 lg:grid-cols-2">
                            <div class="space-y-6 overflow-auto pr-1">
                                <div v-if="editTask.created_at" class="text-muted-foreground text-xs">
                                    Created {{ formatThreadTime(editTask.created_at) }}
                                </div>

                                <div class="space-y-2">
                                    <Label>Task</Label>
                                    <template v-if="isOwner">
                                        <Input v-model="editForm.title" placeholder="Short, descriptive title" required />
                                    </template>
                                    <template v-else>
                                        <div
                                            class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-md border border-dashed p-3 text-sm"
                                        >
                                            {{ editTask.title }}
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-2">
                                    <Label>Priority</Label>
                                    <template v-if="isOwner">
                                        <Select v-model="editForm.priority">
                                            <option value="low">Low</option>
                                            <option value="medium">Medium</option>
                                            <option value="high">High</option>
                                        </Select>
                                    </template>
                                    <template v-else>
                                        <div
                                            class="border-muted-foreground/30 bg-muted/10 text-muted-foreground inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                        >
                                            {{ getPriorityLabel(editTask.priority) }}
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-2">
                                    <Label>Description</Label>
                                    <template v-if="isOwner">
                                        <ShiftEditor
                                            v-model="editForm.description"
                                            :temp-identifier="editTempIdentifier"
                                            placeholder="Update the task details and drag files inline."
                                            @uploading="editUploading = $event"
                                        />
                                    </template>
                                    <template v-else>
                                        <div class="border-muted-foreground/30 bg-muted/10 text-muted-foreground rounded-lg border p-4 text-sm">
                                            <div
                                                v-if="editTask.description"
                                                class="tiptap shift-rich [&_img]:max-w-full [&_img]:cursor-zoom-in [&_img]:rounded-lg [&_img]:shadow-sm [&_img.editor-tile]:aspect-square [&_img.editor-tile]:w-[200px] [&_img.editor-tile]:max-w-[200px] [&_img.editor-tile]:object-cover"
                                                v-html="editTask.description"
                                            ></div>
                                            <div v-else>No description provided.</div>
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-2">
                                    <Label>Attachments</Label>
                                    <div v-if="taskAttachments.length" class="space-y-2">
                                        <div
                                            v-for="attachment in taskAttachments"
                                            :key="attachment.id"
                                            class="border-muted-foreground/20 bg-muted/10 text-muted-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
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
                                class="border-muted-foreground/10 via-background to-background flex h-full flex-col overflow-hidden rounded-2xl border bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-900/5"
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
                                            <div class="relative">
                                                <Button
                                                    v-if="message.isYou && message.id && !message.pending && threadEditingId !== message.id"
                                                    :data-testid="`comment-edit-${message.id}`"
                                                    class="absolute top-0 right-0 z-10 h-7 w-7 translate-x-1/2 -translate-y-1/2 text-white/80 hover:text-white"
                                                    size="icon"
                                                    title="Edit comment"
                                                    type="button"
                                                    variant="ghost"
                                                    @click="startThreadEdit(message)"
                                                >
                                                    <Pencil class="h-3.5 w-3.5" />
                                                </Button>
                                                <div
                                                    :class="
                                                        message.isYou
                                                            ? 'rounded-br-md bg-sky-600 text-white'
                                                            : 'border-muted-foreground/10 bg-background/70 text-foreground rounded-bl-md border'
                                                    "
                                                    class="rounded-2xl px-3 py-2 text-sm shadow-sm"
                                                >
                                                    <div v-if="!message.isYou" class="text-foreground/80 mb-1 text-[11px] font-semibold">
                                                        {{ message.author }}
                                                    </div>
                                                    <template v-if="threadEditingId === message.id">
                                                        <ShiftEditor
                                                            v-model="threadEditHtml"
                                                            :clear-on-send="false"
                                                            :temp-identifier="threadEditTempIdentifier"
                                                            class="mt-2"
                                                            data-testid="comment-edit-editor"
                                                            placeholder="Edit comment..."
                                                            @send="saveThreadEdit($event?.html)"
                                                            @uploading="threadEditUploading = $event"
                                                        />
                                                        <div class="mt-2 flex items-center justify-end gap-2">
                                                            <Button
                                                                :data-testid="`comment-cancel-${message.id}`"
                                                                size="sm"
                                                                type="button"
                                                                variant="ghost"
                                                                @click="cancelThreadEdit"
                                                            >
                                                                Cancel
                                                            </Button>
                                                            <Button
                                                                :data-testid="`comment-save-${message.id}`"
                                                                :disabled="threadEditSaving || threadEditUploading"
                                                                size="sm"
                                                                type="button"
                                                                variant="default"
                                                                @click="saveThreadEdit()"
                                                            >
                                                                Save
                                                            </Button>
                                                        </div>
                                                        <div v-if="threadEditError" class="text-destructive mt-2 text-xs">{{ threadEditError }}</div>
                                                    </template>
                                                    <template v-else>
                                                        <div
                                                            class="shift-rich text-inherit [&_img]:my-2 [&_img]:max-w-full [&_img]:cursor-zoom-in [&_img]:rounded-lg [&_img]:shadow-sm [&_img.editor-tile]:aspect-square [&_img.editor-tile]:w-[200px] [&_img.editor-tile]:max-w-[200px] [&_img.editor-tile]:object-cover"
                                                            v-html="message.content"
                                                        ></div>
                                                        <div v-if="message.attachments?.length" class="mt-2 space-y-1">
                                                            <a
                                                                v-for="attachment in message.attachments"
                                                                :key="attachment.id"
                                                                :href="attachment.url"
                                                                class="block truncate text-xs underline decoration-white/40 underline-offset-2 hover:decoration-white/70"
                                                                rel="noreferrer"
                                                                target="_blank"
                                                            >
                                                                {{ attachment.original_filename }}
                                                            </a>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                            <div :class="message.isYou ? 'text-right' : 'text-left'" class="text-muted-foreground mt-1 text-[11px]">
                                                {{ message.time }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="border-muted-foreground/10 bg-background/80 border-t px-4 py-3 backdrop-blur">
                                    <Label class="text-muted-foreground mb-2 block text-xs">Reply</Label>
                                    <ShiftEditor
                                        :temp-identifier="threadTempIdentifier"
                                        data-testid="comments-editor"
                                        placeholder="Write a comment..."
                                        @send="handleThreadSend"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <SheetFooter class="flex flex-row items-center justify-between border-t px-6 py-4">
                        <Button type="button" variant="outline" @click="closeEdit">Close</Button>
                        <Button v-if="isOwner" :disabled="editLoading || editUploading" type="submit" variant="default"> Save </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>

        <ImageLightbox v-model:open="lightboxOpen" :alt="lightboxAlt" :src="lightboxSrc" />
    </AppLayout>
</template>
