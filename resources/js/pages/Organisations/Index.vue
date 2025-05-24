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
        title: 'Organisations',
        href: '/organisations',
    },
];

const editDialogOpen = ref(false);
const inviteDialogOpen = ref(false);

const search = ref(props.filters.search);

const title = `Organisations` + (search.value ? ` - ${search.value}` : '');

function openEditModal(organisation: { id: number, name: string }) {
    editForm.id = organisation.id;
    editForm.name = organisation.name;
    editDialogOpen.value = true;
}

function openDeleteModal(organisation: { id: number, name: string }) {
    deleteForm.id = organisation.id;
    deleteForm.isActive = true;
}

function openInviteModal(organisation: { id: number, name: string }) {
    inviteForm.organisation_id = organisation.id;
    inviteForm.organisation_name = organisation.name;
    inviteDialogOpen.value = true;
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

const inviteForm = useForm<{
    organisation_id: number | null;
    organisation_name: string;
    email: string;
    name: string;
}>({
    organisation_id: null,
    organisation_name: '',
    email: '',
    name: '',
});

// Computed property for other invite form errors (not related to specific fields)
const otherInviteErrors = computed(() => {
    return Object.entries(inviteForm.errors)
        .filter(([key]) => !['email', 'name'].includes(key))
        .reduce((acc, [key, value]) => {
            acc[key] = value;
            return acc;
        }, {});
});

const manageUsersForm = useForm<{
    organisation_id: number | null;
    organisation_name: string;
    users: any[];
    isOpen: boolean;
}>({
    organisation_id: null,
    organisation_name: '',
    users: [],
    isOpen: false,
});

function submitCreateForm() {
    createForm.post('/organisations', {
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
        editForm.put(`/organisations/${editForm.id}`, {
            onSuccess: () => {
                editDialogOpen.value = false;
            },
            preserveScroll: true,
        });
    }
}

function confirmDelete() {
    if (deleteForm.id) {
        router.delete(`/organisations/${deleteForm.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteForm.isActive = false;
            },
        });
    }
}

function inviteUser() {
    if (inviteForm.organisation_id) {
        inviteForm.post(`/organisations/${inviteForm.organisation_id}/users`, {
            onSuccess: () => {
                inviteDialogOpen.value = false;
                inviteForm.reset();
            },
            onError: () => {
                // Keep the modal open when there are validation errors
                inviteDialogOpen.value = true;
            },
            preserveScroll: true,
        });
    }
}

function openManageUsersModal(organisation: { id: number, name: string }) {
    manageUsersForm.organisation_id = organisation.id;
    manageUsersForm.organisation_name = organisation.name;

    // Fetch users with access to the organisation
    fetch(`/organisations/${organisation.id}/users`)
        .then(response => response.json())
        .then(data => {
            manageUsersForm.users = data;
            manageUsersForm.isOpen = true;
        })
        .catch(error => {
            console.error('Error fetching users:', error);
        });
}

function removeAccess(organisationUser: { id: number }) {
    if (manageUsersForm.organisation_id) {
        router.delete(`/organisations/${manageUsersForm.organisation_id}/users/${organisationUser.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                // Refresh the list of users
                openManageUsersModal({ id: manageUsersForm.organisation_id as number, name: manageUsersForm.organisation_name });
            },
        });
    }
}

function onPageChange(page: number) {
    router.get('/organisations', { page, search: search.value }, { preserveState: true, preserveScroll: true });
}

function reset() {
    search.value = '';
    router.get('/organisations', { search: '' }, { preserveState: true, preserveScroll: true });
}

watch(search, value => debounce(() => {
    router.get('/organisations', { search: value }, { preserveState: true, preserveScroll: true, replace: true });
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
                    <i class="fas fa-plus"></i> Add Organisation
                </Button>
            </div>

            <o-table :data="organisations.data" :paginated="true" :per-page="organisations.per_page" :current-page="organisations.current_page"
                     backend-pagination :total="organisations.total"
                     @page-change="onPageChange">
                <o-table-column v-slot="{row}">
                    {{ row.name }}
                </o-table-column>
                <o-table-column v-slot="{ row }">
                    <div class="flex gap-2 justify-end">
                        <Button variant="outline" @click="openInviteModal(row)">
                            <i class="fas fa-user-plus"></i>
                        </Button>
                        <Button variant="outline" @click="openManageUsersModal(row)">
                            <i class="fas fa-users"></i>
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
                        <p class="text-gray-500">No organisations found.</p>
                    </div>
                </template>
            </o-table>
        </div>

        <DeleteDialog @cancel="deleteForm.isActive = false" @confirm="confirmDelete" :is-open="deleteForm.isActive">
            <template #title>
                Delete Organisation
            </template>
            <template #description>
                Are you sure you want to delete this organisation? This action cannot be undone.
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
                    <AlertDialogTitle>Create Organisation</AlertDialogTitle>
                    <AlertDialogDescription>
                        Add a new organisation.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4">
                    <input
                        v-model="createForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Organisation Name"
                    />

                    <!-- Display server-side validation errors -->
                    <div v-for="(error, key) in createForm.errors" :key="key" class="text-red-500 mt-2">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="createForm.isActive = false">Cancel</AlertDialogCancel>
                    <Button @click="submitCreateForm" :disabled="createForm.processing">Create</Button>
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
                    <AlertDialogTitle>Edit Organisation</AlertDialogTitle>
                    <AlertDialogDescription>
                        Update organisation information.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4">
                    <input
                        v-model="editForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="Organisation Name"
                    />

                    <!-- Display server-side validation errors -->
                    <div v-for="(error, key) in editForm.errors" :key="key" class="text-red-500 mt-2">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="editDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="saveEdit" :disabled="editForm.processing">Save</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <!-- Invite User Modal -->
        <AlertDialog v-model:open="inviteDialogOpen">
            <AlertDialogTrigger as-child>
                <!-- Hidden trigger (manual open via v-model) -->
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Invite User to Organisation</AlertDialogTitle>
                    <AlertDialogDescription>
                        Invite a user to join {{ inviteForm.organisation_name }}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4">
                    <input
                        v-model="inviteForm.email"
                        type="email"
                        class="border rounded px-4 py-2"
                        placeholder="User Email"
                    />
                    <div v-if="inviteForm.errors.email" class="text-red-500 mt-1">{{ inviteForm.errors.email }}</div>

                    <input
                        v-model="inviteForm.name"
                        type="text"
                        class="border rounded px-4 py-2"
                        placeholder="User Name"
                    />
                    <div v-if="inviteForm.errors.name" class="text-red-500 mt-1">{{ inviteForm.errors.name }}</div>

                    <!-- Display any other errors -->
                    <div v-for="(error, key) in otherInviteErrors" :key="key" class="text-red-500 mt-2">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="inviteDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="inviteUser" :disabled="inviteForm.processing">Invite</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <!-- Manage Users Modal -->
        <AlertDialog :open="manageUsersForm.isOpen" @update:open="manageUsersForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <!-- Hidden trigger (manual open via v-model) -->
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Manage Organisation Access</AlertDialogTitle>
                    <AlertDialogDescription>
                        Users with access to {{ manageUsersForm.organisation_name }}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4 max-h-96 overflow-y-auto">
                    <!-- Display server-side validation errors -->
                    <div v-for="(error, key) in manageUsersForm.errors" :key="key" class="text-red-500 mt-2 mb-2">
                        {{ error }}
                    </div>

                    <div v-if="manageUsersForm.users.length === 0" class="text-center text-gray-500">
                        No users have access to this organisation.
                    </div>
                    <div v-else v-for="user in manageUsersForm.users" :key="user.id" class="flex justify-between items-center p-2 border-b">
                        <div>
                            <div class="font-semibold">{{ user.user_name }}</div>
                            <div class="text-sm text-gray-500">{{ user.user_email }}</div>
                        </div>
                        <Button variant="destructive" size="sm" @click="removeAccess(user)">
                            <i class="fas fa-trash mr-1"></i> Remove
                        </Button>
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="manageUsersForm.isOpen = false">Close</AlertDialogCancel>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
