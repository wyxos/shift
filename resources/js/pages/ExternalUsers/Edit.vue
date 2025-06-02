<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Input } from '@/components/ui/input';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps({
    externalUser: {
        type: Object,
        required: true
    },
    projects: {
        type: Array,
        required: true
    }
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'External Users',
        href: '/external-users',
    },
    {
        title: 'Edit External User',
        href: `/external-users/${props.externalUser.id}/edit`,
    }
];

const title = `Edit External User`;

const form = useForm({
    name: props.externalUser.name,
    email: props.externalUser.email,
    project_id: props.externalUser.project_id || null,
});

// Computed property for other errors (not related to specific fields)
const otherErrors = computed(() => {
    return Object.entries(form.errors)
        .filter(([key]) => !['name', 'email', 'project_id'].includes(key))
        .reduce((acc, [key, value]) => {
            acc[key] = value;
            return acc;
        }, {});
});

const submitForm = () => {
    form.put(`/external-users/${props.externalUser.id}`);
};
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4">
            <form @submit.prevent="submitForm">
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                    <Input v-model="form.name" type="text" id="name" required />
                    <div v-if="form.errors.name" class="text-red-500 text-sm mt-1">{{ form.errors.name }}</div>
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <Input v-model="form.email" type="email" id="email" />
                    <div v-if="form.errors.email" class="text-red-500 text-sm mt-1">{{ form.errors.email }}</div>
                </div>

                <div class="mb-4">
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select
                        v-model="form.project_id"
                        id="project_id"
                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                    >
                        <option :value="null">No Project</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                            {{ project.name }}
                        </option>
                    </select>
                    <div v-if="form.errors.project_id" class="text-red-500 text-sm mt-1">{{ form.errors.project_id }}</div>
                </div>

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="text-red-500 text-sm mb-4">
                    {{ error }}
                </div>

                <div class="flex space-x-4">
                    <Button type="submit" :disabled="form.processing">Update External User</Button>
                    <Button type="button" variant="outline" @click="$inertia.visit('/external-users')">Cancel</Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
