<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    externalUser: {
        type: Object,
        required: true,
    },
    projects: {
        type: Array,
        required: true,
    },
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'External Users',
        href: '/external-users',
    },
    {
        title: 'Edit External User',
        href: `/external-users/${props.externalUser.id}/edit`,
    },
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
                    <div v-if="form.errors.name" class="mt-1 text-sm text-red-500">{{ form.errors.name }}</div>
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <Input v-model="form.email" type="email" id="email" />
                    <div v-if="form.errors.email" class="mt-1 text-sm text-red-500">{{ form.errors.email }}</div>
                </div>

                <div class="mb-4">
                    <label for="project_id" class="block text-sm font-medium text-gray-700">Project</label>
                    <select
                        v-model="form.project_id"
                        id="project_id"
                        class="mt-1 block w-full rounded-md border-gray-300 py-2 pr-10 pl-3 text-base focus:border-blue-500 focus:ring-blue-500 focus:outline-none sm:text-sm"
                    >
                        <option :value="null">No Project</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                            {{ project.name }}
                        </option>
                    </select>
                    <div v-if="form.errors.project_id" class="mt-1 text-sm text-red-500">{{ form.errors.project_id }}</div>
                </div>

                <!-- Display any other errors -->
                <div v-for="(error, key) in otherErrors" :key="key" class="mb-4 text-sm text-red-500">
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
