<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Input } from '@/components/ui/input';
import { computed } from 'vue';

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

const createForm = useForm({
    title: '',
    description: '',
    project_id: null,
});

// Computed property for other errors (not related to specific fields)
const otherErrors = computed(() => {
    return Object.entries(createForm.errors)
        .filter(([key]) => !['title', 'description', 'project_id'].includes(key))
        .reduce((acc, [key, value]) => {
            acc[key] = value;
            return acc;
        }, {});
});
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

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="text-red-500 text-sm mb-4">
                    {{ error }}
                </div>

                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md" :disabled="createForm.processing">Create Task</button>
            </form>
        </div>
    </AppLayout>
</template>
