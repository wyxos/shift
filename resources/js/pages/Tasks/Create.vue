<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Input } from '@/components/ui/input';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    projects: {
        type: Array,
        required: true
    }
});


const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/tasks',
    },
    {
        title: 'Create',
        href: '/tasks/create',
    }
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
});

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
        const response = await axios.post(route('task-attachments.upload'), formData, {
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
        const response = await axios.get(route('task-attachments.list-temp'), {
            params: { temp_identifier: tempIdentifier.value }
        });

        uploadedFiles.value = response.data.files;
    } catch (error) {
        console.error('Error loading temp files:', error);
    }
};

// Remove a file
const removeFile = async (file) => {
    try {
        await axios.delete(route('task-attachments.remove-temp'), {
            params: { path: file.path }
        });

        // Remove from the list
        uploadedFiles.value = uploadedFiles.value.filter(f => f.path !== file.path);
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
                    <div v-if="createForm.errors.title" class="text-red-500 text-sm mt-1">{{ createForm.errors.title }}</div>
                </div>

                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea v-model="createForm.description" id="description" rows="4" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-opacity-50"></textarea>
                    <div v-if="createForm.errors.description" class="text-red-500 text-sm mt-1">{{ createForm.errors.description }}</div>
                </div>

                <div class="mb-4">
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select v-model="createForm.project_id" id="project_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-opacity-50 disabled:bg-gray-200">
                        <option :value="null">Select a project</option>
                        <!-- Populate with projects -->
                        <option v-for="project in props.projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                    </select>
                    <div v-if="createForm.errors.project_id" class="text-red-500 text-sm mt-1">{{ createForm.errors.project_id }}</div>
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
                            class="block w-full text-sm text-gray-500
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-md file:border-0
                            file:text-sm file:font-semibold
                            file:bg-blue-50 file:text-blue-700
                            hover:file:bg-blue-100"
                        />
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Upload files directly. They will be attached to the task when created.</p>

                    <!-- Upload error message -->
                    <div v-if="uploadError" class="text-red-500 text-sm mt-1">{{ uploadError }}</div>

                    <!-- Loading indicator -->
                    <div v-if="isUploading" class="text-blue-500 text-sm mt-1">Uploading...</div>

                    <!-- List of uploaded files -->
                    <div v-if="uploadedFiles.length > 0" class="mt-3">
                        <h4 class="text-sm font-medium text-gray-700">Uploaded Files:</h4>
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

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="text-red-500 text-sm mb-4">
                    {{ error }}
                </div>

                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md" :disabled="createForm.processing">Create Task</button>
            </form>
        </div>
    </AppLayout>
</template>
