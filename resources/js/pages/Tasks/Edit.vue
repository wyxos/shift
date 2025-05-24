<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Input } from '@/components/ui/input';
import { computed } from 'vue';

const props = defineProps({
    project: {
        type: Object,
        required: true
    },
    task: {
        type: Object,
        required: true
    }
});


const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: '/tasks',
    },
    {
        title: 'Edit Task',
        href: '/tasks/create',
    }
];

const title = `Create Task`;

const editForm = useForm({
    title: props.task.title,
    description: props.task.description,
    project_id: props.task.project_id,
});

// Computed property for other errors (not related to specific fields)
const otherErrors = computed(() => {
    return Object.entries(editForm.errors)
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
            <form @submit.prevent="editForm.put('/tasks/' + props.task.id)">
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

                <p>
                    {{ project.name }}
                </p>

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="text-red-500 text-sm mb-4">
                    {{ error }}
                </div>

                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md" :disabled="editForm.processing">Update</button>
            </form>
        </div>
    </AppLayout>
</template>
