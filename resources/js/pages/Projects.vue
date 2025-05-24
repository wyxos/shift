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
    organisation_id: number | null;
    isActive: boolean;
}>({
    name: '',
    client_id: null,
    organisation_id: null,
    isActive: false
});

// Function to submit the form
function submitCreateForm() {
    createForm.post('/projects', {
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

const manageUsersForm = useForm<{
    project_id: number | null;
    project_name: string;
    users: any[];
    isOpen: boolean;
}>({
    project_id: null,
    project_name: '',
    users: [],
    isOpen: false,
});

const apiTokenForm = useForm<{
    project_id: number | null;
    project_name: string;
    token: string;
    isOpen: boolean;
}>({
    project_id: null,
    project_name: '',
    token: '',
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

function openManageUsersModal(project: { id: number, name: string }) {
    manageUsersForm.project_id = project.id;
    manageUsersForm.project_name = project.name;

    // Fetch users with access to the project
    fetch(`/projects/${project.id}/users`)
        .then(response => response.json())
        .then(data => {
            manageUsersForm.users = data;
            manageUsersForm.isOpen = true;
        })
        .catch(error => {
            console.error('Error fetching users:', error);
        });
}

function removeAccess(projectUser: { id: number }) {
    if (manageUsersForm.project_id) {
        router.delete(`/projects/${manageUsersForm.project_id}/users/${projectUser.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                // Refresh the list of users
                openManageUsersModal({ id: manageUsersForm.project_id as number, name: manageUsersForm.project_name });
            },
        });
    }
}

function openApiTokenModal(project: { id: number, name: string, project_api_token?: string }) {
    apiTokenForm.project_id = project.id;
    apiTokenForm.project_name = project.name;
    apiTokenForm.token = project.project_api_token || '';
    apiTokenForm.isOpen = true;
}

function generateApiToken() {
    if (apiTokenForm.project_id) {
        apiTokenForm.post(`/projects/${apiTokenForm.project_id}/api-token`, {
            preserveScroll: true,
            onSuccess: (response) => {
                let newToken = '';
                if (response && response.data && response.data.token) {
                    newToken = response.data.token;
                } else if (response && response.token) {
                    newToken = response.token;
                }

                // Update the form token
                apiTokenForm.token = newToken;

                // Refresh the projects data to update the UI
                const currentPage = props.projects.current_page || 1;
                router.get('/projects', {
                    page: currentPage,
                    search: search.value
                }, {
                    preserveState: false,
                    preserveScroll: true,
                    only: ['projects']
                });
            },
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

// Handle string "null" values from select elements
watch(() => createForm.client_id, value => {
    if (value === "null") {
        createForm.client_id = null;
    }
});

watch(() => createForm.organisation_id, value => {
    if (value === "null") {
        createForm.organisation_id = null;
    }
});

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
                        <Button variant="outline" @click="openManageUsersModal(row)">
                            <i class="fas fa-users"></i>
                        </Button>
                        <Button variant="outline" @click="openApiTokenModal(row)">
                            <i class="fas fa-lock"></i>
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

                    <div class="text-sm text-gray-500 mb-2">
                        Select either a client or an organisation for this project
                    </div>

                    <select
                        v-model="createForm.client_id"
                        class="border rounded px-4 py-2 mb-2 disabled:bg-gray-200"
                        :disabled="createForm.organisation_id !== null"
                    >
                        <option :value="null">Select Client (Optional)</option>
                        <option v-for="client in clients.data" :key="client.id" :value="client.id">
                            {{ client.name }}
                        </option>
                    </select>

                    <select
                        v-model="createForm.organisation_id"
                        class="border rounded px-4 py-2 disabled:bg-gray-200"
                        :disabled="createForm.client_id !== null"
                    >
                        <option :value="null">Select Organisation (Optional)</option>
                        <option v-for="org in organisations" :key="org.id" :value="org.id">
                            {{ org.name }}
                        </option>
                    </select>
                </div>


                <!-- Display server-side validation errors -->
                <div v-for="(error, key) in createForm.errors" :key="key" class="text-red-500 mt-2 mb-2 px-4">
                    {{ error }}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="() => {
                        createForm.isActive = false;
                        createForm.reset();
                    }">Cancel</AlertDialogCancel>
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

                <!-- Display server-side validation errors -->
                <div v-for="(error, key) in editForm.errors" :key="key" class="text-red-500 mt-2 mb-2 px-4">
                    {{ error }}
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

                <!-- Display server-side validation errors -->
                <div v-for="(error, key) in grantAccessForm.errors" :key="key" class="text-red-500 mt-2 mb-2 px-4">
                    {{ error }}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="grantAccessForm.isOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="grantAccess" :disabled="grantAccessForm.processing">Grant Access</AlertDialogAction>
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
                    <AlertDialogTitle>Manage Project Access</AlertDialogTitle>
                    <AlertDialogDescription>
                        Users with access to {{ manageUsersForm.project_name }}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4 max-h-96 overflow-y-auto">
                    <div v-if="manageUsersForm.users.length === 0" class="text-center text-gray-500">
                        No users have access to this project.
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

        <!-- API Token Modal -->
        <AlertDialog :open="apiTokenForm.isOpen" @update:open="apiTokenForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <!-- Hidden trigger (manual open via v-model) -->
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Project API Token</AlertDialogTitle>
                    <AlertDialogDescription>
                        Manage API token for {{ apiTokenForm.project_name }}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex flex-col gap-4 p-4">
                    <div v-if="apiTokenForm.token" class="bg-gray-100 p-4 rounded break-all">
                        <div class="font-semibold mb-2">Current API Token:</div>
                        <div class="text-sm">{{ apiTokenForm.token }}</div>
                    </div>
                    <div v-else class="text-gray-500 italic">
                        No API token has been generated for this project yet.
                    </div>

                    <Button @click="generateApiToken" class="mt-2" :disabled="apiTokenForm.processing">
                        {{ apiTokenForm.token ? 'Regenerate Token' : 'Generate Token' }}
                    </Button>

                    <div class="text-sm text-gray-500 mt-2">
                        <p>This token will be used by the Shift SDK to authenticate with this project.</p>
                        <p class="mt-1">Regenerating the token will invalidate any existing SDK installations using the old token.</p>
                    </div>
                </div>

                <!-- Display server-side validation errors -->
                <div v-for="(error, key) in apiTokenForm.errors" :key="key" class="text-red-500 mt-2 mb-2 px-4">
                    {{ error }}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="apiTokenForm.isOpen = false">Close</AlertDialogCancel>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
