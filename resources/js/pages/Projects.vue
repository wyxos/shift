<script setup lang="ts">
import DeleteDialog from '@/components/DeleteDialog.vue';
import AdminListShell from '@/components/admin/AdminListShell.vue';
import ProjectApiTokenDialog from '@/components/admin/projects/ProjectApiTokenDialog.vue';
import ProjectCreateDialog from '@/components/admin/projects/ProjectCreateDialog.vue';
import ProjectEditDialog from '@/components/admin/projects/ProjectEditDialog.vue';
import ProjectGrantAccessDialog from '@/components/admin/projects/ProjectGrantAccessDialog.vue';
import ProjectListTable from '@/components/admin/projects/ProjectListTable.vue';
import ProjectManageUsersDialog from '@/components/admin/projects/ProjectManageUsersDialog.vue';
import { type Option, type ProjectAccessUser, type ProjectFilters, type ProjectPaginator, type ProjectRow, defaultSortBy, normalizeNullableId, normalizeSortBy, sortOptions, type SortBy } from '@/components/admin/projects/project-shared';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { Plus, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

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

                <ProjectListTable
                    :projects="projectRows"
                    @open-api-token="openApiTokenModal"
                    @open-delete="openDeleteModal"
                    @open-edit="openEditModal"
                    @open-grant-access="openGrantAccessModal"
                    @open-manage-users="openManageUsersModal"
                />
            </AdminListShell>
        </div>

        <DeleteDialog :is-open="deleteForm.isActive" @cancel="deleteForm.isActive = false" @confirm="confirmDelete">
            <template #title>Delete Project</template>
            <template #description>Are you sure you want to delete this project? This action cannot be undone.</template>
            <template #cancel>Cancel</template>
            <template #confirm>Confirm</template>
        </DeleteDialog>
        <ProjectCreateDialog
            :open="createForm.isActive"
            :form="createForm"
            :clients="clients"
            :disabled="createDisabled"
            :organisations="organisations"
            :other-errors="otherCreateErrors"
            @cancel="closeCreateModal"
            @submit="submitCreateForm"
            @update:open="createForm.isActive = $event"
        />
        <ProjectEditDialog
            :open="editDialogOpen"
            :form="editForm"
            :disabled="editDisabled"
            :other-errors="otherEditErrors"
            @cancel="closeEditModal"
            @submit="saveEdit"
            @update:open="editDialogOpen = $event"
        />
        <ProjectGrantAccessDialog
            :form="grantAccessForm"
            :open="grantAccessForm.isOpen"
            :disabled="grantAccessDisabled"
            @cancel="grantAccessForm.isOpen = false"
            @submit="grantAccess"
            @update:open="grantAccessForm.isOpen = $event"
        />
        <ProjectManageUsersDialog
            :error="manageUsersError"
            :form="manageUsersForm"
            :loading="manageUsersLoading"
            :open="manageUsersForm.isOpen"
            @cancel="manageUsersForm.isOpen = false"
            @remove-access="removeAccess"
            @update:open="manageUsersForm.isOpen = $event"
        />
        <ProjectApiTokenDialog
            :error="apiTokenError"
            :form="apiTokenForm"
            :loading="apiTokenLoading"
            :open="apiTokenForm.isOpen"
            @cancel="apiTokenForm.isOpen = false"
            @generate="generateApiToken"
            @update:open="apiTokenForm.isOpen = $event"
        />
    </AppLayout>
</template>
