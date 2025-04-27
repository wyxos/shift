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


const props = defineProps({
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
        title: 'Clients',
        href: '/clients',
    },
];

const selectedClient = ref<{ id: number, name: string } | null>(null);
const editDialogOpen = ref(false);

const deleteDialogOpen = ref(false);

const search = ref(props.filters.search);

const title = `Clients` + (search.value ? ` - ${search.value}` : '');

function openEditModal(client: { id: number, name: string }) {
    editForm.id = client.id;
    editForm.name = client.name;
    editDialogOpen.value = true;
}
function openDeleteModal(client: { id: number, name: string }) {
    selectedClient.value = client;
    deleteDialogOpen.value = true;
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
    if (selectedClient.value) {
        router.delete(`/clients/${selectedClient.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteDialogOpen.value = false;
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

                <div class="flex flex-col gap-4 p-4">
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


        <!-- Delete Modal -->
        <AlertDialog v-model:open="deleteDialogOpen">
            <AlertDialogTrigger as-child>
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete Client</AlertDialogTitle>
                    <AlertDialogDescription>
                        Are you sure you want to delete "{{ selectedClient?.name }}"? This action cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="deleteDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="confirmDelete">Delete</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>


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

                <div class="flex flex-col gap-4 p-4">
                    <input
                        v-model="createForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Client Name"
                    />
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="createForm.isActive = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="createForm.post('/clients')" :disabled="createForm.processing">Create</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
