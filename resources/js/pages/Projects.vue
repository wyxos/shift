<script setup lang="ts">
/* eslint-disable max-lines */
import DeleteDialog from '@/components/DeleteDialog.vue';
import AdminListShell from '@/components/admin/AdminListShell.vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { FolderKanban, KeyRound, Pencil, Plus, Search, Shield, Trash2, UserPlus, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type ProjectRow = {
    id: number;
    name: string;
    client_id?: number | null;
    organisation_id?: number | null;
    client_name?: string | null;
    organisation_name?: string | null;
    isOwner?: boolean;
    token?: string | null;
};

type ProjectPaginator = {
    data: ProjectRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Option = {
    id: number;
    name: string;
};

type ProjectFilters = {
    search?: string | null;
    sort_by?: string | null;
};

type ProjectAccessUser = {
    id: number;
    user_name?: string | null;
    user_email?: string | null;
    registration_status?: string | null;
};

type SortBy = 'newest' | 'oldest' | 'name';

const props = withDefaults(
    defineProps<{
        projects: ProjectPaginator;
        clients?: Option[];
        organisations?: Option[];
        filters?: ProjectFilters;
    }>(),
    {
        clients: () => [],
        organisations: () => [],
        filters: () => ({}),
    },
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Projects',
        href: '/projects',
    },
];

const defaultSortBy: SortBy = 'newest';
const sortOptions = [
    { value: 'newest', label: 'Newest' },
    { value: 'oldest', label: 'Oldest' },
    { value: 'name', label: 'Name' },
] satisfies { value: SortBy; label: string }[];

function normalizeSortBy(value: string | null | undefined): SortBy {
    if (value === 'oldest' || value === 'name') {
        return value;
    }

    return defaultSortBy;
}

function normalizeNullableId(value: number | string | null | undefined) {
    if (value === null || value === undefined || value === '' || value === 'null') {
        return null;
    }

    return Number(value);
}

const filtersOpen = ref(false);
const editDialogOpen = ref(false);
const manageUsersLoading = ref(false);
const manageUsersError = ref<string | null>(null);
const apiTokenLoading = ref(false);
const apiTokenError = ref<string | null>(null);
const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);

watch(
    () => props.filters,
    (next) => {
        appliedSearchTerm.value = typeof next?.search === 'string' ? next.search : '';
        appliedSortBy.value = normalizeSortBy(next?.sort_by);
        draftSearchTerm.value = appliedSearchTerm.value;
        draftSortBy.value = appliedSortBy.value;
    },
    { deep: true },
);

watch(filtersOpen, (open) => {
    if (!open) return;

    draftSearchTerm.value = appliedSearchTerm.value;
    draftSortBy.value = appliedSortBy.value;
});

const projectRows = computed(() => props.projects.data ?? []);
const activeFilterCount = computed(() => {
    let count = 0;

    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;

    return count;
});

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
    isActive: false,
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false,
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
    users: ProjectAccessUser[];
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

watch(
    () => createForm.client_id,
    (value) => {
        const normalized = normalizeNullableId(value);
        if (normalized !== value) {
            createForm.client_id = normalized;
        }
    },
);

watch(
    () => createForm.organisation_id,
    (value) => {
        const normalized = normalizeNullableId(value);
        if (normalized !== value) {
            createForm.organisation_id = normalized;
        }
    },
);

const otherCreateErrors = computed<Record<string, string>>(() => {
    return Object.entries(createForm.errors)
        .filter(([key]) => !['name', 'client_id', 'organisation_id'].includes(key))
        .reduce<Record<string, string>>((accumulator, [key, value]) => {
            accumulator[key] = value;
            return accumulator;
        }, {});
});

const otherEditErrors = computed<Record<string, string>>(() => {
    return Object.entries(editForm.errors)
        .filter(([key]) => key !== 'name')
        .reduce<Record<string, string>>((accumulator, [key, value]) => {
            accumulator[key] = value;
            return accumulator;
        }, {});
});

const createDisabled = computed(() => createForm.processing || !createForm.name.trim());
const editDisabled = computed(() => editForm.processing || !editForm.name.trim());
const grantAccessDisabled = computed(() => grantAccessForm.processing || !grantAccessForm.email.trim() || !grantAccessForm.name.trim());

function buildIndexParams(page = 1) {
    return {
        search: appliedSearchTerm.value.trim() || undefined,
        sort_by: appliedSortBy.value !== defaultSortBy ? appliedSortBy.value : undefined,
        page,
    };
}

function applyFilters() {
    appliedSearchTerm.value = draftSearchTerm.value.trim();
    appliedSortBy.value = draftSortBy.value;
    filtersOpen.value = false;

    router.get('/projects', buildIndexParams(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function resetFilters() {
    draftSearchTerm.value = '';
    draftSortBy.value = defaultSortBy;
    appliedSearchTerm.value = '';
    appliedSortBy.value = defaultSortBy;
    filtersOpen.value = false;

    router.get('/projects', buildIndexParams(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function onPageChange(page: number) {
    router.get('/projects', buildIndexParams(page), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function openEditModal(project: ProjectRow) {
    editForm.id = project.id;
    editForm.name = project.name;
    editDialogOpen.value = true;
}

function openDeleteModal(project: ProjectRow) {
    deleteForm.id = project.id;
    deleteForm.isActive = true;
}

function openGrantAccessModal(project: ProjectRow) {
    grantAccessForm.project_id = project.id;
    grantAccessForm.project_name = project.name;
    grantAccessForm.email = '';
    grantAccessForm.name = '';
    grantAccessForm.isOpen = true;
}

async function openManageUsersModal(project: ProjectRow) {
    manageUsersForm.project_id = project.id;
    manageUsersForm.project_name = project.name;
    manageUsersForm.users = [];
    manageUsersForm.isOpen = true;
    manageUsersLoading.value = true;
    manageUsersError.value = null;

    try {
        const response = await fetch(`/projects/${project.id}/users`);

        if (!response.ok) {
            throw new Error(`Failed to load users for project ${project.id}`);
        }

        manageUsersForm.users = await response.json();
    } catch (error) {
        console.error('Error fetching project users:', error);
        manageUsersError.value = 'Unable to load project access right now.';
    } finally {
        manageUsersLoading.value = false;
    }
}

function openApiTokenModal(project: ProjectRow) {
    apiTokenForm.project_id = project.id;
    apiTokenForm.project_name = project.name;
    apiTokenForm.token = project.token ?? '';
    apiTokenError.value = null;
    apiTokenForm.isOpen = true;
}

function closeCreateModal() {
    createForm.isActive = false;
    createForm.reset();
}

function closeEditModal() {
    editDialogOpen.value = false;
    editForm.reset();
    editForm.id = null;
}

function submitCreateForm() {
    createForm.post('/projects', {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
        },
        onError: () => {
            createForm.isActive = true;
        },
    });
}

function saveEdit() {
    if (!editForm.id) return;

    editForm.put(`/projects/${editForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeEditModal();
        },
    });
}

function confirmDelete() {
    if (!deleteForm.id) return;

    router.delete(`/projects/${deleteForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteForm.isActive = false;
        },
    });
}

function grantAccess() {
    if (!grantAccessForm.project_id) return;

    grantAccessForm.post(`/projects/${grantAccessForm.project_id}/users`, {
        preserveScroll: true,
        onSuccess: () => {
            grantAccessForm.reset();
            grantAccessForm.isOpen = false;
        },
        onError: () => {
            grantAccessForm.isOpen = true;
        },
    });
}

function removeAccess(projectUser: ProjectAccessUser) {
    if (!manageUsersForm.project_id) return;

    router.delete(`/projects/${manageUsersForm.project_id}/users/${projectUser.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            openManageUsersModal({ id: manageUsersForm.project_id as number, name: manageUsersForm.project_name });
        },
    });
}

async function generateApiToken() {
    if (!apiTokenForm.project_id) return;

    apiTokenLoading.value = true;
    apiTokenError.value = null;

    try {
        const response = await axios.post(
            `/projects/${apiTokenForm.project_id}/api-token`,
            {},
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        apiTokenForm.token = response.data.token;
    } catch (error) {
        console.error('Error generating project token:', error);
        apiTokenError.value = 'Unable to generate a token right now.';
    } finally {
        apiTokenLoading.value = false;
    }
}

function projectScopeLabel(project: ProjectRow) {
    if (project.client_name) {
        return project.client_name;
    }

    if (project.organisation_name) {
        return project.organisation_name;
    }

    return 'Standalone project';
}

function projectScopeVariant(project: ProjectRow) {
    if (project.client_name || project.organisation_name) {
        return 'secondary';
    }

    return 'outline';
}

function accessStatusLabel(projectUser: ProjectAccessUser) {
    return projectUser.registration_status === 'registered' ? 'Registered' : 'Pending invitation';
}
</script>

<template>
    <Head title="Projects" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="Manage projects, access, and API tokens from one list."
                filter-description="Search and sort the projects list."
                filter-title="Filter projects"
                items-label="projects"
                :page="props.projects"
                title="Projects"
                @page-change="onPageChange"
            >
                <template #filters>
                    <div class="space-y-2">
                        <Label for="projects-search">Search</Label>
                        <div class="relative">
                            <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                            <Input
                                id="projects-search"
                                v-model="draftSearchTerm"
                                data-testid="filter-search"
                                placeholder="Search by project name"
                                class="pl-9"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label class="text-sm leading-none font-medium">Sort By</Label>
                        <ButtonGroup v-model="draftSortBy" :columns="3" :options="sortOptions" test-id-prefix="sort-by" />
                    </div>
                </template>

                <template #filter-actions>
                    <Button data-testid="filters-reset" variant="ghost" @click="resetFilters">Reset</Button>
                    <Button data-testid="filters-apply" @click="applyFilters">Apply</Button>
                </template>

                <template #actions>
                    <Button data-testid="open-create-project" size="sm" @click="createForm.isActive = true">
                        <Plus class="mr-2 h-4 w-4" />
                        Add Project
                    </Button>
                </template>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Project</TableHead>
                            <TableHead>Scope</TableHead>
                            <TableHead>Access</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="projectRows.length">
                            <TableRow v-for="project in projectRows" :key="project.id" :data-testid="`project-row-${project.id}`">
                                <TableCell>
                                    <div class="flex flex-col gap-1">
                                        <span class="font-medium">{{ project.name }}</span>
                                        <span class="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                            <FolderKanban class="h-3.5 w-3.5" />
                                            Project #{{ project.id }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :data-testid="`project-scope-${project.id}`" :variant="projectScopeVariant(project)" class="gap-1">
                                        <Shield class="h-3.5 w-3.5" />
                                        {{ projectScopeLabel(project) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :data-testid="`project-access-${project.id}`"
                                        :class="
                                            project.isOwner
                                                ? 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100'
                                                : 'bg-sky-100 text-sky-900 hover:bg-sky-100'
                                        "
                                        variant="secondary"
                                    >
                                        {{ project.isOwner ? 'Owner access' : 'Shared access' }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <template v-if="project.isOwner">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                :data-testid="`project-grant-${project.id}`"
                                                @click="openGrantAccessModal(project)"
                                            >
                                                <UserPlus class="h-4 w-4 sm:mr-2" />
                                                <span class="hidden sm:inline">Grant</span>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                :data-testid="`project-manage-${project.id}`"
                                                @click="openManageUsersModal(project)"
                                            >
                                                <Users class="h-4 w-4 sm:mr-2" />
                                                <span class="hidden sm:inline">Manage</span>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                :data-testid="`project-token-${project.id}`"
                                                @click="openApiTokenModal(project)"
                                            >
                                                <KeyRound class="h-4 w-4 sm:mr-2" />
                                                <span class="hidden sm:inline">Token</span>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                :data-testid="`project-edit-${project.id}`"
                                                @click="openEditModal(project)"
                                            >
                                                <Pencil class="h-4 w-4 sm:mr-2" />
                                                <span class="hidden sm:inline">Edit</span>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                size="sm"
                                                :data-testid="`project-delete-${project.id}`"
                                                @click="openDeleteModal(project)"
                                            >
                                                <Trash2 class="h-4 w-4 sm:mr-2" />
                                                <span class="hidden sm:inline">Delete</span>
                                            </Button>
                                        </template>
                                        <span v-else class="text-muted-foreground text-sm">View and collaborate only</span>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableEmpty v-else :colspan="4">No projects found.</TableEmpty>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>

        <DeleteDialog :is-open="deleteForm.isActive" @cancel="deleteForm.isActive = false" @confirm="confirmDelete">
            <template #title>Delete Project</template>
            <template #description>Are you sure you want to delete this project? This action cannot be undone.</template>
            <template #cancel>Cancel</template>
            <template #confirm>Confirm</template>
        </DeleteDialog>

        <AlertDialog v-model:open="createForm.isActive">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Create Project</AlertDialogTitle>
                    <AlertDialogDescription>Create a project and attach it to either a client or an organisation.</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label for="create-project-name">Project name</Label>
                        <Input id="create-project-name" v-model="createForm.name" data-testid="create-project-name" placeholder="Portal refresh" />
                        <p v-if="createForm.errors.name" class="text-sm text-red-500">{{ createForm.errors.name }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="create-project-client">Client</Label>
                        <select
                            id="create-project-client"
                            v-model="createForm.client_id"
                            data-testid="create-project-client"
                            :disabled="createForm.organisation_id !== null"
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <option :value="null">No client</option>
                            <option v-for="client in clients" :key="client.id" :value="client.id">
                                {{ client.name }}
                            </option>
                        </select>
                        <p v-if="createForm.errors.client_id" class="text-sm text-red-500">{{ createForm.errors.client_id }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="create-project-organisation">Organisation</Label>
                        <select
                            id="create-project-organisation"
                            v-model="createForm.organisation_id"
                            data-testid="create-project-organisation"
                            :disabled="createForm.client_id !== null"
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <option :value="null">No organisation</option>
                            <option v-for="organisation in organisations" :key="organisation.id" :value="organisation.id">
                                {{ organisation.name }}
                            </option>
                        </select>
                        <p v-if="createForm.errors.organisation_id" class="text-sm text-red-500">{{ createForm.errors.organisation_id }}</p>
                    </div>

                    <p class="text-muted-foreground text-sm">Choose one parent or leave both empty for a standalone project.</p>
                    <p v-for="(error, key) in otherCreateErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="closeCreateModal">Cancel</AlertDialogCancel>
                    <Button type="button" :disabled="createDisabled" data-testid="create-project-submit" @click="submitCreateForm">Create</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog v-model:open="editDialogOpen">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Edit Project</AlertDialogTitle>
                    <AlertDialogDescription>Update the project name.</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label for="edit-project-name">Project name</Label>
                        <Input id="edit-project-name" v-model="editForm.name" data-testid="edit-project-name" placeholder="Portal refresh" />
                        <p v-if="editForm.errors.name" class="text-sm text-red-500">{{ editForm.errors.name }}</p>
                    </div>

                    <p v-for="(error, key) in otherEditErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="closeEditModal">Cancel</AlertDialogCancel>
                    <Button type="button" :disabled="editDisabled" data-testid="edit-project-submit" @click="saveEdit">Save</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog :open="grantAccessForm.isOpen" @update:open="grantAccessForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Grant Project Access</AlertDialogTitle>
                    <AlertDialogDescription>Grant a user access to {{ grantAccessForm.project_name }}</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label for="grant-project-email">Email</Label>
                        <Input
                            id="grant-project-email"
                            v-model="grantAccessForm.email"
                            data-testid="grant-project-email"
                            placeholder="user@example.com"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="grant-project-name">Name</Label>
                        <Input id="grant-project-name" v-model="grantAccessForm.name" data-testid="grant-project-name" placeholder="Pat Doe" />
                    </div>

                    <p v-for="(error, key) in grantAccessForm.errors" :key="key" class="text-sm text-red-500">{{ error }}</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="grantAccessForm.isOpen = false">Cancel</AlertDialogCancel>
                    <Button type="button" :disabled="grantAccessDisabled" data-testid="grant-project-submit" @click="grantAccess"
                        >Grant Access</Button
                    >
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog :open="manageUsersForm.isOpen" @update:open="manageUsersForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Manage Project Access</AlertDialogTitle>
                    <AlertDialogDescription>Users with access to {{ manageUsersForm.project_name }}</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="max-h-96 space-y-4 overflow-y-auto pr-1">
                    <p v-if="manageUsersLoading" class="text-muted-foreground text-sm">Loading project users…</p>
                    <p v-else-if="manageUsersError" class="text-sm text-red-500">{{ manageUsersError }}</p>
                    <p v-else-if="manageUsersForm.users.length === 0" class="text-muted-foreground text-sm">No users have access to this project.</p>
                    <div
                        v-else
                        v-for="projectUser in manageUsersForm.users"
                        :key="projectUser.id"
                        class="flex items-start justify-between gap-4 rounded-lg border p-3"
                    >
                        <div class="space-y-1">
                            <div class="font-medium">{{ projectUser.user_name || 'Unknown user' }}</div>
                            <div class="text-muted-foreground text-sm">{{ projectUser.user_email || 'No email' }}</div>
                            <Badge variant="secondary">
                                {{ accessStatusLabel(projectUser) }}
                            </Badge>
                        </div>
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            :data-testid="`project-remove-access-${projectUser.id}`"
                            @click="removeAccess(projectUser)"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            Remove
                        </Button>
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="manageUsersForm.isOpen = false">Close</AlertDialogCancel>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog :open="apiTokenForm.isOpen" @update:open="apiTokenForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Project API Token</AlertDialogTitle>
                    <AlertDialogDescription>Manage the API token for {{ apiTokenForm.project_name }}</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div v-if="apiTokenForm.token" class="bg-muted rounded-lg p-4" data-testid="project-token-value">
                        <p class="text-sm font-medium">Current API token</p>
                        <p class="mt-2 text-sm break-all">{{ apiTokenForm.token }}</p>
                    </div>
                    <p v-else class="text-muted-foreground text-sm">No API token has been generated for this project yet.</p>

                    <p v-if="apiTokenError" class="text-sm text-red-500">{{ apiTokenError }}</p>

                    <Button type="button" :disabled="apiTokenLoading" data-testid="generate-project-token" @click="generateApiToken">
                        <KeyRound class="mr-2 h-4 w-4" />
                        {{ apiTokenForm.token ? 'Regenerate Token' : 'Generate Token' }}
                    </Button>

                    <p class="text-muted-foreground text-sm">Regenerating a token invalidates any existing integrations using the previous token.</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="apiTokenForm.isOpen = false">Close</AlertDialogCancel>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
