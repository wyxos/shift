<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {OTable, OTableColumn} from '@oruga-ui/oruga-next';
import { Button } from '@/components/ui/button';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogDescription,
    AlertDialogContent,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { Input } from '@/components/ui/input';
import DeleteDialog from '@/components/DeleteDialog.vue';

const props = defineProps({
    projects: {
        type: Object,
        required: true
    },
    clients: {
        type: Object,
        required: true
    },
    filters: {
        type: Object,
        required: true
    }
})

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Projects',
        href: '/projects',
    },
];

const editDialogOpen = ref(false);

const search = ref(props.filters.search);

const title = `Projects` + (search.value ? ` - ${search.value}` : '');

function openEditModal(project: { id: number, name: string }) {
    editForm.id = project.id;
    editForm.name = project.name;
    editDialogOpen.value = true;
}

function openDeleteModal(project: { id: number, name: string }) {
    deleteForm.id = project.id;
    deleteForm.isActive = true;
}


const editForm = useForm<{
    id: number | null;
    name: string;
}>({
    id: null,
    name: '',
});

const createForm = useForm<{
    name: string;
    client_id: number | null;
    isActive: boolean;
}>({
    name: '',
    client_id: null,
    isActive: false
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false
});

const grantAccessForm = useForm<{
    project_id: number | null;
    project_name: string;
    email: string;
    name: string;
    isOpen: boolean;
}>({
    project_id: null,
    project_name: '',
    email: '',
    name: '',
    isOpen: false,
});

function saveEdit() {
    if (editForm.id) {
        editForm.put(`/projects/${editForm.id}`, {
            onSuccess: () => {
                editDialogOpen.value = false;
            },
            preserveScroll: true,
        });
    }
}

function confirmDelete() {
    if (deleteForm.id) {
        router.delete(`/projects/${deleteForm.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteForm.isActive = false;
            },
        });
    }
}

function grantAccess() {
    if (grantAccessForm.project_id) {
        grantAccessForm.post(`/projects/${grantAccessForm.project_id}/users`, {
            onSuccess: () => {
                grantAccessForm.isOpen = false;
                grantAccessForm.reset();
                grantAccessForm.isOpen = false; // Set it again after reset to ensure it's false
            },
            preserveScroll: true,
        });
    }
}

function onPageChange(page: number) {
    router.get('/projects', { page, search: search.value }, { preserveState: true, preserveScroll: true });
}

function reset() {
    search.value = '';
    router.get('/projects', { search: '' }, { preserveState: true, preserveScroll: true });
}

watch(search, value => debounce(() => {
    router.get('/projects', { search: value }, { preserveState: true, preserveScroll: true, replace: true });
}, 300)());
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">

            <div class="flex gap-4">
                <Input type="text" placeholder="Search..." class="mb-4 p-2 border rounded" v-model="search" />

                <Button @click="reset">Reset</Button>

                <Button @click="createForm.isActive = true">
                    <i class="fas fa-plus"></i> Add Project
                </Button>
            </div>

            <o-table :data="projects.data" :paginated="true" :per-page="projects.per_page" :current-page="projects.current_page"
                     backend-pagination :total="projects.total"
                     @page-change="onPageChange">
                <o-table-column v-slot="{row}">
                    {{ row.name }}
                </o-table-column>
                <o-table-column v-slot="{ row }">
                    <div class="flex gap-2 justify-end">
                        <Button variant="outline" @click="() => {
                            console.log('Button clicked for project:', row);
                            grantAccessForm.project_id = row.id;
                            grantAccessForm.project_name = row.name;
                            grantAccessForm.isOpen = true;
                            console.log('grantAccessForm.isOpen set to:', grantAccessForm.isOpen);
                        }">
                            <i class="fas fa-key"></i>
                        </Button>
                        <Button variant="outline" @click="openEditModal(row)">
                            <i class="fas fa-edit"></i>
                        </Button>
                        <Button variant="destructive" @click="openDeleteModal(row)">
                            <i class="fas fa-trash"></i>
                        </Button>
                    </div>
                </o-table-column>

                <template #empty>
                    <div class="flex h-full items-center justify-center">
                        <p class="text-gray-500">No projects found.</p>
                    </div>
                </template>
            </o-table>
        </div>

        <DeleteDialog @cancel="deleteForm.isActive = false" @confirm="confirmDelete" :is-open="deleteForm.isActive">
            <template #title>
                Delete Project
            </template>
            <template #description>
                Are you sure you want to delete this project? This action cannot be undone.
            </template>
            <template #cancel>
                Cancel
            </template>
            <template #confirm>
                Confirm
            </template>
        </DeleteDialog>

        <!-- Create Modal -->
        <AlertDialog v-model:open="createForm.isActive">
            <AlertDialogTrigger as-child>
                <!-- Hidden trigger (manual open via v-model) -->
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Create Project</AlertDialogTitle>
                    <AlertDialogDescription>
                        Add a new project.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4">
                    <input
                        v-model="createForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Project Name"
                    />

                    <select v-model="createForm.client_id" class="border rounded px-4 py-2">
                        <option value="" disabled>Select Client</option>
                        <option v-for="client in clients.data" :key="client.id" :value="client.id">
                            {{ client.name }}
                        </option>
                    </select>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="createForm.isActive = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="createForm.post('/projects')" :disabled="createForm.processing">Create</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <!-- Edit Modal -->
        <AlertDialog v-model:open="editDialogOpen">
            <AlertDialogTrigger as-child>
                <!-- Hidden trigger (manual open via v-model) -->
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Edit Project</AlertDialogTitle>
                    <AlertDialogDescription>
                        Update project information.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4">
                    <input
                        v-model="editForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Project Name"
                    />
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="editDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="saveEdit" :disabled="editForm.processing">Save</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <!-- Grant Access Modal -->
        <AlertDialog :open="grantAccessForm.isOpen" @update:open="grantAccessForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <!-- Hidden trigger (manual open via v-model) -->
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Grant Project Access</AlertDialogTitle>
                    <AlertDialogDescription>
                        Grant a user access to {{ grantAccessForm.project_name }}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4">
                    <input
                        v-model="grantAccessForm.email"
                        type="email"
                        class="border rounded px-4 py-2"
                        placeholder="User Email"
                    />
                    <input
                        v-model="grantAccessForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="User Name"
                    />
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="grantAccessForm.isOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="grantAccess" :disabled="grantAccessForm.processing">Grant Access</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
