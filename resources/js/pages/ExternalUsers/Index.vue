<script lang="ts" setup>
import AdminListShell from '@/components/admin/AdminListShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Input } from '@/components/ui/input';
import { Select, type SelectOption } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Pencil } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type ExternalUserRow = {
    id: number;
    name: string;
    email?: string | null;
    environment?: string | null;
    role?: string | null;
    role_label?: string | null;
    project?: {
        id: number;
        name: string;
    } | null;
};

type ExternalUsersPage = {
    data: ExternalUserRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Filters = {
    search?: string | null;
    sort_by?: string | null;
    project_id?: number | string | null;
    organisation_id?: number | string | null;
};

type ProjectOption = {
    id: number;
    name: string;
};

type SortBy = 'newest' | 'oldest' | 'name';
type EditErrors = Partial<Record<'name' | 'email' | 'project_id', string>>;

const props = defineProps<{
    externalUsers: ExternalUsersPage;
    filters: Filters;
    projects?: ProjectOption[];
}>();

const defaultSortBy: SortBy = 'newest';
const filtersOpen = ref(false);
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

function normalizeProjectId(value: number | string | null | undefined) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return String(value);
}

const appliedSearchTerm = ref(typeof props.filters.search === 'string' ? props.filters.search : '');
const appliedProjectId = ref(normalizeProjectId(props.filters.project_id));
const appliedSortBy = ref<SortBy>(normalizeSortBy(props.filters.sort_by));
const draftSearchTerm = ref(appliedSearchTerm.value);
const draftProjectId = ref(appliedProjectId.value);
const draftSortBy = ref<SortBy>(appliedSortBy.value);
const editingExternalUser = ref<ExternalUserRow | null>(null);
const editSaving = ref(false);
const editError = ref<string | null>(null);
const editErrors = ref<EditErrors>({});
const editForm = ref({
    name: '',
    email: '',
    project_id: '',
});
const indexPath = computed(() =>
    props.filters.organisation_id ? `/organisation/${props.filters.organisation_id}/external-users` : '/external-users',
);
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: 'External Users',
        href: indexPath.value,
    },
]);
const projectFilterOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'All projects' },
    ...(props.projects ?? []).map((project) => ({ value: String(project.id), label: project.name })),
]);
const editProjectOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'No project' },
    ...(props.projects ?? []).map((project) => ({ value: String(project.id), label: project.name })),
]);
const editSheetOpen = computed(() => Boolean(editingExternalUser.value));
const editDisabled = computed(() => editSaving.value || !editForm.value.name.trim());

watch(
    () => props.filters,
    (next) => {
        appliedSearchTerm.value = typeof next.search === 'string' ? next.search : '';
        appliedProjectId.value = normalizeProjectId(next.project_id);
        appliedSortBy.value = normalizeSortBy(next.sort_by);
        draftSearchTerm.value = appliedSearchTerm.value;
        draftProjectId.value = appliedProjectId.value;
        draftSortBy.value = appliedSortBy.value;
    },
    { deep: true },
);

const activeFilterCount = computed(() => {
    let count = 0;

    if (appliedSearchTerm.value.trim()) count += 1;
    if (appliedProjectId.value) count += 1;
    if (appliedSortBy.value !== defaultSortBy) count += 1;

    return count;
});

function queryParams(page = 1) {
    return {
        search: appliedSearchTerm.value.trim() || undefined,
        project_id: appliedProjectId.value || undefined,
        sort_by: appliedSortBy.value !== defaultSortBy ? appliedSortBy.value : undefined,
        page,
    };
}

function applyFilters() {
    appliedSearchTerm.value = draftSearchTerm.value;
    appliedProjectId.value = draftProjectId.value;
    appliedSortBy.value = draftSortBy.value;
    filtersOpen.value = false;

    router.get(indexPath.value, queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters() {
    draftSearchTerm.value = '';
    draftProjectId.value = '';
    draftSortBy.value = defaultSortBy;
    appliedSearchTerm.value = '';
    appliedProjectId.value = '';
    appliedSortBy.value = defaultSortBy;
    filtersOpen.value = false;

    router.get(indexPath.value, queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(page: number) {
    router.get(indexPath.value, queryParams(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function openEditSheet(externalUser: ExternalUserRow) {
    editingExternalUser.value = externalUser;
    editForm.value = {
        name: externalUser.name,
        email: externalUser.email ?? '',
        project_id: normalizeProjectId(externalUser.project?.id),
    };
    editErrors.value = {};
    editError.value = null;
}

function closeEditSheet() {
    editingExternalUser.value = null;
    editForm.value = {
        name: '',
        email: '',
        project_id: '',
    };
    editErrors.value = {};
    editError.value = null;
}

function normalizeValidationErrors(errors: Record<string, string[] | string> | undefined) {
    if (!errors) return {};

    return Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? (value[0] ?? '') : value])) as EditErrors;
}

async function saveExternalUser() {
    if (!editingExternalUser.value || editDisabled.value) return;

    editSaving.value = true;
    editErrors.value = {};
    editError.value = null;

    try {
        await axios.put(
            `/external-users/${editingExternalUser.value.id}`,
            {
                name: editForm.value.name.trim(),
                email: editForm.value.email.trim() || null,
                project_id: editForm.value.project_id ? Number(editForm.value.project_id) : null,
            },
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        closeEditSheet();
        router.reload({
            only: ['externalUsers'],
            preserveScroll: true,
        });
    } catch (error) {
        const response = (error as { response?: { status?: number; data?: { errors?: Record<string, string[] | string>; message?: string } } })
            .response;

        if (response?.status === 422) {
            editErrors.value = normalizeValidationErrors(response.data?.errors);
            editError.value = response.data?.message ?? null;
        } else {
            editError.value = 'Unable to save external user right now.';
        }
    } finally {
        editSaving.value = false;
    }
}

function environmentLabel(environment?: string | null) {
    return environment?.trim() || 'Unknown';
}

function roleLabel(externalUser: ExternalUserRow) {
    return externalUser.role_label?.trim() || 'User';
}
</script>

<template>
    <Head title="External Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <AdminListShell
                v-model:filtersOpen="filtersOpen"
                :active-filter-count="activeFilterCount"
                description="External contacts grouped by project and environment."
                filter-description="Search and sort the external users list."
                items-label="external users"
                :page="props.externalUsers"
                title="External Users"
                @page-change="goToPage"
            >
                <template #filters>
                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Search</label>
                        <Input v-model="draftSearchTerm" data-testid="filter-search" placeholder="Search by name, email, or environment" />
                    </div>

                    <div v-if="projectFilterOptions.length > 1" class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Project</label>
                        <Select
                            v-model="draftProjectId"
                            :options="projectFilterOptions"
                            placeholder="All projects"
                            search-placeholder="Search projects..."
                            empty-label="No projects found."
                            searchable
                            test-id="filter-project"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-muted-foreground text-sm leading-none font-medium">Sort By</label>
                        <ButtonGroup
                            v-model="draftSortBy"
                            aria-label="Sort external users"
                            :columns="3"
                            :options="sortOptions"
                            test-id-prefix="sort-by"
                        />
                    </div>
                </template>

                <template #filter-actions>
                    <Button data-testid="filters-reset" variant="destructive" @click="resetFilters">Reset</Button>
                    <Button data-testid="filters-apply" variant="default" @click="applyFilters">Apply</Button>
                </template>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead>Environment</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Project</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-if="props.externalUsers.data.length">
                            <TableRow
                                v-for="externalUser in props.externalUsers.data"
                                :key="externalUser.id"
                                :data-testid="`external-user-row-${externalUser.id}`"
                            >
                                <TableCell class="font-medium">{{ externalUser.name }}</TableCell>
                                <TableCell>{{ externalUser.email || 'No email' }}</TableCell>
                                <TableCell>
                                    <Badge variant="secondary" :data-testid="`external-user-environment-${externalUser.id}`">
                                        {{ environmentLabel(externalUser.environment) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline" :data-testid="`external-user-role-${externalUser.id}`">
                                        {{ roleLabel(externalUser) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge v-if="externalUser.project" class="bg-sky-100 text-sky-900 hover:bg-sky-100" variant="secondary">
                                        {{ externalUser.project.name }}
                                    </Badge>
                                    <span v-else class="text-muted-foreground text-sm">No project assigned</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex justify-end">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :data-testid="`external-user-edit-${externalUser.id}`"
                                            title="Edit external user"
                                            @click="openEditSheet(externalUser)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                            <span class="sr-only">Edit external user</span>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                        <TableEmpty v-else :colspan="6">No external users found.</TableEmpty>
                    </TableBody>
                </Table>
            </AdminListShell>
        </div>

        <Sheet :open="editSheetOpen" @update:open="(open) => !open && closeEditSheet()">
            <SheetContent class="p-0" side="right">
                <SheetHeader class="border-b px-6 py-5">
                    <SheetTitle>Edit external user</SheetTitle>
                    <SheetDescription v-if="editingExternalUser">
                        {{ editingExternalUser.name }}
                        <span v-if="editingExternalUser.email">({{ editingExternalUser.email }})</span>
                    </SheetDescription>
                </SheetHeader>

                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveExternalUser">
                    <div class="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium" for="external-user-edit-name">Name</label>
                            <Input
                                id="external-user-edit-name"
                                v-model="editForm.name"
                                data-testid="external-user-edit-name"
                                :disabled="editSaving"
                                required
                            />
                            <p v-if="editErrors.name" class="text-destructive text-sm">{{ editErrors.name }}</p>
                        </div>

                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium" for="external-user-edit-email">Email</label>
                            <Input
                                id="external-user-edit-email"
                                v-model="editForm.email"
                                data-testid="external-user-edit-email"
                                :disabled="editSaving"
                                type="email"
                            />
                            <p v-if="editErrors.email" class="text-destructive text-sm">{{ editErrors.email }}</p>
                        </div>

                        <div class="space-y-2">
                            <label class="text-muted-foreground text-sm leading-none font-medium">Project</label>
                            <Select
                                v-model="editForm.project_id"
                                :disabled="editSaving"
                                empty-label="No projects found."
                                :options="editProjectOptions"
                                placeholder="No project"
                                searchable
                                search-placeholder="Search projects..."
                                test-id="external-user-edit-project"
                            />
                            <p v-if="editErrors.project_id" class="text-destructive text-sm">{{ editErrors.project_id }}</p>
                        </div>

                        <p v-if="editError" class="text-destructive text-sm">{{ editError }}</p>
                    </div>

                    <SheetFooter class="border-t sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" :disabled="editSaving" @click="closeEditSheet">Cancel</Button>
                        <Button type="submit" :disabled="editDisabled" data-testid="external-user-edit-save">
                            {{ editSaving ? 'Saving...' : 'Save changes' }}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
