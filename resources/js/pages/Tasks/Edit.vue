<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MarkdownEditor } from '@/components/ui/markdown-editor';
import AppLayout from '@/layouts/AppLayout.vue';
import TaskThreadTab from '@/components/TaskThreadTab.vue';
import { useTaskThreads } from '@/composables/useTaskThreads';
import { useTaskAttachments } from '@/composables/useTaskAttachments';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import { Paperclip } from 'lucide-vue-next';

interface Props {
    project: {
        id: number;
        name: string;
    };
    task: {
        id: number;
        title: string;
        description: string;
        project_id: number;
        status: string;
        priority: string;
        submitter_type?: string;
        submitter?: any;
    };
    attachments?: any[];
    projectExternalUsers?: any[];
    taskExternalUserIds?: number[];
}

const props = withDefaults(defineProps<Props>(), {
    attachments: () => [],
    projectExternalUsers: () => [],
    taskExternalUserIds: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/tasks',
    },
    {
        title: 'Edit Task',
        href: `/tasks/${props.task.id}/edit`,
    },
];

const title = 'Edit Task';

// Use composables for threads and attachments
const {
    activeTab,
    internalMessages,
    externalMessages,
    internalNewMessage,
    externalNewMessage,
    internalMessagesContainer,
    externalMessagesContainer,
    internalThreadAttachments,
    externalThreadAttachments,
    isThreadUploading,
    threadUploadError,
    isDraggingInternal,
    isDraggingExternal,
    renderMarkdown,
    handleDragOver,
    handleDragLeave,
    handleDrop,
    handleThreadFileUpload,
    removeThreadAttachment,
    sendMessage,
    isMessageDeletable,
    deleteMessage,
    loadTaskThreads,
    internalThreadTempIdentifier,
    externalThreadTempIdentifier,
} = useTaskThreads(props.task.id);

const {
    tempIdentifier,
    uploadedFiles,
    existingAttachments,
    deletedAttachmentIds,
    isUploading,
    uploadError,
    truncateFilename,
    handleFileUpload,
    removeFile,
    deleteAttachment,
    loadTempFiles,
} = useTaskAttachments(props.attachments);

const editForm = useForm({
    title: props.task.title,
    description: props.task.description,
    project_id: props.task.project_id,
    status: props.task.status,
    priority: props.task.priority,
    temp_identifier: tempIdentifier.value,
    deleted_attachment_ids: [],
    external_user_ids: props.taskExternalUserIds || [],
});

// Computed property for other errors (not related to specific fields)
const otherErrors = computed(() => {
    return Object.entries(editForm.errors)
        .filter(([key]) => !['title', 'description', 'project_id', 'status', 'priority', 'temp_identifier', 'deleted_attachment_ids'].includes(key))
        .reduce((acc, [key, value]) => {
            acc[key] = value;
            return acc;
        }, {});
});

// Check if task was submitted by an external user
const isTaskExternallySubmitted = computed(() => {
    return props.task.submitter_type === 'App\\Models\\ExternalUser';
});

// Load any previously uploaded files and task threads
onMounted(() => {
    loadTempFiles();
    loadTaskThreads();
});

// Before submitting the form, update the deleted_attachment_ids
const submitForm = (): void => {
    editForm.deleted_attachment_ids = deletedAttachmentIds.value;
    editForm.put(`/tasks/${props.task.id}`);
};
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid flex-1 grid-cols-1 gap-4 overflow-hidden p-4 lg:grid-cols-3">
            <form class="h-full space-y-4 overflow-auto" @submit.prevent="submitForm" @keydown.enter.prevent>
                <Card>
                    <CardHeader>
                        <CardTitle>Edit Task</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="mb-4">
                            <Label for="title">Task title</Label>
                            <Input id="title" v-model="editForm.title" required type="text" />
                            <div v-if="editForm.errors.title" class="mt-1 text-sm text-red-500">{{ editForm.errors.title }}</div>
                        </div>

                        <div class="mb-4">
                            <Label for="description">Description</Label>
                            <MarkdownEditor
                                id="description"
                                v-model="editForm.description"
                                :auto-grow="true"
                                class="mt-1"
                                height="300px"
                                max-height="600px"
                                placeholder="Write your task description here..."
                            />
                            <div v-if="editForm.errors.description" class="mt-1 text-sm text-red-500">
                                {{ editForm.errors.description }}
                            </div>
                        </div>

                        <div class="mb-4">
                            <p class="text-sm font-medium text-gray-700">Project: {{ project.name }}</p>
                        </div>

                        <!-- Status Dropdown -->
                        <div class="mb-4">
                            <Label for="status">Status</Label>
                            <select
                                id="status"
                                v-model="editForm.status"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            >
                                <option value="pending">Pending</option>
                                <option value="in-progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="awaiting-feedback">Awaiting Feedback</option>
                            </select>
                            <div v-if="editForm.errors.status" class="mt-1 text-sm text-red-500">
                                {{ editForm.errors.status }}
                            </div>
                        </div>

                        <!-- Priority Dropdown -->
                        <div class="mb-4">
                            <Label for="priority">Priority</Label>
                            <select
                                id="priority"
                                v-model="editForm.priority"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            >
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                            <div v-if="editForm.errors.priority" class="mt-1 text-sm text-red-500">
                                {{ editForm.errors.priority }}
                            </div>
                        </div>

                        <!-- External Users Section -->
                        <div v-if="props.projectExternalUsers.length > 0" class="mb-4">
                            <Label>Assign External Users</Label>
                            <p class="mb-2 text-xs text-gray-500">Select external users who should have access to this task</p>

                            <div class="mt-2 max-h-60 space-y-2 overflow-y-auto rounded-md border p-2">
                                <div v-for="externalUser in props.projectExternalUsers" :key="externalUser.id" class="flex items-center">
                                    <input
                                        :id="'external-user-' + externalUser.id"
                                        v-model="editForm.external_user_ids"
                                        :value="externalUser.id"
                                        class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        type="checkbox"
                                    />
                                    <label :for="'external-user-' + externalUser.id" class="ml-2 block text-sm text-gray-900">
                                        {{ externalUser.name || 'User ' + externalUser.external_id }}
                                        <span v-if="externalUser.email" class="ml-1 text-xs text-gray-500">({{ externalUser.email }})</span>
                                        <span class="ml-1 text-xs text-blue-600 font-medium">[{{ externalUser.environment }}]</span>
                                    </label>
                                </div>
                            </div>

                            <div v-if="editForm.errors.external_user_ids" class="mt-1 text-sm text-red-500">
                                {{ editForm.errors.external_user_ids }}
                            </div>
                        </div>

                        <div v-else class="mb-4">
                            <p class="text-sm text-gray-500">No external users available for this project.</p>
                        </div>

                        <!-- Existing Attachments Section -->
                        <div v-if="existingAttachments.length > 0" class="mb-4">
                            <h4 class="text-sm font-medium text-gray-700">Existing Attachments:</h4>
                            <ul class="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                                <li
                                    v-for="attachment in existingAttachments"
                                    :key="attachment.id"
                                    class="flex items-center justify-between px-3 py-2 text-sm"
                                >
                                    <div class="flex items-center">
                                        <Paperclip :size="20" class="mr-2 text-gray-400" />
                                        <a :href="attachment.url" class="hover:text-blue-600" target="_blank">{{
                                            truncateFilename(attachment.original_filename)
                                        }}</a>
                                    </div>
                                    <button class="text-red-600 hover:text-red-900" type="button" @click="deleteAttachment(attachment)">
                                        Remove
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- File Upload Section -->
                        <div class="mb-4">
                            <Label for="attachments">Add New Attachments</Label>
                            <div class="mt-1 flex items-center">
                                <input
                                    id="attachments"
                                    class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100"
                                    multiple
                                    type="file"
                                    @change="handleFileUpload"
                                />
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Upload files directly. They will be attached to the task when updated.</p>

                            <!-- Upload error message -->
                            <div v-if="uploadError" class="mt-1 text-sm text-red-500">{{ uploadError }}</div>

                            <!-- Loading indicator -->
                            <div v-if="isUploading" class="mt-1 text-sm text-blue-500">Uploading...</div>

                            <!-- List of uploaded files -->
                            <div v-if="uploadedFiles.length > 0" class="mt-3">
                                <h4 class="text-sm font-medium text-gray-700">New Files:</h4>
                                <ul class="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                                    <li v-for="file in uploadedFiles" :key="file.path" class="flex items-center justify-between px-3 py-2 text-sm">
                                        <div class="flex items-center">
                                            <Paperclip :size="20" class="mr-2 text-gray-400" />
                                            <span>{{ truncateFilename(file.original_filename) }}</span>
                                        </div>
                                        <button class="text-red-600 hover:text-red-900" type="button" @click="removeFile(file)">Remove</button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Display any other errors -->
                        <div v-for="(error, key) in otherErrors" :key="key" class="mb-4 text-sm text-red-500">
                            {{ error }}
                        </div>
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button :disabled="editForm.processing" type="submit">Update Task</Button>
                    </CardFooter>
                </Card>
            </form>
            <!-- Thread Tabs Section -->
            <Card class="col-span-1 h-full overflow-hidden lg:col-span-2">
                <CardHeader>
                    <CardTitle>Comments</CardTitle>
                </CardHeader>
                <CardContent class="grid flex-1 grid-cols-1 gap-4 overflow-hidden md:grid-cols-2">
                    <TaskThreadTab
                        :active-tab="activeTab"
                        :is-dragging="isDraggingInternal"
                        :is-message-deletable="isMessageDeletable"
                        :is-thread-uploading="isThreadUploading"
                        :messages="internalMessages"
                        :messages-container="internalMessagesContainer"
                        :new-message="internalNewMessage"
                        :render-markdown="renderMarkdown"
                        :thread-attachments="internalThreadAttachments"
                        :thread-upload-error="threadUploadError"
                        :truncate-filename="truncateFilename"
                        :thread-temp-identifier="internalThreadTempIdentifier"
                        tab-type="internal"
                        @update:active-tab="activeTab = $event"
                        @update:new-message="internalNewMessage = $event"
                        @delete-message="deleteMessage"
                        @handle-drag-over="handleDragOver"
                        @handle-drag-leave="handleDragLeave"
                        @handle-drop="handleDrop"
                        @handle-thread-file-upload="handleThreadFileUpload"
                        @remove-thread-attachment="removeThreadAttachment"
                        @send-message="(e) => sendMessage(e, { tempIdentifierOverride: internalThreadTempIdentifier })"
                    />

                    <TaskThreadTab
                        :active-tab="activeTab"
                        :is-dragging="isDraggingExternal"
                        :is-message-deletable="isMessageDeletable"
                        :is-thread-uploading="isThreadUploading"
                        :messages="externalMessages"
                        :messages-container="externalMessagesContainer"
                        :new-message="externalNewMessage"
                        :render-markdown="renderMarkdown"
                        :thread-attachments="externalThreadAttachments"
                        :thread-upload-error="threadUploadError"
                        :truncate-filename="truncateFilename"
                        :thread-temp-identifier="externalThreadTempIdentifier"
                        tab-type="external"
                        @update:active-tab="activeTab = $event"
                        @update:new-message="externalNewMessage = $event"
                        @delete-message="deleteMessage"
                        @handle-drag-over="handleDragOver"
                        @handle-drag-leave="handleDragLeave"
                        @handle-drop="handleDrop"
                        @handle-thread-file-upload="handleThreadFileUpload"
                        @remove-thread-attachment="removeThreadAttachment"
                        @send-message="(e) => sendMessage(e, { tempIdentifierOverride: externalThreadTempIdentifier })"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

<style>
.markdown-content {
    /* Basic styling for markdown content */
    line-height: 1.5;
}

.markdown-content h1,
.markdown-content h2,
.markdown-content h3,
.markdown-content h4,
.markdown-content h5,
.markdown-content h6 {
    margin-top: 1em;
    margin-bottom: 0.5em;
    font-weight: bold;
}

.markdown-content h1 {
    font-size: 1.5em;
}

.markdown-content h2 {
    font-size: 1.3em;
}

.markdown-content h3 {
    font-size: 1.2em;
}

.markdown-content h4 {
    font-size: 1.1em;
}

.markdown-content h5 {
    font-size: 1em;
}

.markdown-content h6 {
    font-size: 0.9em;
}

.markdown-content p {
    margin-bottom: 1em;
}

.markdown-content ul,
.markdown-content ol {
    margin-left: 1.5em;
    margin-bottom: 1em;
}

.markdown-content ul {
    list-style-type: disc;
}

.markdown-content ol {
    list-style-type: decimal;
}

.markdown-content a {
    color: #3182ce;
    text-decoration: underline;
}

.markdown-content blockquote {
    border-left: 4px solid #e2e8f0;
    padding-left: 1em;
    margin-left: 0;
    margin-bottom: 1em;
    color: #4a5568;
}

.markdown-content table {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 1em;
}

.markdown-content table th,
.markdown-content table td {
    border: 1px solid #e2e8f0;
    padding: 0.5em;
}

.markdown-content table th {
    background-color: #f7fafc;
}

/* Drag and drop styles */
.drag-over {
    border: 2px dashed #3182ce !important;
    background-color: rgba(49, 130, 206, 0.1) !important;
    transition: all 0.2s ease;
}
</style>
