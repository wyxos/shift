import { type AccessUserCandidate } from '@/components/admin/access-users';
import {
    defaultSortBy,
    normalizeNullableId,
    normalizeSortBy,
    type Option,
    type ProjectAccessUser,
    type ProjectEnvironmentRow,
    type ProjectFilters,
    type ProjectPaginator,
    type ProjectRow,
    type SortBy,
} from '@/components/admin/projects/project-shared';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

type ProjectsPageStateProps = {
    projects: ProjectPaginator;
    accessUsers: AccessUserCandidate[];
    clients: Option[];
    organisations: Option[];
    filters: ProjectFilters;
};

type WidgetEnvironmentSettings = Pick<
    ProjectEnvironmentRow,
    'id' | 'key' | 'label' | 'url' | 'external_widget_enabled' | 'external_widget_guest_submissions_enabled'
>;

function omitErrors(errors: Record<string, string>, keys: string[]) {
    return Object.fromEntries(Object.entries(errors).filter(([key]) => !keys.includes(key))) as Record<string, string>;
}

export function useProjectsPageState(props: ProjectsPageStateProps) {
    const page = usePage<SharedData>();
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: '/projects' }];
    const filtersOpen = ref(false);
    const editDialogOpen = ref(false);
    const deleteProcessing = ref(false);
    const deleteError = ref<string | null>(null);
    const manageUsersLoading = ref(false);
    const manageUsersError = ref<string | null>(null);
    const removingAccessId = ref<number | null>(null);
    const apiTokenLoading = ref(false);
    const apiTokenError = ref<string | null>(null);
    const widgetSettingsOpen = ref(false);
    const widgetSettingsLoading = ref(false);
    const widgetSettingsError = ref<string | null>(null);
    const mcpSettingsOpen = ref(false);
    const mcpSettingsLoading = ref(false);
    const mcpSettingsError = ref<string | null>(null);
    const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
    const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
    const appliedOrganisationId = computed(() => props.filters.organisation_id ?? null);
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

    const editForm = useForm<{ id: number | null; name: string }>({ id: null, name: '' });
    const createForm = useForm<{
        name: string;
        client_id: number | null;
        organisation_id: number | null;
        isActive: boolean;
    }>({ name: '', client_id: null, organisation_id: null, isActive: false });
    const deleteForm = useForm<{ id: number | null; isActive: boolean }>({ id: null, isActive: false });
    const grantAccessForm = useForm<{
        project_id: number | null;
        project_name: string;
        email: string;
        name: string;
        isOpen: boolean;
    }>({ project_id: null, project_name: '', email: '', name: '', isOpen: false });
    const manageUsersForm = useForm<{
        project_id: number | null;
        project_name: string;
        users: ProjectAccessUser[];
        isOpen: boolean;
    }>({ project_id: null, project_name: '', users: [], isOpen: false });
    const apiTokenForm = useForm<{
        project_id: number | null;
        project_name: string;
        token: string;
        isOpen: boolean;
    }>({ project_id: null, project_name: '', token: '', isOpen: false });
    const widgetSettingsForm = ref({
        project_id: null as number | null,
        project_name: '',
        external_widget_enabled: false,
        external_widget_guest_submissions_enabled: false,
        environments: [] as WidgetEnvironmentSettings[],
    });
    const mcpSettingsForm = ref({
        project_id: null as number | null,
        project_name: '',
        mcp_enabled: false,
    });

    watch(
        () => createForm.client_id,
        (value) => {
            const normalized = normalizeNullableId(value);
            if (normalized !== value) createForm.client_id = normalized;
        },
    );
    watch(
        () => createForm.organisation_id,
        (value) => {
            const normalized = normalizeNullableId(value);
            if (normalized !== value) createForm.organisation_id = normalized;
        },
    );

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
        router.get(indexPath.value, buildIndexParams(), { preserveScroll: true, preserveState: true, replace: true });
    }

    function resetFilters() {
        draftSearchTerm.value = '';
        draftSortBy.value = defaultSortBy;
        appliedSearchTerm.value = '';
        appliedSortBy.value = defaultSortBy;
        filtersOpen.value = false;
        router.get(indexPath.value, buildIndexParams(), { preserveScroll: true, preserveState: true, replace: true });
    }

    function onPageChange(page: number) {
        router.get(indexPath.value, buildIndexParams(page), { preserveScroll: true, preserveState: true, replace: true });
    }

    function openEditModal(project: ProjectRow) {
        editForm.id = project.id;
        editForm.name = project.name;
        editDialogOpen.value = true;
    }

    function openDeleteModal(project: ProjectRow) {
        deleteProcessing.value = false;
        deleteError.value = null;
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
            if (!response.ok) throw new Error(`Failed to load users for project ${project.id}`);
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

    function openWidgetSettingsModal(project: ProjectRow) {
        widgetSettingsForm.value = {
            project_id: project.id,
            project_name: project.name,
            external_widget_enabled: Boolean(project.external_widget_enabled),
            external_widget_guest_submissions_enabled: Boolean(project.external_widget_guest_submissions_enabled),
            environments: (project.environments ?? []).map((environment) => ({
                id: environment.id,
                key: environment.key,
                label: environment.label,
                url: environment.url,
                external_widget_enabled: Boolean(environment.external_widget_enabled),
                external_widget_guest_submissions_enabled: Boolean(environment.external_widget_guest_submissions_enabled),
            })),
        };
        widgetSettingsError.value = null;
        widgetSettingsOpen.value = true;
    }

    function openMcpSettingsModal(project: ProjectRow) {
        mcpSettingsForm.value = {
            project_id: project.id,
            project_name: project.name,
            mcp_enabled: Boolean(project.mcp_enabled),
        };
        mcpSettingsError.value = null;
        mcpSettingsOpen.value = true;
    }

    function openProjectTasks(project: ProjectRow) {
        const path = isScopedOrganisationRoute.value ? `/organisation/${appliedOrganisationId.value}/tasks` : '/tasks';
        router.get(path, { project_id: project.id });
    }

    function openProjectExternalUsers(project: ProjectRow) {
        const path = isScopedOrganisationRoute.value ? `/organisation/${appliedOrganisationId.value}/external-users` : '/external-users';
        router.get(path, { project_id: project.id });
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

    function closeWidgetSettingsModal() {
        widgetSettingsOpen.value = false;
        widgetSettingsError.value = null;
    }

    function closeMcpSettingsModal() {
        mcpSettingsOpen.value = false;
        mcpSettingsError.value = null;
    }

    function submitCreateForm() {
        createForm.post('/projects', {
            preserveScroll: true,
            onSuccess: () => closeCreateModal(),
            onError: () => {
                createForm.isActive = true;
            },
        });
    }

    function saveEdit() {
        if (!editForm.id) return;
        editForm.put(`/projects/${editForm.id}`, { preserveScroll: true, onSuccess: () => closeEditModal() });
    }

    function confirmDelete() {
        if (!deleteForm.id || deleteProcessing.value) return;
        deleteProcessing.value = true;
        deleteError.value = null;
        router.delete(`/projects/${deleteForm.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                deleteForm.isActive = false;
            },
            onError: (errors) => {
                deleteError.value = String(Object.values(errors)[0] ?? 'Unable to delete this project right now.');
            },
            onFinish: () => {
                if (deleteForm.isActive) deleteProcessing.value = false;
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
        if (!manageUsersForm.project_id || removingAccessId.value !== null) return;

        removingAccessId.value = projectUser.id;
        router.delete(`/projects/${manageUsersForm.project_id}/users/${projectUser.id}`, {
            preserveScroll: true,
            onSuccess: () => openManageUsersModal({ id: manageUsersForm.project_id as number, name: manageUsersForm.project_name }),
            onFinish: () => {
                removingAccessId.value = null;
            },
        });
    }

    async function generateApiToken() {
        if (!apiTokenForm.project_id) return;
        apiTokenLoading.value = true;
        apiTokenError.value = null;

        try {
            const response = await axios.post(`/projects/${apiTokenForm.project_id}/api-token`, {}, { headers: { Accept: 'application/json' } });
            apiTokenForm.token = response.data.token;
        } catch (error) {
            console.error('Error generating project token:', error);
            apiTokenError.value = 'Unable to generate a token right now.';
        } finally {
            apiTokenLoading.value = false;
        }
    }

    async function saveWidgetSettings() {
        if (!widgetSettingsForm.value.project_id) return;
        widgetSettingsLoading.value = true;
        widgetSettingsError.value = null;

        try {
            await axios.patch(
                `/projects/${widgetSettingsForm.value.project_id}/widget-settings`,
                {
                    external_widget_enabled: widgetSettingsForm.value.external_widget_enabled,
                    external_widget_guest_submissions_enabled: widgetSettingsForm.value.external_widget_guest_submissions_enabled,
                    environments: widgetSettingsForm.value.environments.map((environment) => ({
                        id: environment.id,
                        external_widget_enabled: Boolean(environment.external_widget_enabled),
                        external_widget_guest_submissions_enabled: Boolean(environment.external_widget_guest_submissions_enabled),
                    })),
                },
                { headers: { Accept: 'application/json' } },
            );
            closeWidgetSettingsModal();
            router.reload({ only: ['projects'], preserveScroll: true });
        } catch (error) {
            console.error('Error saving widget settings:', error);
            widgetSettingsError.value = 'Unable to save widget settings right now.';
        } finally {
            widgetSettingsLoading.value = false;
        }
    }

    async function saveMcpSettings() {
        if (!mcpSettingsForm.value.project_id) return;
        mcpSettingsLoading.value = true;
        mcpSettingsError.value = null;

        try {
            await axios.patch(
                `/projects/${mcpSettingsForm.value.project_id}/mcp-settings`,
                {
                    mcp_enabled: mcpSettingsForm.value.mcp_enabled,
                },
                { headers: { Accept: 'application/json' } },
            );
            closeMcpSettingsModal();
            router.reload({ only: ['projects'], preserveScroll: true });
        } catch (error) {
            console.error('Error saving MCP settings:', error);
            mcpSettingsError.value = 'Unable to save MCP settings right now.';
        } finally {
            mcpSettingsLoading.value = false;
        }
    }

    return {
        activeFilterCount,
        apiTokenError,
        apiTokenForm,
        apiTokenLoading,
        applyFilters,
        breadcrumbs,
        closeCreateModal,
        closeEditModal,
        closeMcpSettingsModal,
        closeWidgetSettingsModal,
        confirmDelete,
        createDisabled,
        createForm,
        deleteError,
        deleteForm,
        deleteProcessing,
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
        removingAccessId,
        mcpSettingsError,
        mcpSettingsForm,
        mcpSettingsLoading,
        mcpSettingsOpen,
        onPageChange,
        openApiTokenModal,
        openDeleteModal,
        openEditModal,
        openManageUsersModal,
        openMcpSettingsModal,
        openProjectExternalUsers,
        openProjectTasks,
        openWidgetSettingsModal,
        otherCreateErrors,
        otherEditErrors,
        projectRows,
        removeAccess,
        resetFilters,
        saveEdit,
        saveMcpSettings,
        saveWidgetSettings,
        submitCreateForm,
        widgetSettingsError,
        widgetSettingsForm,
        widgetSettingsLoading,
        widgetSettingsOpen,
    };
}
