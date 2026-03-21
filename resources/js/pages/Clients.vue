<script setup lang="ts">
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
import ActionIconButton from '@/shared/components/ActionIconButton.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, Pencil, Plus, Search, Trash2, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type ClientRow = {
    id: number;
    name: string;
    organisation_id?: number | null;
    organisation_name?: string | null;
};

type ClientPaginator = {
    data: ClientRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type OrganisationOption = {
    id: number;
    name: string;
};

type ClientFilters = {
    search?: string | null;
    sort_by?: string | null;
};

type SortBy = 'newest' | 'oldest' | 'name';

const props = withDefaults(
    defineProps<{
        clients: ClientPaginator;
        organisations?: OrganisationOption[];
        filters?: ClientFilters;
    }>(),
    {
        organisations: () => [],
        filters: () => ({}),
    },
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Clients',
        href: '/clients',
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

const clientRows = computed(() => props.clients.data ?? []);
const activeFilterCount = computed(() => {
    let count = 0;

    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;

    return count;
});

const createForm = useForm<{
    name: string;
    organisation_id: number | null;
    isActive: boolean;
}>({
    name: '',
    organisation_id: null,
    isActive: false,
});

const editForm = useForm<{
    id: number | null;
    name: string;
}>({
    id: null,
    name: '',
});

const deleteForm = useForm<{
    id: number | null;
    isActive: boolean;
}>({
    id: null,
    isActive: false,
});

const otherCreateErrors = computed<Record<string, string>>(() => {
    return Object.entries(createForm.errors)
        .filter(([key]) => !['name', 'organisation_id'].includes(key))
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

const createDisabled = computed(() => createForm.processing || !createForm.name.trim() || createForm.organisation_id === null);
const editDisabled = computed(() => editForm.processing || !editForm.name.trim());

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

    router.get('/clients', buildIndexParams(), {
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

    router.get('/clients', buildIndexParams(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function onPageChange(page: number) {
    router.get('/clients', buildIndexParams(page), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function openEditModal(client: ClientRow) {
    editForm.id = client.id;
    editForm.name = client.name;
    editDialogOpen.value = true;
}

function openDeleteModal(client: ClientRow) {
    deleteForm.id = client.id;
    deleteForm.isActive = true;
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
    createForm.post('/clients', {
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

    editForm.put(`/clients/${editForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeEditModal();
        },
    });
}

function confirmDelete() {
    if (!deleteForm.id) return;

    router.delete(`/clients/${deleteForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteForm.isActive = false;
        },
    });
}
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="Manage client records and the organisations they belong to."
                filter-description="Search and sort the clients list."
                filter-title="Filter clients"
                items-label="clients"
                :page="props.clients"
                title="Clients"
                @page-change="onPageChange"
            >
                <template #filters>
                    <div class="space-y-2">
                        <Label for="clients-search">Search</Label>
                        <div class="relative">
                            <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                            <Input
                                id="clients-search"
                                v-model="draftSearchTerm"
                                data-testid="filter-search"
                                placeholder="Search by client name"
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
                    <Button data-testid="open-create-client" size="sm" @click="createForm.isActive = true">
                        <Plus class="mr-2 h-4 w-4" />
                        Add Client
                    </Button>
                </template>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Client</TableHead>
                            <TableHead>Organisation</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="clientRows.length">
                            <TableRow v-for="client in clientRows" :key="client.id" :data-testid="`client-row-${client.id}`">
                                <TableCell>
                                    <div class="flex flex-col gap-1">
                                        <span class="font-medium">{{ client.name }}</span>
                                        <span class="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                            <Users class="h-3.5 w-3.5" />
                                            Client #{{ client.id }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :data-testid="`client-organisation-${client.id}`"
                                        :variant="client.organisation_name ? 'secondary' : 'outline'"
                                        class="gap-1"
                                    >
                                        <Building2 class="h-3.5 w-3.5" />
                                        {{ client.organisation_name || 'No organisation assigned' }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <ActionIconButton
                                            label="Edit client"
                                            title="Edit"
                                            :data-testid="`client-edit-${client.id}`"
                                            @click="openEditModal(client)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </ActionIconButton>
                                        <ActionIconButton
                                            label="Delete client"
                                            title="Delete"
                                            variant="destructive"
                                            :data-testid="`client-delete-${client.id}`"
                                            @click="openDeleteModal(client)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </ActionIconButton>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableEmpty v-else :colspan="3">No clients found.</TableEmpty>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>

        <DeleteDialog :is-open="deleteForm.isActive" @cancel="deleteForm.isActive = false" @confirm="confirmDelete">
            <template #title>Delete Client</template>
            <template #description>Are you sure you want to delete this client? This action cannot be undone.</template>
            <template #cancel>Cancel</template>
            <template #confirm>Confirm</template>
        </DeleteDialog>

        <AlertDialog v-model:open="createForm.isActive">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Create Client</AlertDialogTitle>
                    <AlertDialogDescription>Add a new client and attach it to an organisation.</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label for="create-client-name">Client name</Label>
                        <Input id="create-client-name" v-model="createForm.name" data-testid="create-client-name" placeholder="Acme Ltd" />
                        <p v-if="createForm.errors.name" class="text-sm text-red-500">{{ createForm.errors.name }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="create-client-organisation">Organisation</Label>
                        <select
                            id="create-client-organisation"
                            v-model="createForm.organisation_id"
                            data-testid="create-client-organisation"
                            class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <option :value="null">Select organisation</option>
                            <option v-for="organisation in organisations" :key="organisation.id" :value="organisation.id">
                                {{ organisation.name }}
                            </option>
                        </select>
                        <p v-if="createForm.errors.organisation_id" class="text-sm text-red-500">{{ createForm.errors.organisation_id }}</p>
                    </div>

                    <p v-for="(error, key) in otherCreateErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="closeCreateModal">Cancel</AlertDialogCancel>
                    <Button type="button" :disabled="createDisabled" data-testid="create-client-submit" @click="submitCreateForm">Create</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog v-model:open="editDialogOpen">
            <AlertDialogTrigger as-child>
                <div />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Edit Client</AlertDialogTitle>
                    <AlertDialogDescription>Update the client name.</AlertDialogDescription>
                </AlertDialogHeader>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <Label for="edit-client-name">Client name</Label>
                        <Input id="edit-client-name" v-model="editForm.name" data-testid="edit-client-name" placeholder="Acme Ltd" />
                        <p v-if="editForm.errors.name" class="text-sm text-red-500">{{ editForm.errors.name }}</p>
                    </div>

                    <p v-for="(error, key) in otherEditErrors" :key="key" class="text-sm text-red-500">{{ error }}</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel type="button" @click="closeEditModal">Cancel</AlertDialogCancel>
                    <Button type="button" :disabled="editDisabled" data-testid="edit-client-submit" @click="saveEdit">Save</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
