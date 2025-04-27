<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {OTable, OTableColumn} from '@oruga-ui/oruga-next';
import { Button } from '@/components/ui/button';
import { ref, watch } from 'vue';
import { debounce } from 'lodash';
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
    tasks: {
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
        title: 'Tasks',
        href: '/tasks',
    },
];

const editDialogOpen = ref(false);

const search = ref(props.filters.search);

const title = `Tasks` + (search.value ? ` - ${search.value}` : '');

function openEditModal(task: { id: number, name: string }) {
    editForm.id = task.id;
    editForm.name = task.name;
    editDialogOpen.value = true;
}
function openDeleteModal(task: { id: number, name: string }) {
    deleteForm.id = task.id;
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
    isActive: boolean;
}>({
    name: '',
    isActive: false
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false
});

function saveEdit() {
    if (editForm.id) {
        editForm.put(`/tasks/${editForm.id}`, {
            onSuccess: () => {
                editDialogOpen.value = false;
            },
            preserveScroll: true,
        });
    }
}

function confirmDelete() {
    if (deleteForm.id) {
        router.delete(`/tasks/${deleteForm.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteForm.isActive = false;
            },
        });
    }
}

function onPageChange(page: number) {
    router.get('/tasks', { page, search: search.value }, { preserveState: true, preserveScroll: true });
}

function reset() {
    search.value = '';
    router.get('/tasks', { search: '' }, { preserveState: true, preserveScroll: true });
}

watch(search, value => debounce(() => {
    router.get('/tasks', { search: value }, { preserveState: true, preserveScroll: true, replace: true });
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
                    <i class="fas fa-plus"></i> Add Task
                </Button>
            </div>

            <o-table :data="tasks.data" :paginated="true" :per-page="tasks.per_page" :current-page="tasks.current_page"
                     backend-pagination :total="tasks.total"
                     @page-change="onPageChange">
                <o-table-column v-slot="{row}">
                    {{ row.name }}
                </o-table-column>
                <o-table-column v-slot="{ row }">
                    <div class="flex gap-2 justify-end">
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
                        <p class="text-gray-500">No tasks found.</p>
                    </div>
                </template>
            </o-table>
        </div>

        <DeleteDialog @cancel="deleteForm.isActive = false" @confirm="confirmDelete" :is-open="deleteForm.isActive">
            <template #title>
                Delete Task
            </template>
            <template #description>
                Are you sure you want to delete this task? This action cannot be undone.
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
                    <AlertDialogTitle>Create Task</AlertDialogTitle>
                    <AlertDialogDescription>
                        Add a new task.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4">
                    <input
                        v-model="createForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Task Name"
                    />
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="createForm.isActive = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="createForm.post('/tasks')" :disabled="createForm.processing">Create</AlertDialogAction>
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
                    <AlertDialogTitle>Edit Task</AlertDialogTitle>
                    <AlertDialogDescription>
                        Update task information.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4">
                    <input
                        v-model="editForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Task Name"
                    />
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="editDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="saveEdit" :disabled="editForm.processing">Save</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
