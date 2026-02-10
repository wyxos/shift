<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { MarkdownEditor } from '@/components/ui/markdown-editor';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

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
    projects: {
        type: Array,
        required: true,
    },
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/tasks',
    },
    {
        title: 'Create',
        href: '/tasks/create',
    },
];

const title = `Create Task`;

// Generate a unique identifier for temporary files
const tempIdentifier = ref(Date.now().toString());
const uploadedFiles = ref([]);
const isUploading = ref(false);
const uploadError = ref('');

const createForm = useForm({
    title: '',
    description: '',
    project_id: null,
    temp_identifier: tempIdentifier.value,
    external_user_ids: [],
});

// State for external users
const selectedProjectExternalUsers = ref([]);

// Watch for project changes to update available external users
watch(
    () => createForm.project_id,
    (newProjectId) => {
        if (newProjectId) {
            const project = props.projects.find((p) => p.id === newProjectId);
            selectedProjectExternalUsers.value = project ? project.external_users : [];
            // Reset selected external users when project changes
            createForm.external_user_ids = [];
        } else {
            selectedProjectExternalUsers.value = [];
            createForm.external_user_ids = [];
        }
    },
);

// Computed property for other errors (not related to specific fields)
const otherErrors = computed(() => {
    return Object.entries(createForm.errors)
        .filter(([key]) => !['title', 'description', 'project_id', 'temp_identifier'].includes(key))
        .reduce((acc, [key, value]) => {
            acc[key] = value;
            return acc;
        }, {});
});

// Load any previously uploaded files
onMounted(() => {
    loadTempFiles();
});

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

// Remove a file
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
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4">
            <form @submit.prevent="createForm.post('/tasks')">
                <div class="mb-4">
                    <label for="title" class="block text-sm font-medium text-gray-700">Task Name</label>
                    <Input v-model="createForm.title" type="text" id="title" required />
                    <div v-if="createForm.errors.title" class="mt-1 text-sm text-red-500">{{ createForm.errors.title }}</div>
                </div>

                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <MarkdownEditor
                        v-model="createForm.description"
                        id="description"
                        height="300px"
                        :auto-grow="true"
                        max-height="600px"
                        placeholder="Write your task description here..."
                        class="mt-1"
                    />
                    <div v-if="createForm.errors.description" class="mt-1 text-sm text-red-500">{{ createForm.errors.description }}</div>
                </div>

                <div class="mb-4">
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select
                        v-model="createForm.project_id"
                        id="project_id"
                        class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring disabled:bg-gray-200"
                    >
                        <option :value="null">Select a project</option>
                        <!-- Populate with projects -->
                        <option v-for="project in props.projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                    </select>
                    <div v-if="createForm.errors.project_id" class="mt-1 text-sm text-red-500">{{ createForm.errors.project_id }}</div>
                </div>

                <!-- External Users Section -->
                <div v-if="createForm.project_id && selectedProjectExternalUsers.length > 0" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Assign External Users</label>
                    <p class="mb-2 text-xs text-gray-500">Select external users who should have access to this task</p>

                    <div class="mt-2 max-h-60 space-y-2 overflow-y-auto rounded-md border p-2">
                        <div v-for="externalUser in selectedProjectExternalUsers" :key="externalUser.id" class="flex items-center">
                            <input
                                type="checkbox"
                                :id="'external-user-' + externalUser.id"
                                :value="externalUser.id"
                                v-model="createForm.external_user_ids"
                                class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            />
                            <label :for="'external-user-' + externalUser.id" class="ml-2 block text-sm text-gray-900">
                                {{ externalUser.name || 'User ' + externalUser.external_id }}
                                <span v-if="externalUser.email" class="ml-1 text-xs text-gray-500">({{ externalUser.email }})</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="createForm.errors.external_user_ids" class="mt-1 text-sm text-red-500">{{ createForm.errors.external_user_ids }}</div>
                </div>

                <div v-else-if="createForm.project_id && selectedProjectExternalUsers.length === 0" class="mb-4">
                    <p class="text-sm text-gray-500">No external users available for this project.</p>
                </div>

                <!-- File Upload Section -->
                <div class="mb-4">
                    <label for="attachments" class="block text-sm font-medium text-gray-700">Attachments</label>
                    <div class="mt-1 flex items-center">
                        <input
                            type="file"
                            id="attachments"
                            @change="handleFileUpload"
                            multiple
                            class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100"
                        />
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Upload files directly. They will be attached to the task when created.</p>

                    <!-- Upload error message -->
                    <div v-if="uploadError" class="mt-1 text-sm text-red-500">{{ uploadError }}</div>

                    <!-- Loading indicator -->
                    <div v-if="isUploading" class="mt-1 text-sm text-blue-500">Uploading...</div>

                    <!-- List of uploaded files -->
                    <div v-if="uploadedFiles.length > 0" class="mt-3">
                        <h4 class="text-sm font-medium text-gray-700">Uploaded Files:</h4>
                        <ul class="mt-2 divide-y divide-gray-200 rounded-md border border-gray-200">
                            <li v-for="file in uploadedFiles" :key="file.path" class="flex items-center justify-between px-3 py-2 text-sm">
                                <div class="flex items-center">
                                    <svg class="mr-2 h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            fill-rule="evenodd"
                                            d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                    <span>{{ truncateFilename(file.original_filename) }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-md bg-red-50 text-red-600 shadow-sm ring-1 ring-red-200 transition hover:bg-red-100 hover:text-red-700 hover:ring-red-300"
                                    aria-label="Remove attachment"
                                    title="Remove"
                                    @click="removeFile(file)"
                                >
                                    <X :size="14" />
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="mb-4 text-sm text-red-500">
                    {{ error }}
                </div>

                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-white" :disabled="createForm.processing">Create Task</button>
            </form>
        </div>
    </AppLayout>
</template>
