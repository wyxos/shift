<script setup lang="ts">
import DeleteDialog from '@/components/DeleteDialog.vue';
import AdminListShell from '@/components/admin/AdminListShell.vue';
import { type AccessUserCandidate } from '@/components/admin/access-users';
import ProjectApiTokenDialog from '@/components/admin/projects/ProjectApiTokenDialog.vue';
import ProjectCreateDialog from '@/components/admin/projects/ProjectCreateDialog.vue';
import ProjectEditDialog from '@/components/admin/projects/ProjectEditDialog.vue';
import ProjectFilterControls from '@/components/admin/projects/ProjectFilterControls.vue';
import ProjectListTable from '@/components/admin/projects/ProjectListTable.vue';
import ProjectManageUsersDialog from '@/components/admin/projects/ProjectManageUsersDialog.vue';
import ProjectMcpSettingsDialog from '@/components/admin/projects/ProjectMcpSettingsDialog.vue';
import ProjectWidgetSettingsDialog from '@/components/admin/projects/ProjectWidgetSettingsDialog.vue';
import {
    defaultSortBy,
    normalizeNullableId,
    normalizeSortBy,
    type Option,
    type ProjectAccessUser,
    type ProjectFilters,
    type ProjectPaginator,
    type ProjectRow,
    type SortBy,
} from '@/components/admin/projects/project-shared';
import { useProjectIntegrationDialogs } from '@/components/admin/projects/useProjectIntegrationDialogs';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        projects: ProjectPaginator;
        accessUsers?: AccessUserCandidate[];
        clients?: Option[];
        organisations?: Option[];
        filters?: ProjectFilters;
    }>(),
    {
        accessUsers: () => [],
        clients: () => [],
        organisations: () => [],
        filters: () => ({}),
    },
);
const page = usePage<SharedData>();
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
const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const appliedOrganisationId = computed(() => props.filters.organisation_id ?? null);
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);
const {
    apiTokenError,
    apiTokenForm,
    apiTokenLoading,
    closeMcpSettingsModal,
    closeWidgetSettingsModal,
    generateApiToken,
    openApiTokenModal,
    openMcpSettingsModal,
    openWidgetSettingsModal,
    saveMcpSettings,
    saveWidgetSettings,
    mcpSettingsError,
    mcpSettingsForm,
    mcpSettingsLoading,
    mcpSettingsOpen,
    widgetSettingsError,
    widgetSettingsForm,
    widgetSettingsLoading,
    widgetSettingsOpen,
} = useProjectIntegrationDialogs();

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
const isOrganisationScoped = computed(
    () => appliedOrganisationId.value !== null && appliedOrganisationId.value !== undefined && appliedOrganisationId.value !== '',
);
const isScopedOrganisationRoute = computed(() => {
    if (!isOrganisationScoped.value) return false;

    const current = new URL(page.url, 'https://shift.test');

    return current.pathname === `/organisation/${appliedOrganisationId.value}/projects`;
});
const indexPath = computed(() => (isScopedOrganisationRoute.value ? `/organisation/${appliedOrganisationId.value}/projects` : '/projects'));
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

function omitErrors(errors: Record<string, string>, keys: string[]) {
    return Object.fromEntries(Object.entries(errors).filter(([key]) => !keys.includes(key))) as Record<string, string>;
}

const otherCreateErrors = computed(() => omitErrors(createForm.errors, ['name', 'client_id', 'organisation_id']));
const otherEditErrors = computed(() => omitErrors(editForm.errors, ['name']));

const createDisabled = computed(() => createForm.processing || !createForm.name.trim());
const editDisabled = computed(() => editForm.processing || !editForm.name.trim());
const grantAccessDisabled = computed(() => grantAccessForm.processing || !grantAccessForm.email.trim() || !grantAccessForm.name.trim());

function buildIndexParams(page = 1) {
    const params: Record<string, unknown> = {
        search: appliedSearchTerm.value.trim() || undefined,
        sort_by: appliedSortBy.value !== defaultSortBy ? appliedSortBy.value : undefined,
        page,
    };

    if (!isScopedOrganisationRoute.value) {
        params.organisation_id = appliedOrganisationId.value || undefined;
    }

    return params;
}

function applyFilters() {
    appliedSearchTerm.value = draftSearchTerm.value.trim();
    appliedSortBy.value = draftSortBy.value;
    filtersOpen.value = false;

    router.get(indexPath.value, buildIndexParams(), {
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

    router.get(indexPath.value, buildIndexParams(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function onPageChange(page: number) {
    router.get(indexPath.value, buildIndexParams(page), {
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

async function openManageUsersModal(project: ProjectRow) {
    manageUsersForm.project_id = project.id;
    manageUsersForm.project_name = project.name;
    manageUsersForm.users = [];
    manageUsersForm.isOpen = true;
    grantAccessForm.project_id = project.id;
    grantAccessForm.project_name = project.name;
    grantAccessForm.email = '';
    grantAccessForm.name = '';
    grantAccessForm.clearErrors?.();
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
            const projectId = grantAccessForm.project_id;
            const projectName = grantAccessForm.project_name;

            grantAccessForm.email = '';
            grantAccessForm.name = '';
            void openManageUsersModal({ id: projectId as number, name: projectName });
        },
        onError: () => {
            manageUsersForm.isOpen = true;
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
                    <ProjectFilterControls v-model:search-term="draftSearchTerm" v-model:sort-by="draftSortBy" />
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
                    :show-organisation-column="!isOrganisationScoped"
                    @open-api-token="openApiTokenModal"
                    @open-delete="openDeleteModal"
                    @open-edit="openEditModal"
                    @open-manage-users="openManageUsersModal"
                    @open-mcp-settings="openMcpSettingsModal"
                    @open-widget-settings="openWidgetSettingsModal"
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
        <ProjectManageUsersDialog
            :access-disabled="grantAccessDisabled"
            :access-form="grantAccessForm"
            :access-users="accessUsers"
            :error="manageUsersError"
            :form="manageUsersForm"
            :loading="manageUsersLoading"
            :open="manageUsersForm.isOpen"
            @cancel="manageUsersForm.isOpen = false"
            @remove-access="removeAccess"
            @submit-access="grantAccess"
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
        <ProjectWidgetSettingsDialog
            :error="widgetSettingsError"
            :form="widgetSettingsForm"
            :loading="widgetSettingsLoading"
            :open="widgetSettingsOpen"
            @cancel="closeWidgetSettingsModal"
            @save="saveWidgetSettings"
            @update:open="widgetSettingsOpen = $event"
        />
        <ProjectMcpSettingsDialog
            :error="mcpSettingsError"
            :form="mcpSettingsForm"
            :loading="mcpSettingsLoading"
            :open="mcpSettingsOpen"
            @cancel="closeMcpSettingsModal"
            @save="saveMcpSettings"
            @update:open="mcpSettingsOpen = $event"
        />
    </AppLayout>
</template>
