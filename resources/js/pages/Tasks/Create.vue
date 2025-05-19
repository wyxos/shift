<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Input } from '@/components/ui/input';

defineProps({
    projects: {
        type: Array,
        required: true
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
    }
];

const title = `Create Task`;

const createForm = useForm({
    title: '',
    description: '',
    project_id: null,
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
                </div>

                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea v-model="createForm.description" id="description" rows="4" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-opacity-50"></textarea>
                </div>

                <div class="mb-4">
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select v-model="createForm.project_id" id="project_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-opacity-50">
                        <option value="">Select a project</option>
                        <!-- Populate with projects -->
                        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                    </select>
                </div>

                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md">Create Task</button>
            </form>
        </div>
    </AppLayout>
</template>
