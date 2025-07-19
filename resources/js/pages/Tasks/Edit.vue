<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MarkdownEditor } from '@/components/ui/markdown-editor';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { marked } from 'marked';
import { computed, onMounted, ref } from 'vue';

// Function to render markdown content
function renderMarkdown(content) {
    return marked(content);
}

// Function to truncate long filenames, showing part of the start and end
function truncateFilename(filename, maxLength = 30) {
    if (!filename || filename.length <= maxLength) {
        return filename;
    }

    const extension = filename.lastIndexOf('.') > 0 ? filename.substring(filename.lastIndexOf('.')) : '';
    const nameWithoutExtension = filename.substring(0, filename.length - extension.length);

    // Calculate how many characters to keep from start and end
    const startChars = Math.floor((maxLength - 3 - extension.length) / 2);
    const endChars = Math.ceil((maxLength - 3 - extension.length) / 2);

    return nameWithoutExtension.substring(0, startChars) + '...' + nameWithoutExtension.substring(nameWithoutExtension.length - endChars) + extension;
}

const props = defineProps({
    project: {
        type: Object,
        required: true,
    },
    task: {
        type: Object,
        required: true,
    },
    attachments: {
        type: Array,
        default: () => [],
    },
    projectExternalUsers: {
        type: Array,
        default: () => [],
    },
    taskExternalUserIds: {
        type: Array,
        default: () => [],
    },
});

// Thread state
const activeTab = ref('internal');
const internalMessages = ref([
    {
        id: 1,
        sender: 'John Doe',
        content: 'This is an example internal message',
        timestamp: '10:30 AM',
        isCurrentUser: true,
        attachments: [],
    },
    {
        id: 2,
        sender: 'Jane Smith',
        content: 'This is a response to the internal message',
        timestamp: '10:35 AM',
        isCurrentUser: false,
        attachments: [],
    },
]);
const externalMessages = ref([
    {
        id: 1,
        sender: 'Client Name',
        content: 'This is an example external message from the client',
        timestamp: '11:30 AM',
        isCurrentUser: false,
        attachments: [],
    },
    {
        id: 2,
        sender: 'You',
        content: 'This is a response to the client',
        timestamp: '11:45 AM',
        isCurrentUser: true,
        attachments: [],
    },
]);
const internalNewMessage = ref('');
const externalNewMessage = ref('');

// Refs for message containers to enable autoscrolling
const internalMessagesContainer = ref(null);
const externalMessagesContainer = ref(null);

// Function to scroll message container to the bottom
const scrollToBottom = (container) => {
    if (container) {
        setTimeout(() => {
            container.scrollTop = container.scrollHeight;
        }, 50); // Small delay to ensure content is rendered
    }
};

// Thread attachment state
const internalThreadTempIdentifier = ref(Date.now().toString() + '_internal_thread');
const externalThreadTempIdentifier = ref(Date.now().toString() + '_external_thread');
const internalThreadAttachments = ref([]);
const externalThreadAttachments = ref([]);
const isThreadUploading = ref(false);
const threadUploadError = ref('');

// Computed property to get the current thread temp identifier based on active tab
const currentThreadTempIdentifier = computed(() => {
    return activeTab.value === 'internal' ? internalThreadTempIdentifier.value : externalThreadTempIdentifier.value;
});

// We don't need a computed property for thread attachments since we'll use the specific arrays directly

// Handle thread file upload
const handleThreadFileUpload = (event) => {
    const files = event.target.files || event.dataTransfer.files;
    if (!files.length) return;

    for (let i = 0; i < files.length; i++) {
        uploadThreadFile(files[i]);
    }

    // Clear the file input if it's a file input element
    if (event.target.value !== undefined) {
        event.target.value = '';
    }
};

// Drag and drop state
const isDraggingInternal = ref(false);
const isDraggingExternal = ref(false);

// Drag event handlers
const handleDragOver = (event, type) => {
    event.preventDefault();
    if (type === 'internal') {
        isDraggingInternal.value = true;
    } else {
        isDraggingExternal.value = true;
    }
};

const handleDragLeave = (event, type) => {
    event.preventDefault();
    if (type === 'internal') {
        isDraggingInternal.value = false;
    } else {
        isDraggingExternal.value = false;
    }
};

const handleDrop = (event, type) => {
    event.preventDefault();

    // Set the active tab based on where the file was dropped
    activeTab.value = type;

    if (type === 'internal') {
        isDraggingInternal.value = false;
    } else {
        isDraggingExternal.value = false;
    }

    handleThreadFileUpload(event);
};

// Upload a thread file
const uploadThreadFile = async (file) => {
    isThreadUploading.value = true;
    threadUploadError.value = '';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('temp_identifier', currentThreadTempIdentifier.value);

    try {
        const response = await axios.post(route('attachments.upload'), formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (activeTab.value === 'internal') {
            internalThreadAttachments.value.push(response.data);
        } else {
            externalThreadAttachments.value.push(response.data);
        }
        isThreadUploading.value = false;
    } catch (error) {
        isThreadUploading.value = false;
        threadUploadError.value = error.response?.data?.message || 'Error uploading file';
        console.error('Thread upload error:', error);
    }
};

// Remove a thread attachment
const removeThreadAttachment = async (file) => {
    try {
        await axios.delete(route('attachments.remove-temp'), {
            params: { path: file.path },
        });

        // Remove from the appropriate list based on active tab
        if (activeTab.value === 'internal') {
            internalThreadAttachments.value = internalThreadAttachments.value.filter((f) => f.path !== file.path);
        } else {
            externalThreadAttachments.value = externalThreadAttachments.value.filter((f) => f.path !== file.path);
        }
    } catch (error) {
        console.error('Error removing thread attachment:', error);
    }
};

// Function to send a new message
const sendMessage = async (event) => {
    // Prevent form submission
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    // Get the appropriate message based on the active tab
    const messageContent = activeTab.value === 'internal' ? internalNewMessage.value : externalNewMessage.value;

    // Get the appropriate attachments based on the active tab
    const currentAttachments = activeTab.value === 'internal' ? internalThreadAttachments.value : externalThreadAttachments.value;

    if (!messageContent.trim() && currentAttachments.length === 0) return;

    try {
        const response = await axios.post(route('task-threads.store', { task: props.task.id }), {
            content: messageContent,
            type: activeTab.value,
            temp_identifier: currentAttachments.length > 0 ? currentThreadTempIdentifier.value : null,
        });

        const message = {
            id: response.data.thread.id,
            sender: response.data.thread.sender_name,
            content: response.data.thread.content,
            timestamp: new Date(response.data.thread.created_at).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
            }),
            isCurrentUser: response.data.thread.is_current_user,
            attachments: response.data.thread.attachments || [],
            created_at: response.data.thread.created_at,
        };

        if (activeTab.value === 'internal') {
            internalMessages.value.push(message);
            // Clear internal message form
            internalNewMessage.value = '';
            // Clear internal attachments
            internalThreadAttachments.value = [];
            internalThreadTempIdentifier.value = Date.now().toString() + '_internal_thread';
            // Scroll to bottom of internal messages
            scrollToBottom(internalMessagesContainer.value);
        } else {
            externalMessages.value.push(message);
            // Clear external message form
            externalNewMessage.value = '';
            // Clear external attachments
            externalThreadAttachments.value = [];
            externalThreadTempIdentifier.value = Date.now().toString() + '_external_thread';
            // Scroll to bottom of external messages
            scrollToBottom(externalMessagesContainer.value);
        }
    } catch (error) {
        console.error('Error sending message:', error);
        alert('Failed to send message. Please try again.');
    }
};

// Function to check if a message is older than 1 minute
const isMessageDeletable = (createdAt) => {
    if (!createdAt) return false;

    const messageDate = new Date(createdAt);
    const now = new Date();
    const diffInMinutes = (now - messageDate) / (1000 * 60);

    return diffInMinutes <= 1;
};

// Function to delete a message
const deleteMessage = async (messageId, messageType) => {
    if (!confirm('Are you sure you want to delete this message?')) {
        return;
    }

    try {
        await axios.delete(
            route('task-threads.destroy', {
                task: props.task.id,
                thread: messageId,
            }),
        );

        // Remove the message from the appropriate list
        if (messageType === 'internal') {
            internalMessages.value = internalMessages.value.filter((message) => message.id !== messageId);
        } else {
            externalMessages.value = externalMessages.value.filter((message) => message.id !== messageId);
        }
    } catch (error) {
        console.error('Error deleting message:', error);
        if (error.response?.data?.error === 'Messages can only be deleted within 1 minute of creation') {
            alert('Messages can only be deleted within 1 minute of creation.');
        } else {
            alert('Failed to delete message. Please try again.');
        }
    }
};

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

const title = `Edit Task`;

// Generate a unique identifier for temporary files
const tempIdentifier = ref(Date.now().toString());
const uploadedFiles = ref([]);
const existingAttachments = ref(props.attachments || []);
const deletedAttachmentIds = ref([]);
const isUploading = ref(false);
const uploadError = ref('');

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

// Load any previously uploaded files and task threads
onMounted(() => {
    loadTempFiles();
    loadTaskThreads();
});

// Load task threads from the server
const loadTaskThreads = async () => {
    try {
        const response = await axios.get(route('task-threads.index', { task: props.task.id }));

        if (response.data.internal && Array.isArray(response.data.internal)) {
            internalMessages.value = response.data.internal.map((thread) => ({
                id: thread.id,
                sender: thread.sender_name,
                content: thread.content,
                timestamp: new Date(thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                isCurrentUser: thread.is_current_user,
                attachments: thread.attachments || [],
                created_at: thread.created_at,
            }));
            // Scroll to bottom of internal messages
            scrollToBottom(internalMessagesContainer.value);
        }

        if (response.data.external && Array.isArray(response.data.external)) {
            externalMessages.value = response.data.external.map((thread) => ({
                id: thread.id,
                sender: thread.sender_name,
                content: thread.content,
                timestamp: new Date(thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                isCurrentUser: thread.is_current_user,
                attachments: thread.attachments || [],
                created_at: thread.created_at,
            }));
            // Scroll to bottom of external messages
            scrollToBottom(externalMessagesContainer.value);
        }
    } catch (error) {
        console.error('Error loading task threads:', error);
    }
};

// Handle file upload
const handleFileUpload = (event) => {
    const files = event.target.files || event.dataTransfer.files;
    if (!files.length) return;

    for (let i = 0; i < files.length; i++) {
        uploadFile(files[i]);
    }

    // Clear the file input
    event.target.value = '';
};

// Upload a single file
const uploadFile = async (file) => {
    isUploading.value = true;
    uploadError.value = '';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('temp_identifier', tempIdentifier.value);

    try {
        const response = await axios.post(route('attachments.upload'), formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        uploadedFiles.value.push(response.data);
        isUploading.value = false;
    } catch (error) {
        isUploading.value = false;
        uploadError.value = error.response?.data?.message || 'Error uploading file';
        console.error('Upload error:', error);
    }
};

// Load temporary files
const loadTempFiles = async () => {
    try {
        const response = await axios.get(route('attachments.list-temp'), {
            params: { temp_identifier: tempIdentifier.value },
        });

        uploadedFiles.value = response.data.files;
    } catch (error) {
        console.error('Error loading temp files:', error);
    }
};

// Remove a temporary file
const removeFile = async (file) => {
    try {
        await axios.delete(route('attachments.remove-temp'), {
            params: { path: file.path },
        });

        // Remove from the list
        uploadedFiles.value = uploadedFiles.value.filter((f) => f.path !== file.path);
    } catch (error) {
        console.error('Error removing file:', error);
    }
};

// Delete an existing attachment
const deleteAttachment = (attachment) => {
    // Add to deleted attachments list
    deletedAttachmentIds.value.push(attachment.id);
    // Remove from the displayed list
    existingAttachments.value = existingAttachments.value.filter((a) => a.id !== attachment.id);
};

// Before submitting the form, update the deleted_attachment_ids
const submitForm = () => {
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
                                class="mt-1"
                                height="300px"
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
                                        <svg class="mr-2 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                clip-rule="evenodd"
                                                d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                                fill-rule="evenodd"
                                            />
                                        </svg>
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
                                            <svg class="mr-2 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path
                                                    clip-rule="evenodd"
                                                    d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                                    fill-rule="evenodd"
                                                />
                                            </svg>
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
                    <div
                        :class="['rounded-md border p-4', activeTab === 'internal' ? 'border-blue-500 bg-blue-50' : '']"
                        class="flex h-full flex-col overflow-hidden"
                        @click="activeTab = 'internal'"
                    >
                        <h4>Internal</h4>
                        <!-- Messages container with fixed height and scrolling -->
                        <div ref="internalMessagesContainer" class="mb-4 flex-1 overflow-y-auto rounded bg-gray-50 p-2">
                            <div
                                v-for="message in internalMessages"
                                :key="message.id"
                                :class="message.isCurrentUser ? 'text-right' : 'text-left'"
                                class="mb-3"
                            >
                                <div class="flex items-center justify-between">
                                    <p :class="message.isCurrentUser ? 'ml-auto' : ''" class="text-sm">
                                        <span class="font-semibold">{{ message.sender }} - </span>
                                        <span class="mt-1 opacity-75">{{ message.timestamp }}</span>
                                    </p>
                                    <button
                                        v-if="message.isCurrentUser && isMessageDeletable(message.created_at)"
                                        class="ml-2 text-xs text-red-500 hover:text-red-700"
                                        title="Delete message"
                                        @click="deleteMessage(message.id, 'internal')"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                            ></path>
                                        </svg>
                                    </button>
                                </div>
                                <div
                                    :class="
                                        message.isCurrentUser ? 'rounded-br-none bg-blue-500 text-white' : 'rounded-bl-none bg-gray-200 text-gray-800'
                                    "
                                    class="inline-block max-w-3/4 min-w-[200px] rounded-lg p-3"
                                >
                                    <div class="markdown-content" v-html="renderMarkdown(message.content)"></div>
                                    <!-- Display message attachments if any -->
                                    <div v-if="message.attachments && message.attachments.length > 0" class="mt-2">
                                        <p class="text-xs font-semibold">Attachments:</p>
                                        <div v-for="attachment in message.attachments" :key="attachment.id" class="mt-1">
                                            <a :href="attachment.url" class="flex items-center text-xs underline" target="_blank">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path
                                                        clip-rule="evenodd"
                                                        d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                                        fill-rule="evenodd"
                                                    />
                                                </svg>
                                                {{ truncateFilename(attachment.original_filename) }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Thread attachments display -->
                        <div v-if="internalThreadAttachments.length > 0" class="mb-3">
                            <h4 class="text-sm font-medium text-gray-700">Attachments:</h4>
                            <ul class="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                                <li
                                    v-for="file in internalThreadAttachments"
                                    :key="file.path"
                                    class="flex items-center justify-between px-3 py-2 text-sm"
                                >
                                    <div class="flex items-center">
                                        <svg class="mr-2 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                clip-rule="evenodd"
                                                d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                                fill-rule="evenodd"
                                            />
                                        </svg>
                                        <span>{{ truncateFilename(file.original_filename) }}</span>
                                    </div>
                                    <button class="text-red-600 hover:text-red-900" type="button" @click="removeThreadAttachment(file)">
                                        Remove
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Thread upload error message -->
                        <div v-if="threadUploadError" class="mb-2 text-sm text-red-500">{{ threadUploadError }}</div>

                        <!-- Thread loading indicator -->
                        <div v-if="isThreadUploading" class="mb-2 text-sm text-blue-500">Uploading attachment...</div>

                        <!-- Message input with attachment button -->
                        <div class="flex flex-col">
                            <div class="mb-2">
                                <MarkdownEditor
                                    v-model="internalNewMessage"
                                    :class="['flex-grow', isDraggingInternal ? 'drag-over' : '']"
                                    height="200px"
                                    placeholder="Type your message or drop files here..."
                                    @dragleave="(event) => handleDragLeave(event, 'internal')"
                                    @dragover="(event) => handleDragOver(event, 'internal')"
                                    @drop="(event) => handleDrop(event, 'internal')"
                                    @enter="sendMessage"
                                />
                                <div class="mt-2 flex justify-end gap-2">
                                    <label
                                        class="flex cursor-pointer items-center bg-gray-200 px-3 py-2 text-gray-700 hover:bg-gray-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    >
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                            />
                                        </svg>
                                        <input class="hidden" multiple type="file" @change="handleThreadFileUpload" />
                                    </label>
                                    <button
                                        class="rounded-r-md bg-blue-500 px-4 py-2 text-white hover:bg-blue-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                        type="button"
                                        @click.prevent="sendMessage($event)"
                                    >
                                        Send
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        :class="['rounded-md border p-4', activeTab === 'external' ? 'border-blue-500 bg-blue-50' : '']"
                        class="flex h-full flex-col overflow-hidden"
                        @click="activeTab = 'external'"
                    >
                        <h4>External</h4>
                        <!-- Messages container with fixed height and scrolling -->
                        <div ref="externalMessagesContainer" class="mb-4 flex-1 overflow-y-auto rounded bg-gray-50 p-2">
                            <div
                                v-for="message in externalMessages"
                                :key="message.id"
                                :class="message.isCurrentUser ? 'text-right' : 'text-left'"
                                class="mb-3"
                            >
                                <div class="flex items-center justify-between">
                                    <p :class="message.isCurrentUser ? 'ml-auto' : ''" class="text-sm">
                                        <span class="font-semibold">{{ message.sender }} - </span>
                                        <span class="mt-1 opacity-75">{{ message.timestamp }}</span>
                                    </p>
                                    <button
                                        v-if="message.isCurrentUser && isMessageDeletable(message.created_at)"
                                        class="ml-2 text-xs text-red-500 hover:text-red-700"
                                        title="Delete message"
                                        @click="deleteMessage(message.id, 'external')"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                            ></path>
                                        </svg>
                                    </button>
                                </div>
                                <div
                                    :class="
                                        message.isCurrentUser ? 'rounded-br-none bg-blue-500 text-white' : 'rounded-bl-none bg-gray-200 text-gray-800'
                                    "
                                    class="inline-block max-w-3/4 min-w-[200px] rounded-lg p-3"
                                >
                                    <div class="markdown-content" v-html="renderMarkdown(message.content)"></div>
                                    <!-- Display message attachments if any -->
                                    <div v-if="message.attachments && message.attachments.length > 0" class="mt-2">
                                        <p class="text-xs font-semibold">Attachments:</p>
                                        <div v-for="attachment in message.attachments" :key="attachment.id" class="mt-1">
                                            <a :href="attachment.url" class="flex items-center text-xs underline" target="_blank">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path
                                                        clip-rule="evenodd"
                                                        d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                                        fill-rule="evenodd"
                                                    />
                                                </svg>
                                                {{ truncateFilename(attachment.original_filename) }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Thread attachments display -->
                        <div v-if="externalThreadAttachments.length > 0" class="mb-3">
                            <h4 class="text-sm font-medium text-gray-700">Attachments:</h4>
                            <ul class="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                                <li
                                    v-for="file in externalThreadAttachments"
                                    :key="file.path"
                                    class="flex items-center justify-between px-3 py-2 text-sm"
                                >
                                    <div class="flex items-center">
                                        <svg class="mr-2 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                clip-rule="evenodd"
                                                d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                                fill-rule="evenodd"
                                            />
                                        </svg>
                                        <span>{{ truncateFilename(file.original_filename) }}</span>
                                    </div>
                                    <button class="text-red-600 hover:text-red-900" type="button" @click="removeThreadAttachment(file)">
                                        Remove
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Thread upload error message -->
                        <div v-if="threadUploadError" class="mb-2 text-sm text-red-500">{{ threadUploadError }}</div>

                        <!-- Thread loading indicator -->
                        <div v-if="isThreadUploading" class="mb-2 text-sm text-blue-500">Uploading attachment...</div>

                        <!-- Message input with attachment button -->
                        <div class="flex flex-col">
                            <div class="mb-2">
                                <MarkdownEditor
                                    v-model="externalNewMessage"
                                    :class="['flex-grow', isDraggingExternal ? 'drag-over' : '']"
                                    height="200px"
                                    placeholder="Type your message or drop files here..."
                                    @dragleave="(event) => handleDragLeave(event, 'external')"
                                    @dragover="(event) => handleDragOver(event, 'external')"
                                    @drop="(event) => handleDrop(event, 'external')"
                                    @enter="sendMessage"
                                />
                                <div class="mt-2 flex justify-end gap-2">
                                    <label
                                        class="flex cursor-pointer items-center bg-gray-200 px-3 py-2 text-gray-700 hover:bg-gray-300 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    >
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                            />
                                        </svg>
                                        <input class="hidden" multiple type="file" @change="handleThreadFileUpload" />
                                    </label>
                                    <button
                                        class="rounded-r-md bg-blue-500 px-4 py-2 text-white hover:bg-blue-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                        type="button"
                                        @click.prevent="sendMessage($event)"
                                    >
                                        Send
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
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

.markdown-content code {
    background-color: rgba(0, 0, 0, 0.1);
    padding: 0.2em 0.4em;
    border-radius: 3px;
    font-family: monospace;
}

.markdown-content pre {
    background-color: rgba(0, 0, 0, 0.1);
    padding: 1em;
    border-radius: 5px;
    overflow-x: auto;
    margin-bottom: 1em;
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
