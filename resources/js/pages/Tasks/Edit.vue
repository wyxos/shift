<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Input } from '@/components/ui/input';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

const props = defineProps({
    project: {
        type: Object,
        required: true
    },
    task: {
        type: Object,
        required: true
    },
    attachments: {
        type: Array,
        default: () => []
    },
    projectExternalUsers: {
        type: Array,
        default: () => []
    },
    taskExternalUserIds: {
        type: Array,
        default: () => []
    }
});

// Thread state
const activeTab = ref('internal');
const internalMessages = ref([
    { id: 1, sender: 'John Doe', content: 'This is an example internal message', timestamp: '10:30 AM', isCurrentUser: true, attachments: [] },
    { id: 2, sender: 'Jane Smith', content: 'This is a response to the internal message', timestamp: '10:35 AM', isCurrentUser: false, attachments: [] },
]);
const externalMessages = ref([
    { id: 1, sender: 'Client Name', content: 'This is an example external message from the client', timestamp: '11:30 AM', isCurrentUser: false, attachments: [] },
    { id: 2, sender: 'You', content: 'This is a response to the client', timestamp: '11:45 AM', isCurrentUser: true, attachments: [] },
]);
const newMessage = ref('');

// Thread attachment state
const threadTempIdentifier = ref(Date.now().toString() + '_thread');
const threadAttachments = ref([]);
const isThreadUploading = ref(false);
const threadUploadError = ref('');

// Handle thread file upload
const handleThreadFileUpload = (event) => {
    const files = event.target.files || event.dataTransfer.files;
    if (!files.length) return;

    for (let i = 0; i < files.length; i++) {
        uploadThreadFile(files[i]);
    }

    // Clear the file input
    event.target.value = '';
};

// Upload a thread file
const uploadThreadFile = async (file) => {
    isThreadUploading.value = true;
    threadUploadError.value = '';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('temp_identifier', threadTempIdentifier.value);

    try {
        const response = await axios.post(route('attachments.upload'), formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });

        threadAttachments.value.push(response.data);
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
            params: { path: file.path }
        });

        // Remove from the list
        threadAttachments.value = threadAttachments.value.filter(f => f.path !== file.path);
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

    if (!newMessage.value.trim() && threadAttachments.value.length === 0) return;

    try {
        const response = await axios.post(route('task-threads.store', { task: props.task.id }), {
            content: newMessage.value,
            type: activeTab.value,
            temp_identifier: threadAttachments.value.length > 0 ? threadTempIdentifier.value : null
        });

        const message = {
            id: response.data.thread.id,
            sender: response.data.thread.sender_name,
            content: response.data.thread.content,
            timestamp: new Date(response.data.thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
            isCurrentUser: response.data.thread.is_current_user,
            attachments: response.data.thread.attachments || []
        };

        if (activeTab.value === 'internal') {
            internalMessages.value.push(message);
        } else {
            externalMessages.value.push(message);
        }

        // Clear form
        newMessage.value = '';
        threadAttachments.value = [];
        threadTempIdentifier.value = Date.now().toString() + '_thread';
    } catch (error) {
        console.error('Error sending message:', error);
        alert('Failed to send message. Please try again.');
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
    }
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
    temp_identifier: tempIdentifier.value,
    deleted_attachment_ids: [],
    external_user_ids: props.taskExternalUserIds || [],
});

// Computed property for other errors (not related to specific fields)
const otherErrors = computed(() => {
    return Object.entries(editForm.errors)
        .filter(([key]) => !['title', 'description', 'project_id', 'temp_identifier', 'deleted_attachment_ids'].includes(key))
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
            internalMessages.value = response.data.internal.map(thread => ({
                id: thread.id,
                sender: thread.sender_name,
                content: thread.content,
                timestamp: new Date(thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                isCurrentUser: thread.is_current_user,
                attachments: thread.attachments || []
            }));
        }

        if (response.data.external && Array.isArray(response.data.external)) {
            externalMessages.value = response.data.external.map(thread => ({
                id: thread.id,
                sender: thread.sender_name,
                content: thread.content,
                timestamp: new Date(thread.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                isCurrentUser: thread.is_current_user,
                attachments: thread.attachments || []
            }));
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
                'Content-Type': 'multipart/form-data'
            }
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
            params: { temp_identifier: tempIdentifier.value }
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
            params: { path: file.path }
        });

        // Remove from the list
        uploadedFiles.value = uploadedFiles.value.filter(f => f.path !== file.path);
    } catch (error) {
        console.error('Error removing file:', error);
    }
};

// Delete an existing attachment
const deleteAttachment = (attachment) => {
    // Add to deleted attachments list
    deletedAttachmentIds.value.push(attachment.id);
    // Remove from the displayed list
    existingAttachments.value = existingAttachments.value.filter(a => a.id !== attachment.id);
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

        <div class="p-4">
            <form @submit.prevent="submitForm" @keydown.enter.prevent>
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700">Task title</label>
                    <Input v-model="editForm.title" type="text" id="title" required />
                    <div v-if="editForm.errors.title" class="text-red-500 text-sm mt-1">{{ editForm.errors.title }}</div>
                </div>

                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea v-model="editForm.description" id="description" rows="4" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-opacity-50"></textarea>
                    <div v-if="editForm.errors.description" class="text-red-500 text-sm mt-1">{{ editForm.errors.description }}</div>
                </div>

                <div class="mb-4">
                    <p class="text-sm font-medium text-gray-700">Project: {{ project.name }}</p>
                </div>

                <!-- External Users Section -->
                <div v-if="props.projectExternalUsers.length > 0" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Assign External Users</label>
                    <p class="text-xs text-gray-500 mb-2">Select external users who should have access to this task</p>

                    <div class="mt-2 space-y-2 max-h-60 overflow-y-auto border rounded-md p-2">
                        <div v-for="externalUser in props.projectExternalUsers" :key="externalUser.id" class="flex items-center">
                            <input
                                type="checkbox"
                                :id="'external-user-' + externalUser.id"
                                :value="externalUser.id"
                                v-model="editForm.external_user_ids"
                                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                            >
                            <label :for="'external-user-' + externalUser.id" class="ml-2 block text-sm text-gray-900">
                                {{ externalUser.name || 'User ' + externalUser.external_id }}
                                <span v-if="externalUser.email" class="text-xs text-gray-500 ml-1">({{ externalUser.email }})</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="editForm.errors.external_user_ids" class="text-red-500 text-sm mt-1">{{ editForm.errors.external_user_ids }}</div>
                </div>

                <div v-else class="mb-4">
                    <p class="text-sm text-gray-500">No external users available for this project.</p>
                </div>

                <!-- Existing Attachments Section -->
                <div v-if="existingAttachments.length > 0" class="mb-4">
                    <h4 class="text-sm font-medium text-gray-700">Existing Attachments:</h4>
                    <ul class="mt-2 divide-y divide-gray-200 border border-gray-200 rounded-md">
                        <li v-for="attachment in existingAttachments" :key="attachment.id" class="flex items-center justify-between py-2 px-3 text-sm">
                            <div class="flex items-center">
                                <svg class="h-5 w-5 text-gray-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd" />
                                </svg>
                                <a :href="attachment.url" target="_blank" class="truncate hover:text-blue-600">{{ attachment.original_filename }}</a>
                            </div>
                            <button
                                type="button"
                                @click="deleteAttachment(attachment)"
                                class="text-red-600 hover:text-red-900"
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- File Upload Section -->
                <div class="mb-4">
                    <label for="attachments" class="block text-sm font-medium text-gray-700">Add New Attachments</label>
                    <div class="mt-1 flex items-center">
                        <input
                            type="file"
                            id="attachments"
                            @change="handleFileUpload"
                            multiple
                            class="block w-full text-sm text-gray-500
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-md file:border-0
                            file:text-sm file:font-semibold
                            file:bg-blue-50 file:text-blue-700
                            hover:file:bg-blue-100"
                        />
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Upload files directly. They will be attached to the task when updated.</p>

                    <!-- Upload error message -->
                    <div v-if="uploadError" class="text-red-500 text-sm mt-1">{{ uploadError }}</div>

                    <!-- Loading indicator -->
                    <div v-if="isUploading" class="text-blue-500 text-sm mt-1">Uploading...</div>

                    <!-- List of uploaded files -->
                    <div v-if="uploadedFiles.length > 0" class="mt-3">
                        <h4 class="text-sm font-medium text-gray-700">New Files:</h4>
                        <ul class="mt-2 divide-y divide-gray-200 border border-gray-200 rounded-md">
                            <li v-for="file in uploadedFiles" :key="file.path" class="flex items-center justify-between py-2 px-3 text-sm">
                                <div class="flex items-center">
                                    <svg class="h-5 w-5 text-gray-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd" />
                                    </svg>
                                    <span class="truncate">{{ file.original_filename }}</span>
                                </div>
                                <button
                                    type="button"
                                    @click="removeFile(file)"
                                    class="text-red-600 hover:text-red-900"
                                >
                                    Remove
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Thread Tabs Section -->
                <div class="mb-6 mt-8">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Task Threads</h3>

                    <Tabs :default-value="activeTab" @update:value="activeTab = $event" class="w-full">
                        <TabsList class="grid w-full grid-cols-2 mb-4">
                            <TabsTrigger value="internal">Internal Thread</TabsTrigger>
                            <TabsTrigger value="external">External Thread</TabsTrigger>
                        </TabsList>

                        <TabsContent value="internal" class="border rounded-md p-4">
                            <!-- Messages container with fixed height and scrolling -->
                            <div class="h-64 overflow-y-auto mb-4 p-2 bg-gray-50 rounded">
                                <div v-for="message in internalMessages" :key="message.id"
                                    class="mb-3"
                                    :class="message.isCurrentUser ? 'text-right' : 'text-left'">
                                    <div class="inline-block max-w-3/4 p-3 rounded-lg"
                                        :class="message.isCurrentUser ? 'bg-blue-500 text-white rounded-br-none' : 'bg-gray-200 text-gray-800 rounded-bl-none'">
                                        <p class="text-sm font-semibold">{{ message.sender }}</p>
                                        <p>{{ message.content }}</p>
                                        <!-- Display message attachments if any -->
                                        <div v-if="message.attachments && message.attachments.length > 0" class="mt-2">
                                            <p class="text-xs font-semibold">Attachments:</p>
                                            <div v-for="attachment in message.attachments" :key="attachment.id" class="mt-1">
                                                <a :href="attachment.url" target="_blank" class="text-xs underline flex items-center">
                                                    <svg class="h-3 w-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd" />
                                                    </svg>
                                                    {{ attachment.original_filename }}
                                                </a>
                                            </div>
                                        </div>
                                        <p class="text-xs mt-1 opacity-75">{{ message.timestamp }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Thread attachments display -->
                            <div v-if="threadAttachments.length > 0" class="mb-3">
                                <h4 class="text-sm font-medium text-gray-700">Attachments:</h4>
                                <ul class="mt-2 divide-y divide-gray-200 border border-gray-200 rounded-md">
                                    <li v-for="file in threadAttachments" :key="file.path" class="flex items-center justify-between py-2 px-3 text-sm">
                                        <div class="flex items-center">
                                            <svg class="h-5 w-5 text-gray-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd" />
                                            </svg>
                                            <span class="truncate">{{ file.original_filename }}</span>
                                        </div>
                                        <button
                                            type="button"
                                            @click="removeThreadAttachment(file)"
                                            class="text-red-600 hover:text-red-900"
                                        >
                                            Remove
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <!-- Thread upload error message -->
                            <div v-if="threadUploadError" class="text-red-500 text-sm mb-2">{{ threadUploadError }}</div>

                            <!-- Thread loading indicator -->
                            <div v-if="isThreadUploading" class="text-blue-500 text-sm mb-2">Uploading attachment...</div>

                            <!-- Message input with attachment button -->
                            <div class="flex flex-col">
                                <div class="flex mb-2">
                                    <input
                                        v-model="newMessage"
                                        type="text"
                                        placeholder="Type your message..."
                                        class="flex-grow border rounded-l-md p-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        @keyup.enter.prevent="sendMessage($event)"
                                    />
                                    <label class="cursor-pointer bg-gray-200 text-gray-700 px-3 py-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                        </svg>
                                        <input
                                            type="file"
                                            class="hidden"
                                            multiple
                                            @change="handleThreadFileUpload"
                                        />
                                    </label>
                                    <button
                                        type="button"
                                        @click.prevent="sendMessage($event)"
                                        class="bg-blue-500 text-white px-4 py-2 rounded-r-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        Send
                                    </button>
                                </div>
                            </div>
                        </TabsContent>

                        <TabsContent value="external" class="border rounded-md p-4">
                            <!-- Messages container with fixed height and scrolling -->
                            <div class="h-64 overflow-y-auto mb-4 p-2 bg-gray-50 rounded">
                                <div v-for="message in externalMessages" :key="message.id"
                                    class="mb-3"
                                    :class="message.isCurrentUser ? 'text-right' : 'text-left'">
                                    <div class="inline-block max-w-3/4 p-3 rounded-lg"
                                        :class="message.isCurrentUser ? 'bg-blue-500 text-white rounded-br-none' : 'bg-gray-200 text-gray-800 rounded-bl-none'">
                                        <p class="text-sm font-semibold">{{ message.sender }}</p>
                                        <p>{{ message.content }}</p>
                                        <!-- Display message attachments if any -->
                                        <div v-if="message.attachments && message.attachments.length > 0" class="mt-2">
                                            <p class="text-xs font-semibold">Attachments:</p>
                                            <div v-for="attachment in message.attachments" :key="attachment.id" class="mt-1">
                                                <a :href="attachment.url" target="_blank" class="text-xs underline flex items-center">
                                                    <svg class="h-3 w-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd" />
                                                    </svg>
                                                    {{ attachment.original_filename }}
                                                </a>
                                            </div>
                                        </div>
                                        <p class="text-xs mt-1 opacity-75">{{ message.timestamp }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Thread attachments display -->
                            <div v-if="threadAttachments.length > 0" class="mb-3">
                                <h4 class="text-sm font-medium text-gray-700">Attachments:</h4>
                                <ul class="mt-2 divide-y divide-gray-200 border border-gray-200 rounded-md">
                                    <li v-for="file in threadAttachments" :key="file.path" class="flex items-center justify-between py-2 px-3 text-sm">
                                        <div class="flex items-center">
                                            <svg class="h-5 w-5 text-gray-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z" clip-rule="evenodd" />
                                            </svg>
                                            <span class="truncate">{{ file.original_filename }}</span>
                                        </div>
                                        <button
                                            type="button"
                                            @click="removeThreadAttachment(file)"
                                            class="text-red-600 hover:text-red-900"
                                        >
                                            Remove
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <!-- Thread upload error message -->
                            <div v-if="threadUploadError" class="text-red-500 text-sm mb-2">{{ threadUploadError }}</div>

                            <!-- Thread loading indicator -->
                            <div v-if="isThreadUploading" class="text-blue-500 text-sm mb-2">Uploading attachment...</div>

                            <!-- Message input with attachment button -->
                            <div class="flex flex-col">
                                <div class="flex mb-2">
                                    <input
                                        v-model="newMessage"
                                        type="text"
                                        placeholder="Type your message..."
                                        class="flex-grow border rounded-l-md p-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        @keyup.enter.prevent="sendMessage($event)"
                                    />
                                    <label class="cursor-pointer bg-gray-200 text-gray-700 px-3 py-2 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                        </svg>
                                        <input
                                            type="file"
                                            class="hidden"
                                            multiple
                                            @change="handleThreadFileUpload"
                                        />
                                    </label>
                                    <button
                                        type="button"
                                        @click.prevent="sendMessage($event)"
                                        class="bg-blue-500 text-white px-4 py-2 rounded-r-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        Send
                                    </button>
                                </div>
                            </div>
                        </TabsContent>
                    </Tabs>
                </div>

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="text-red-500 text-sm mb-4">
                    {{ error }}
                </div>

                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md" :disabled="editForm.processing">Update Task</button>
            </form>
        </div>
    </AppLayout>
</template>
