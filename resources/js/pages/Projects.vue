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
import ProjectWidgetSettingsDialog from '@/components/admin/projects/ProjectWidgetSettingsDialog.vue';
import { type Option, type ProjectFilters, type ProjectPaginator } from '@/components/admin/projects/project-shared';
import { Button } from '@/components/ui/button';
import { useProjectsPageState } from '@/composables/useProjectsPageState';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

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
const {
    activeFilterCount,
    apiTokenError,
    apiTokenForm,
    apiTokenLoading,
    applyFilters,
    breadcrumbs,
    closeCreateModal,
    closeEditModal,
    closeWidgetSettingsModal,
    confirmDelete,
    createDisabled,
    createForm,
    deleteForm,
    draftSearchTerm,
    draftSortBy,
    editDialogOpen,
    editDisabled,
    editForm,
    filtersOpen,
    generateApiToken,
    grantAccess,
    grantAccessDisabled,
    grantAccessForm,
    isOrganisationScoped,
    manageUsersError,
    manageUsersForm,
    manageUsersLoading,
    onPageChange,
    openApiTokenModal,
    openDeleteModal,
    openEditModal,
    openManageUsersModal,
    openProjectExternalUsers,
    openProjectTasks,
    openWidgetSettingsModal,
    otherCreateErrors,
    otherEditErrors,
    projectRows,
    removeAccess,
    resetFilters,
    saveEdit,
    saveWidgetSettings,
    submitCreateForm,
    widgetSettingsError,
    widgetSettingsForm,
    widgetSettingsLoading,
    widgetSettingsOpen,
} = useProjectsPageState(props);
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
                    <Button data-testid="filters-reset" variant="destructive" @click="resetFilters">Reset</Button>
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
                    @open-external-users="openProjectExternalUsers"
                    @open-manage-users="openManageUsersModal"
                    @open-tasks="openProjectTasks"
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
    </AppLayout>
</template>
