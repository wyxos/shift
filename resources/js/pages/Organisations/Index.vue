<script setup lang="ts">
import DeleteDialog from '@/components/DeleteDialog.vue';
import AdminListShell from '@/components/admin/AdminListShell.vue';
import OrganisationCreateDialog from '@/components/admin/organisations/OrganisationCreateDialog.vue';
import OrganisationEditDialog from '@/components/admin/organisations/OrganisationEditDialog.vue';
import OrganisationInviteDialog from '@/components/admin/organisations/OrganisationInviteDialog.vue';
import OrganisationListTable from '@/components/admin/organisations/OrganisationListTable.vue';
import OrganisationManageUsersDialog from '@/components/admin/organisations/OrganisationManageUsersDialog.vue';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    organisations: OrganisationPaginator;
    filters: {
        search?: string | null;
        sort_by?: string | null;
    };
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
const inviteDialogOpen = ref(false);

const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);

const organisationRows = computed(() => props.organisations.data ?? []);
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

function openEditModal(organisation: OrganisationRow) {
    editForm.id = organisation.id;
    editForm.name = organisation.name;
    editDialogOpen.value = true;
}

function openDeleteModal(organisation: OrganisationRow) {
    deleteForm.id = organisation.id;
    deleteForm.isActive = true;
}

function openInviteModal(organisation: OrganisationRow) {
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
    isActive: false,
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false,
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

const manageUsersForm = useForm<{
    organisation_id: number | null;
    organisation_name: string;
    users: Array<{ id: number; user_name: string; user_email: string }>;
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
            createForm.isActive = true;
        },
    });
}

function saveEdit() {
    if (!editForm.id) return;

    editForm.put(`/organisations/${editForm.id}`, {
        onSuccess: () => {
            editDialogOpen.value = false;
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

function inviteUser() {
    if (!inviteForm.organisation_id) return;

    inviteForm.post(`/organisations/${inviteForm.organisation_id}/users`, {
        onSuccess: () => {
            inviteDialogOpen.value = false;
            inviteForm.reset();
        },
        onError: () => {
            inviteDialogOpen.value = true;
        },
        preserveScroll: true,
    });
}

async function openManageUsersModal(organisation: OrganisationRow) {
    manageUsersForm.organisation_id = organisation.id;
    manageUsersForm.organisation_name = organisation.name;

    try {
        const response = await fetch(`/organisations/${organisation.id}/users`);
        manageUsersForm.users = await response.json();
        manageUsersForm.isOpen = true;
    } catch (error) {
        console.error('Error fetching users:', error);
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



</script>

<template>
    <Head title="Organisations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
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
                    @open-invite="openInviteModal"
                    @open-manage-users="openManageUsersModal"
                />
            </AdminListShell>
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

        <OrganisationInviteDialog
            :form="inviteForm"
            :open="inviteDialogOpen"
            @cancel="inviteDialogOpen = false"
            @submit="inviteUser"
            @update:open="inviteDialogOpen = $event"
        />

        <OrganisationManageUsersDialog
            :form="manageUsersForm"
            :open="manageUsersForm.isOpen"
            @cancel="manageUsersForm.isOpen = false"
            @remove-access="removeAccess"
            @update:open="manageUsersForm.isOpen = $event"
        />
    </AppLayout>
</template>
