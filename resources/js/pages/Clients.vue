<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {OTable, OTableColumn} from '@oruga-ui/oruga-next';
import { Button } from '@/components/ui/button';
import { ref, watch, computed } from 'vue';
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
    clients: {
        type: Object,
        required: true
    },
    organisations: {
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
        title: 'Clients',
        href: '/clients',
    },
];

const editDialogOpen = ref(false);

const search = ref(props.filters.search);

const title = `Clients` + (search.value ? ` - ${search.value}` : '');

function openEditModal(client: { id: number, name: string }) {
    editForm.id = client.id;
    editForm.name = client.name;
    editDialogOpen.value = true;
}
function openDeleteModal(client: { id: number, name: string }) {
    deleteForm.id = client.id;
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
    organisation_id: number | null;
    isActive: boolean;
}>({
    name: '',
    organisation_id: null,
    isActive: false
});

// Computed property for other create form errors (not related to specific fields)
const otherCreateErrors = computed(() => {
    return Object.entries(createForm.errors)
        .filter(([key]) => !['name', 'organisation_id'].includes(key))
        .reduce((acc, [key, value]) => {
            acc[key] = value;
            return acc;
        }, {});
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false
});

function submitCreateForm() {
    createForm.post('/clients', {
        onSuccess: () => {
            createForm.isActive = false;
            createForm.reset();
        },
        onError: () => {
            // Keep the modal open when there are validation errors
            createForm.isActive = true;
        }
    });
}

function saveEdit() {
    if (editForm.id) {
        editForm.put(`/clients/${editForm.id}`, {
            onSuccess: () => {
                editDialogOpen.value = false;
            },
            preserveScroll: true,
        });
    }
}

function confirmDelete() {
    if (deleteForm.id) {
        router.delete(`/clients/${deleteForm.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteForm.isActive = false;
            },
        });
    }
}

function onPageChange(page: number) {
    router.get('/clients', { page, search: search.value }, { preserveState: true, preserveScroll: true });
}

function reset() {
    search.value = '';
    router.get('/clients', { search: '' }, { preserveState: true, preserveScroll: true });
}

watch(search, value => debounce(() => {
    router.get('/clients', { search: value }, { preserveState: true, preserveScroll: true, replace: true });
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
                    <i class="fas fa-plus"></i> Add Client
                </Button>
            </div>

            <o-table :data="clients.data" :paginated="true" :per-page="clients.per_page" :current-page="clients.current_page"
                     backend-pagination :total="clients.total"
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
                        <p class="text-gray-500">No clients found.</p>
                    </div>
                </template>
            </o-table>
        </div>

        <DeleteDialog @cancel="deleteForm.isActive = false" @confirm="confirmDelete" :is-open="deleteForm.isActive">
            <template #title>
                Delete Client
            </template>
            <template #description>
                Are you sure you want to delete this client? This action cannot be undone.
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
                    <AlertDialogTitle>Create Client</AlertDialogTitle>
                    <AlertDialogDescription>
                        Add a new client.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4">
                    <input
                        v-model="createForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Client Name"
                    />
                    <div v-if="createForm.errors.name" class="text-red-500 mt-1">{{ createForm.errors.name }}</div>

                    <select v-model="createForm.organisation_id" class="border rounded px-4 py-2">
                        <option value="" disabled>Select Organisation</option>
                        <option v-for="organisation in props.organisations" :key="organisation.id" :value="organisation.id">
                            {{ organisation.name }}
                        </option>
                    </select>
                    <div v-if="createForm.errors.organisation_id" class="text-red-500 mt-1">{{ createForm.errors.organisation_id }}</div>

                    <!-- Display any other errors -->
                    <div v-for="(error, key) in otherCreateErrors" :key="key" class="text-red-500 mt-2">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="createForm.isActive = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="submitCreateForm" :disabled="createForm.processing">Create</AlertDialogAction>
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
                    <AlertDialogTitle>Edit Client</AlertDialogTitle>
                    <AlertDialogDescription>
                        Update client information.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4">
                    <input
                        v-model="editForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Client Name"
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
