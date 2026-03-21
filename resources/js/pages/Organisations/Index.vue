<!-- eslint-disable max-lines -->
<script setup lang="ts">
/* eslint-disable max-lines */
import DeleteDialog from '@/components/DeleteDialog.vue';
import AdminListShell from '@/components/admin/AdminListShell.vue';
import {
    AlertDialog,
    AlertDialogAction,
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
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, FolderKanban, Pencil, Trash2, UserPlus, Users } from 'lucide-vue-next';
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

type SortBy = 'newest' | 'oldest' | 'name';

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

const otherInviteErrors = computed(() => {
    return Object.entries(inviteForm.errors)
        .filter(([key]) => !['email', 'name'].includes(key))
        .reduce<Record<string, string>>((accumulator, [key, value]) => {
            if (typeof value === 'string') {
                accumulator[key] = value;
            }
            return accumulator;
        }, {});
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

function formatDate(value?: string | null) {
    if (!value) return 'Unknown';
    return new Date(value).toLocaleDateString();
}

function usersLabel(count?: number | null) {
    const total = Number(count ?? 0);
    return `${total} user${total === 1 ? '' : 's'}`;
}

function projectsLabel(count?: number | null) {
    const total = Number(count ?? 0);
    return `${total} project${total === 1 ? '' : 's'}`;
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

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Organisation</TableHead>
                            <TableHead>Access</TableHead>
                            <TableHead>Created</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="organisationRows.length === 0" :colspan="4">No organisations found.</TableEmpty>

                        <TableRow
                            v-for="organisation in organisationRows"
                            v-else
                            :key="organisation.id"
                            :data-testid="`organisation-row-${organisation.id}`"
                        >
                            <TableCell>
                                <div class="flex items-start gap-3">
                                    <div class="bg-primary/10 text-primary flex h-9 w-9 items-center justify-center rounded-lg">
                                        <Building2 class="h-4 w-4" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ organisation.name }}</div>
                                        <div class="text-muted-foreground text-xs">Created {{ formatDate(organisation.created_at) }}</div>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-wrap gap-2">
                                    <Badge variant="secondary">{{ usersLabel(organisation.organisation_users_count) }}</Badge>
                                    <Badge variant="outline" class="gap-1">
                                        <FolderKanban class="h-3 w-3" />
                                        {{ projectsLabel(organisation.projects_count) }}
                                    </Badge>
                                </div>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{ formatDate(organisation.created_at) }}</TableCell>
                            <TableCell>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :data-testid="`organisation-invite-${organisation.id}`"
                                        title="Invite user"
                                        @click="openInviteModal(organisation)"
                                    >
                                        <UserPlus class="h-4 w-4" />
                                        <span class="sr-only">Invite user</span>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :data-testid="`organisation-manage-${organisation.id}`"
                                        title="Manage users"
                                        @click="openManageUsersModal(organisation)"
                                    >
                                        <Users class="h-4 w-4" />
                                        <span class="sr-only">Manage users</span>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :data-testid="`organisation-edit-${organisation.id}`"
                                        title="Edit organisation"
                                        @click="openEditModal(organisation)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                        <span class="sr-only">Edit organisation</span>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        :data-testid="`organisation-delete-${organisation.id}`"
                                        title="Delete organisation"
                                        @click="openDeleteModal(organisation)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                        <span class="sr-only">Delete organisation</span>
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>

        <DeleteDialog :is-open="deleteForm.isActive" @cancel="deleteForm.isActive = false" @confirm="confirmDelete">
            <template #title>Delete Organisation</template>
            <template #description>Are you sure you want to delete this organisation? This action cannot be undone.</template>
            <template #cancel>Cancel</template>
            <template #confirm>Confirm</template>
        </DeleteDialog>

        <AlertDialog v-model:open="createForm.isActive">
            <AlertDialogTrigger as-child>
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Create Organisation</AlertDialogTitle>
                    <AlertDialogDescription>Add a new organisation.</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label>Name</Label>
                        <Input v-model="createForm.name" data-testid="create-organisation-name" placeholder="Organisation name" />
                    </div>

                    <div v-for="(error, key) in createForm.errors" :key="key" class="text-destructive text-sm">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="createForm.isActive = false">Cancel</AlertDialogCancel>
                    <Button data-testid="submit-create-organisation" :disabled="createForm.processing" @click="submitCreateForm">Create</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog v-model:open="editDialogOpen">
            <AlertDialogTrigger as-child>
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Edit Organisation</AlertDialogTitle>
                    <AlertDialogDescription>Update organisation information.</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label>Name</Label>
                        <Input v-model="editForm.name" data-testid="edit-organisation-name" placeholder="Organisation name" />
                    </div>

                    <div v-for="(error, key) in editForm.errors" :key="key" class="text-destructive text-sm">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="editDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction data-testid="submit-edit-organisation" :disabled="editForm.processing" @click="saveEdit">
                        Save
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog v-model:open="inviteDialogOpen">
            <AlertDialogTrigger as-child>
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Invite User to Organisation</AlertDialogTitle>
                    <AlertDialogDescription>Invite a user to join {{ inviteForm.organisation_name }}</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label>Email</Label>
                        <Input v-model="inviteForm.email" data-testid="invite-organisation-email" type="email" placeholder="user@example.com" />
                        <div v-if="inviteForm.errors.email" class="text-destructive text-sm">{{ inviteForm.errors.email }}</div>
                    </div>

                    <div class="space-y-2">
                        <Label>Name</Label>
                        <Input v-model="inviteForm.name" data-testid="invite-organisation-name" placeholder="User name" />
                        <div v-if="inviteForm.errors.name" class="text-destructive text-sm">{{ inviteForm.errors.name }}</div>
                    </div>

                    <div v-for="(error, key) in otherInviteErrors" :key="key" class="text-destructive text-sm">
                        {{ error }}
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel @click="inviteDialogOpen = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction data-testid="submit-invite-organisation" :disabled="inviteForm.processing" @click="inviteUser">
                        Invite
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog :open="manageUsersForm.isOpen" @update:open="manageUsersForm.isOpen = $event">
            <AlertDialogTrigger as-child>
                <div></div>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Manage Organisation Access</AlertDialogTitle>
                    <AlertDialogDescription>Users with access to {{ manageUsersForm.organisation_name }}</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="flex max-h-96 flex-col gap-3 overflow-y-auto">
                    <div v-for="(error, key) in manageUsersForm.errors" :key="key" class="text-destructive text-sm">
                        {{ error }}
                    </div>

                    <div v-if="manageUsersForm.users.length === 0" class="text-muted-foreground py-6 text-center text-sm">
                        No users have access to this organisation.
                    </div>

                    <div
                        v-for="user in manageUsersForm.users"
                        v-else
                        :key="user.id"
                        class="flex items-center justify-between rounded-lg border px-3 py-3"
                    >
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ user.user_name }}</div>
                            <div class="text-muted-foreground truncate text-sm">{{ user.user_email }}</div>
                        </div>
                        <Button size="sm" variant="destructive" :data-testid="`organisation-remove-access-${user.id}`" @click="removeAccess(user)">
                            <Trash2 class="mr-2 h-4 w-4" />
                            Remove
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
