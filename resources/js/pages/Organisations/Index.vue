<script setup lang="ts">
import { type AccessUserCandidate, type ManagedAccessUser } from '@/components/admin/access-users';
import AdminListShell from '@/components/admin/AdminListShell.vue';
import OrganisationCreateDialog from '@/components/admin/organisations/OrganisationCreateDialog.vue';
import OrganisationEditDialog from '@/components/admin/organisations/OrganisationEditDialog.vue';
import OrganisationListTable from '@/components/admin/organisations/OrganisationListTable.vue';
import OrganisationManageUsersDialog from '@/components/admin/organisations/OrganisationManageUsersDialog.vue';
import DeleteDialog from '@/components/DeleteDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type OrganisationRow = {
    id: number;
    name: string;
    created_at?: string | null;
    organisation_users_count?: number | null;
    projects_count?: number | null;
};

type OrganisationPaginator = {
    data: OrganisationRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type OrganisationIdentity = Pick<OrganisationRow, 'id' | 'name'>;

type OrganisationTeamUser = {
    id: string;
    organisationUserId?: number | null;
    name: string;
    email: string;
    status: 'owner' | 'registered' | 'pending';
    statusLabel: string;
};

type PanelOrganisation = OrganisationIdentity & {
    teamUsers: OrganisationTeamUser[];
};

const props = defineProps<{
    accessUsers?: AccessUserCandidate[];
    organisations: OrganisationPaginator;
    filters: {
        search?: string | null;
        sort_by?: string | null;
    };
    panel?: {
        create?: boolean;
        team?: number | null;
        manage?: number | null;
        settings?: number | null;
    };
    panelOrganisation?: PanelOrganisation | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Organisations',
        href: '/organisations',
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

const filtersOpen = ref(false);
const editDialogOpen = ref(false);
const manageUsersLoading = ref(false);
const manageUsersError = ref<string | null>(null);

const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);

const organisationRows = computed(() => props.organisations.data ?? []);
const activeTeamPanelId = computed(() => props.panel?.team ?? props.panel?.manage ?? null);
const activePanelOrganisation = computed<PanelOrganisation | null>(() => {
    const panelOrganisationId = activeTeamPanelId.value ?? props.panel?.settings ?? null;

    if (!panelOrganisationId || props.panelOrganisation?.id !== panelOrganisationId) {
        return null;
    }

    return props.panelOrganisation;
});
const isTeamMode = computed(() => Boolean(activeTeamPanelId.value && activePanelOrganisation.value));
const isSettingsMode = computed(() => Boolean(props.panel?.settings && activePanelOrganisation.value));
const activeFilterCount = computed(() => {
    let count = 0;

    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;

    return count;
});

watch(
    () => props.filters,
    (next) => {
        appliedSearchTerm.value = typeof next.search === 'string' ? next.search : '';
        appliedSortBy.value = normalizeSortBy(next.sort_by);
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

function queryParams(page = 1) {
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

    router.get('/organisations', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters() {
    draftSearchTerm.value = '';
    draftSortBy.value = defaultSortBy;
    appliedSearchTerm.value = '';
    appliedSortBy.value = defaultSortBy;
    filtersOpen.value = false;

    router.get('/organisations', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onPageChange(page: number) {
    router.get('/organisations', queryParams(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function openEditModal(organisation: OrganisationIdentity) {
    editForm.id = organisation.id;
    editForm.name = organisation.name;
    editDialogOpen.value = true;
}

function openDeleteModal(organisation: OrganisationIdentity) {
    deleteForm.id = organisation.id;
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
    isActive: false,
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false,
});

const accessForm = useForm<{
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

const manageUsersForm = useForm<{
    organisation_id: number | null;
    organisation_name: string;
    users: ManagedAccessUser[];
    isOpen: boolean;
}>({
    organisation_id: null,
    organisation_name: '',
    users: [],
    isOpen: false,
});

const accessDisabled = computed(() => accessForm.processing || !accessForm.email.trim() || !accessForm.name.trim());
const settingsSaveDisabled = computed(() => editForm.processing || !editForm.name.trim());

function submitCreateForm() {
    createForm.post('/organisations', {
        onSuccess: () => {
            createForm.isActive = false;
            createForm.reset();
        },
        onError: () => {
            createForm.isActive = true;
        },
    });
}

function saveEdit() {
    if (!editForm.id) return;

    editForm.put(`/organisations/${editForm.id}`, {
        onSuccess: () => {
            if (!isSettingsMode.value) {
                editDialogOpen.value = false;
            }
        },
        preserveScroll: true,
    });
}

function confirmDelete() {
    if (!deleteForm.id) return;

    router.delete(`/organisations/${deleteForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteForm.isActive = false;
        },
    });
}

function addAccess() {
    if (!accessForm.organisation_id) return;

    accessForm.post(`/organisations/${accessForm.organisation_id}/users`, {
        onSuccess: () => {
            const organisationId = accessForm.organisation_id;
            const organisationName = accessForm.organisation_name;

            accessForm.email = '';
            accessForm.name = '';
            void openManageUsersModal({ id: organisationId as number, name: organisationName });
        },
        onError: () => {
            manageUsersForm.isOpen = true;
        },
        preserveScroll: true,
    });
}

async function openManageUsersModal(organisation: OrganisationIdentity) {
    manageUsersForm.organisation_id = organisation.id;
    manageUsersForm.organisation_name = organisation.name;
    manageUsersForm.users = [];
    manageUsersForm.isOpen = true;
    accessForm.organisation_id = organisation.id;
    accessForm.organisation_name = organisation.name;
    accessForm.email = '';
    accessForm.name = '';
    accessForm.clearErrors?.();
    manageUsersLoading.value = true;
    manageUsersError.value = null;

    try {
        const response = await fetch(`/organisations/${organisation.id}/users`);
        if (!response.ok) {
            throw new Error(`Failed to load users for organisation ${organisation.id}`);
        }

        manageUsersForm.users = await response.json();
    } catch (error) {
        console.error('Error fetching users:', error);
        manageUsersError.value = 'Unable to load organisation access right now.';
    } finally {
        manageUsersLoading.value = false;
    }
}

function removeAccess(organisationUser: { id: number }) {
    if (!manageUsersForm.organisation_id) return;

    router.delete(`/organisations/${manageUsersForm.organisation_id}/users/${organisationUser.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            void openManageUsersModal({
                id: manageUsersForm.organisation_id as number,
                name: manageUsersForm.organisation_name,
            });
        },
    });
}

watch(
    () => props.panel?.create,
    () => {
        if (props.panel?.create) {
            createForm.isActive = true;
        }
    },
    { immediate: true },
);

watch(
    () => (isSettingsMode.value ? activePanelOrganisation.value : null),
    (organisation) => {
        if (!organisation) return;

        editForm.id = organisation.id;
        editForm.name = organisation.name;
        editForm.clearErrors?.();
    },
    { immediate: true, deep: true },
);

function teamStatusBadgeClass(status: OrganisationTeamUser['status']) {
    if (status === 'owner') {
        return 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-200';
    }

    if (status === 'pending') {
        return 'border-transparent bg-amber-100 text-amber-900 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-200';
    }

    return '';
}
</script>

<template>
    <Head title="Organisations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-if="!isTeamMode && !isSettingsMode"
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="Manage organisation access, invitations, and ownership surfaces."
                filter-description="Search or reorder organisations."
                items-label="organisations"
                :page="props.organisations"
                title="Organisations"
                @page-change="onPageChange"
            >
                <template #filters>
                    <div class="space-y-2">
                        <Label class="text-muted-foreground">Search</Label>
                        <Input v-model="draftSearchTerm" data-testid="filter-search" placeholder="Search organisations" />
                    </div>

                    <div class="space-y-2">
                        <Label class="text-muted-foreground">Sort By</Label>
                        <ButtonGroup
                            v-model="draftSortBy"
                            test-id-prefix="sort-by"
                            :options="sortOptions"
                            :columns="3"
                            aria-label="Sort organisations"
                        />
                    </div>
                </template>

                <template #filter-actions>
                    <Button data-testid="filters-reset" variant="ghost" @click="resetFilters">Reset</Button>
                    <Button data-testid="filters-apply" variant="default" @click="applyFilters">Apply</Button>
                </template>

                <template #actions>
                    <Button data-testid="create-organisation-trigger" size="sm" @click="createForm.isActive = true">
                        <Building2 class="mr-2 h-4 w-4" />
                        Add Organisation
                    </Button>
                </template>

                <OrganisationListTable
                    :organisations="organisationRows"
                    @open-delete="openDeleteModal"
                    @open-edit="openEditModal"
                    @open-manage-users="openManageUsersModal"
                />
            </AdminListShell>

            <section v-else-if="isTeamMode && activePanelOrganisation" class="bg-card rounded-xl border p-4">
                <div class="mb-4 flex flex-col gap-1">
                    <h1 class="text-lg font-semibold">Team</h1>
                    <p class="text-muted-foreground text-sm">{{ activePanelOrganisation.name }}</p>
                </div>

                <div class="overflow-hidden rounded-lg border">
                    <div v-if="activePanelOrganisation.teamUsers.length === 0" class="text-muted-foreground p-4 text-sm">
                        No users have access to this organisation.
                    </div>
                    <div
                        v-for="teamUser in activePanelOrganisation.teamUsers"
                        v-else
                        :key="teamUser.id"
                        class="flex items-center justify-between gap-4 border-b p-3 last:border-b-0"
                        :data-testid="`organisation-team-user-${teamUser.id}`"
                    >
                        <div class="min-w-0">
                            <div class="truncate font-medium">
                                {{ teamUser.name }}
                                <span class="text-muted-foreground font-normal">({{ teamUser.email }})</span>
                            </div>
                        </div>
                        <Badge :class="teamStatusBadgeClass(teamUser.status)" variant="secondary">{{ teamUser.statusLabel }}</Badge>
                    </div>
                </div>
            </section>

            <section v-else-if="isSettingsMode && activePanelOrganisation" class="space-y-4">
                <div class="bg-card rounded-xl border p-4">
                    <div class="max-w-xl space-y-4">
                        <div class="space-y-1">
                            <h1 class="text-lg font-semibold">Settings</h1>
                            <p class="text-muted-foreground text-sm">{{ activePanelOrganisation.name }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="settings-organisation-name">Name</Label>
                            <Input
                                id="settings-organisation-name"
                                v-model="editForm.name"
                                data-testid="settings-organisation-name"
                                placeholder="Organisation name"
                            />
                        </div>

                        <div v-for="(error, key) in editForm.errors" :key="key" class="text-destructive text-sm">{{ error }}</div>

                        <Button data-testid="settings-save-organisation" :disabled="settingsSaveDisabled" @click="saveEdit"> Save changes </Button>
                    </div>
                </div>

                <div class="border-destructive/30 bg-card rounded-xl border p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <h2 class="text-destructive font-semibold">Delete organisation</h2>
                            <p class="text-muted-foreground text-sm">This will permanently remove the organisation.</p>
                        </div>
                        <Button data-testid="settings-delete-organisation" variant="destructive" @click="openDeleteModal(activePanelOrganisation)">
                            Delete organisation
                        </Button>
                    </div>
                </div>
            </section>
        </div>

        <DeleteDialog :is-open="deleteForm.isActive" @cancel="deleteForm.isActive = false" @confirm="confirmDelete">
            <template #title>Delete Organisation</template>
            <template #description>Are you sure you want to delete this organisation? This action cannot be undone.</template>
            <template #cancel>Cancel</template>
            <template #confirm>Confirm</template>
        </DeleteDialog>

        <OrganisationCreateDialog
            :form="createForm"
            :open="createForm.isActive"
            @cancel="createForm.isActive = false"
            @submit="submitCreateForm"
            @update:open="createForm.isActive = $event"
        />

        <OrganisationEditDialog
            :form="editForm"
            :open="editDialogOpen"
            @cancel="editDialogOpen = false"
            @submit="saveEdit"
            @update:open="editDialogOpen = $event"
        />

        <OrganisationManageUsersDialog
            :access-disabled="accessDisabled"
            :access-form="accessForm"
            :access-users="accessUsers ?? []"
            :error="manageUsersError"
            :form="manageUsersForm"
            :loading="manageUsersLoading"
            :open="manageUsersForm.isOpen"
            @cancel="manageUsersForm.isOpen = false"
            @remove-access="removeAccess"
            @submit-access="addAccess"
            @update:open="manageUsersForm.isOpen = $event"
        />
    </AppLayout>
</template>
